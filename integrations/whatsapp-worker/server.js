import http from 'node:http';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import QRCode from 'qrcode';
import pino from 'pino';
import { Boom } from '@hapi/boom';
import makeWASocket, {
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys';

const HOST = process.env.HOST || '127.0.0.1';
const PORT = Number(process.env.PORT || 21466);
const SECRET = String(process.env.EVENTMENU_WHATSAPP_BRIDGE_SECRET || '');
const SESSION_ROOT = path.resolve(process.env.WHATSAPP_SESSION_ROOT || path.join(process.cwd(), 'storage', 'sessions'));
const MAX_BODY = 32 * 1024;
const SESSION_RE = /^[a-f0-9]{64}$/;
const sessions = new Map();
const logger = pino({ level: 'silent' });

if (!SECRET || SECRET.length < 32) {
  console.error('EVENTMENU_WHATSAPP_BRIDGE_SECRET deve possuir ao menos 32 caracteres.');
  process.exit(1);
}
if (!Number.isInteger(PORT) || PORT < 1 || PORT > 65535) {
  console.error('PORT inválida.');
  process.exit(1);
}

fs.mkdirSync(SESSION_ROOT, { recursive: true, mode: 0o700 });
try { fs.chmodSync(SESSION_ROOT, 0o700); } catch (_) {}

function json(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
    'Cache-Control': 'no-store',
    'X-Content-Type-Options': 'nosniff',
  });
  res.end(body);
}

function authorized(req) {
  const header = String(req.headers.authorization || '');
  if (!header.startsWith('Bearer ')) return false;
  const received = Buffer.from(header.slice(7));
  const expected = Buffer.from(SECRET);
  return received.length === expected.length && crypto.timingSafeEqual(received, expected);
}

function normalizePathname(pathname) {
  if (pathname === '/health' || pathname.endsWith('/health')) return '/health';
  const marker = '/v1/sessions/';
  const index = pathname.indexOf(marker);
  return index >= 0 ? pathname.slice(index) : pathname;
}

function sessionDir(key) {
  return path.join(SESSION_ROOT, key);
}

function stateFor(key) {
  if (!sessions.has(key)) {
    sessions.set(key, {
      key,
      status: 'disconnected',
      qr: null,
      qrSeq: 0,
      pairingCode: null,
      pairingPhone: '',
      pairingMode: false,
      registered: false,
      phone: '',
      error: '',
      socket: null,
      starting: null,
      reconnectTimer: null,
      reconnectAttempt: 0,
      manualStop: false,
      updatedAt: new Date().toISOString(),
    });
  }
  return sessions.get(key);
}

function publicState(state) {
  return {
    ok: true,
    status: state.status,
    qr: state.qr,
    pairing_code: state.pairingCode,
    pairing_phone: state.pairingPhone,
    phone: state.phone,
    error: state.error || null,
    updated_at: state.updatedAt,
  };
}

function touch(state) {
  state.updatedAt = new Date().toISOString();
}

function cancelReconnect(state) {
  if (state.reconnectTimer) clearTimeout(state.reconnectTimer);
  state.reconnectTimer = null;
}

function scheduleReconnect(state) {
  if (state.manualStop || state.starting || state.reconnectTimer) return;
  const attempt = Math.min(state.reconnectAttempt + 1, 8);
  state.reconnectAttempt = attempt;
  const delay = Math.min(60000, 2000 * (2 ** (attempt - 1)));
  state.status = 'reconnecting';
  state.pairingCode = null;
  state.pairingMode = false;
  touch(state);
  state.reconnectTimer = setTimeout(() => {
    state.reconnectTimer = null;
    startSession(state.key).catch(() => {});
  }, delay);
}

function disconnectCode(lastDisconnect) {
  const error = lastDisconnect?.error;
  if (!error) return 0;
  try {
    return new Boom(error).output.statusCode || 0;
  } catch (_) {
    return Number(error?.output?.statusCode || error?.statusCode || 0);
  }
}

function phoneFromSocket(socket) {
  const raw = String(socket?.user?.id || '');
  const left = raw.split('@')[0].split(':')[0];
  const phone = left.replace(/\D+/g, '');
  return /^\d{10,15}$/.test(phone) ? phone : '';
}

function normalizePairingPhone(value) {
  let digits = String(value || '').replace(/\D+/g, '').replace(/^00/, '');
  digits = digits.replace(/^0+/, '');
  if (digits.length === 10 || digits.length === 11) digits = `55${digits}`;
  if (!/^\d{12,15}$/.test(digits)) throw new Error('Informe o número do WhatsApp com DDD. Ex.: (22) 99999-9999.');
  return digits;
}

async function setQr(state, rawQr) {
  if (state.pairingMode) return;
  const seq = ++state.qrSeq;
  try {
    const dataUrl = await QRCode.toDataURL(rawQr, {
      type: 'image/png',
      width: 360,
      margin: 3,
      errorCorrectionLevel: 'H',
      color: { dark: '#000000', light: '#FFFFFF' },
    });
    if (seq !== state.qrSeq || state.manualStop || state.pairingMode) return;
    state.qr = dataUrl;
    state.pairingCode = null;
    state.pairingPhone = '';
    state.status = 'qr';
    state.error = '';
    touch(state);
  } catch (error) {
    if (seq !== state.qrSeq) return;
    state.qr = null;
    state.status = 'error';
    state.error = String(error?.message || 'Falha ao gerar QR Code.').slice(0, 400);
    touch(state);
  }
}

async function removeSessionFiles(key) {
  await fs.promises.rm(sessionDir(key), { recursive: true, force: true });
}

async function startSession(key, mode = 'qr') {
  if (!SESSION_RE.test(key)) throw new Error('Sessão inválida.');
  const state = stateFor(key);
  state.manualStop = false;
  cancelReconnect(state);

  if (state.socket && ['connected', 'starting', 'qr', 'reconnecting'].includes(state.status)) {
    if (mode === 'qr' && state.status !== 'connected') {
      state.pairingMode = false;
      state.pairingCode = null;
      state.pairingPhone = '';
    }
    return state;
  }
  if (state.starting) return state.starting;

  state.pairingMode = mode === 'pairing';
  if (!state.pairingMode) {
    state.pairingCode = null;
    state.pairingPhone = '';
  }
  state.status = state.reconnectAttempt > 0 ? 'reconnecting' : 'starting';
  state.error = '';
  state.qr = null;
  touch(state);

  const promise = (async () => {
    const dir = sessionDir(key);
    await fs.promises.mkdir(dir, { recursive: true, mode: 0o700 });
    try { await fs.promises.chmod(dir, 0o700); } catch (_) {}

    const { state: authState, saveCreds } = await useMultiFileAuthState(dir);
    state.registered = Boolean(authState.creds.registered);
    let version;
    try {
      ({ version } = await fetchLatestBaileysVersion());
    } catch (_) {}

    const socket = makeWASocket({
      auth: {
        creds: authState.creds,
        keys: makeCacheableSignalKeyStore(authState.keys, logger),
      },
      ...(version ? { version } : {}),
      logger,
      printQRInTerminal: false,
      markOnlineOnConnect: false,
      syncFullHistory: false,
      generateHighQualityLinkPreview: false,
    });

    state.socket = socket;

    socket.ev.on('creds.update', () => {
      saveCreds().catch((error) => {
        state.error = String(error?.message || 'Falha ao salvar sessão.').slice(0, 400);
        touch(state);
      });
    });

    socket.ev.on('connection.update', (update) => {
      const { connection, qr, lastDisconnect } = update;

      if (qr) setQr(state, qr).catch(() => {});

      if (connection === 'connecting' && state.status !== 'qr') {
        state.status = state.reconnectAttempt > 0 ? 'reconnecting' : 'starting';
        touch(state);
      }

      if (connection === 'open') {
        state.status = 'connected';
        state.qr = null;
        state.qrSeq += 1;
        state.pairingCode = null;
        state.pairingPhone = '';
        state.pairingMode = false;
        state.registered = true;
        state.error = '';
        state.reconnectAttempt = 0;
        state.phone = phoneFromSocket(socket);
        touch(state);
      }

      if (connection === 'close') {
        if (state.socket === socket) state.socket = null;
        state.qr = null;
        state.qrSeq += 1;
        state.pairingCode = null;
        state.pairingPhone = '';
        state.pairingMode = false;
        const code = disconnectCode(lastDisconnect);
        const loggedOut = code === DisconnectReason.loggedOut;

        if (state.manualStop) {
          state.status = 'disconnected';
          state.error = '';
          touch(state);
          return;
        }

        if (loggedOut) {
          state.status = 'disconnected';
          state.phone = '';
          state.registered = false;
          state.error = 'Sessão encerrada pelo WhatsApp. Gere um novo QR Code ou use a conexão por código.';
          state.reconnectAttempt = 0;
          touch(state);
          removeSessionFiles(key).catch(() => {});
          return;
        }

        state.error = String(lastDisconnect?.error?.message || 'Conexão encerrada. Tentando reconectar.').slice(0, 400);
        touch(state);
        scheduleReconnect(state);
      }
    });

    return state;
  })()
    .catch((error) => {
      state.socket = null;
      state.status = state.qr || state.pairingCode ? 'qr' : 'error';
      state.error = String(error?.message || error || 'Falha ao iniciar sessão.').slice(0, 400);
      touch(state);
      throw error;
    })
    .finally(() => {
      state.starting = null;
      if (!state.manualStop && ['disconnected', 'error'].includes(state.status)) scheduleReconnect(state);
    });

  state.starting = promise;
  return promise;
}

async function requestPairingCode(key, body) {
  if (!SESSION_RE.test(key)) throw new Error('Sessão inválida.');
  const phone = normalizePairingPhone(body.phone);
  const state = stateFor(key);
  state.pairingMode = true;
  state.pairingCode = null;
  state.pairingPhone = phone;
  state.qr = null;
  state.qrSeq += 1;
  state.error = '';
  touch(state);

  await startSession(key, 'pairing');
  if (state.status === 'connected' || state.registered) {
    state.pairingMode = false;
    state.pairingPhone = '';
    throw new Error('Este WhatsApp já possui uma sessão salva. Desconecte antes de gerar um novo código.');
  }
  if (!state.socket || typeof state.socket.requestPairingCode !== 'function') {
    throw new Error('Esta versão do mecanismo local não suporta conexão por código.');
  }

  const code = String(await state.socket.requestPairingCode(phone)).trim().toUpperCase();
  if (!code) throw new Error('O WhatsApp não retornou um código de conexão. Tente novamente.');
  state.pairingCode = code;
  state.pairingPhone = phone;
  state.status = 'qr';
  state.error = '';
  touch(state);
  return state;
}

async function logoutSession(key) {
  const state = stateFor(key);
  state.manualStop = true;
  cancelReconnect(state);
  state.qrSeq += 1;

  const socket = state.socket;
  state.socket = null;
  try {
    if (socket) {
      try {
        await socket.logout();
      } catch (_) {
        try { socket.end(new Error('Logout EventMenu')); } catch (_) {}
      }
    }
  } finally {
    await removeSessionFiles(key).catch(() => {});
    state.qr = null;
    state.pairingCode = null;
    state.pairingPhone = '';
    state.pairingMode = false;
    state.registered = false;
    state.phone = '';
    state.error = '';
    state.status = 'disconnected';
    state.reconnectAttempt = 0;
    touch(state);
  }
  return state;
}

async function readBody(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY) throw new Error('Payload grande demais.');
    chunks.push(chunk);
  }
  if (!chunks.length) return {};
  const parsed = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
}

async function sendMessage(key, body) {
  const state = stateFor(key);
  if (!state.socket || state.status !== 'connected') throw new Error('WhatsApp não conectado.');

  const phone = String(body.phone || '').replace(/\D+/g, '');
  const message = String(body.message || '').trim();
  if (!/^\d{12,15}$/.test(phone)) throw new Error('Telefone inválido.');
  if (!message || message.length > 2000) throw new Error('Mensagem inválida.');

  const result = await state.socket.sendMessage(`${phone}@s.whatsapp.net`, { text: message });
  return { ok: true, message_id: String(result?.key?.id || '') };
}

async function listSavedSessionKeys() {
  let entries = [];
  try {
    entries = await fs.promises.readdir(SESSION_ROOT, { withFileTypes: true });
  } catch (_) {
    return [];
  }

  const keys = [];
  for (const entry of entries) {
    if (!entry.isDirectory() || !SESSION_RE.test(entry.name)) continue;
    try {
      await fs.promises.access(path.join(SESSION_ROOT, entry.name, 'creds.json'), fs.constants.R_OK);
      keys.push(entry.name);
    } catch (_) {}
  }
  return keys;
}

const server = http.createServer(async (req, res) => {
  try {
    if (!authorized(req)) return json(res, 401, { ok: false, error: 'Não autorizado.' });

    const url = new URL(req.url || '/', `http://${HOST}:${PORT}`);
    const pathname = normalizePathname(url.pathname);

    if (req.method === 'GET' && pathname === '/health') {
      const saved = await listSavedSessionKeys();
      return json(res, 200, {
        ok: true,
        service: 'eventmenu-whatsapp-bridge',
        engine: 'baileys',
        sessions: sessions.size,
        saved_sessions: saved.length,
        pairing_code: true,
      });
    }

    const match = pathname.match(/^\/v1\/sessions\/([a-f0-9]{64})(?:\/(start|pairing-code|logout|send))?$/);
    if (!match) return json(res, 404, { ok: false, error: 'Rota não encontrada.' });

    const key = match[1];
    const action = match[2] || '';

    if (req.method === 'GET' && !action) return json(res, 200, publicState(stateFor(key)));
    if (req.method === 'POST' && action === 'start') {
      const state = stateFor(key);
      state.pairingMode = false;
      state.pairingCode = null;
      state.pairingPhone = '';
      startSession(key, 'qr').catch(() => {});
      return json(res, 202, publicState(state));
    }
    if (req.method === 'POST' && action === 'pairing-code') return json(res, 200, publicState(await requestPairingCode(key, await readBody(req))));
    if (req.method === 'POST' && action === 'logout') return json(res, 200, publicState(await logoutSession(key)));
    if (req.method === 'POST' && action === 'send') return json(res, 200, await sendMessage(key, await readBody(req)));

    return json(res, 405, { ok: false, error: 'Método não permitido.' });
  } catch (error) {
    return json(res, 500, { ok: false, error: String(error?.message || 'Erro interno.').slice(0, 400) });
  }
});

server.listen(PORT, HOST, async () => {
  console.log(`EventMenu WhatsApp Bridge (Baileys) em http://${HOST}:${PORT}`);
  console.log(`Sessões protegidas em ${SESSION_ROOT}`);
  const keys = await listSavedSessionKeys();
  keys.forEach((key, index) => {
    setTimeout(() => startSession(key).catch(() => {}), 250 * (index + 1));
  });
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, async () => {
    server.close();
    for (const state of sessions.values()) {
      state.manualStop = true;
      cancelReconnect(state);
      try { state.socket?.end(new Error('Encerramento do serviço')); } catch (_) {}
      state.socket = null;
    }
    process.exit(0);
  });
}
