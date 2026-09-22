import http from 'node:http';
import https from 'node:https';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import pino from 'pino';
import { Boom } from '@hapi/boom';
import makeWASocket, {
  Browsers,
  DisconnectReason,
  fetchLatestBaileysVersion,
  fetchLatestWaWebVersion,
  makeCacheableSignalKeyStore,
  useMultiFileAuthState,
} from '@whiskeysockets/baileys';

const configPath = process.argv[2];
if (!configPath) throw new Error('Arquivo de configuração não informado.');
const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const HOST = '127.0.0.1';
const PORT = Number(config.port || 21567);
const SECRET = String(config.secret || '');
const SESSION_ROOT = path.resolve(String(config.session_root || ''));
const MAX_BODY = 64 * 1024;
const MAX_MEDIA = 20 * 1024 * 1024;
const MEDIA_TIMEOUT = 30_000;
const MAX_REDIRECTS = 4;
const MAX_INBOUND_QUEUE = 5000;
const INBOUND_FILE = path.join(path.dirname(SESSION_ROOT), 'eventmenu-whatsapp-inbound.json');
const logger = pino({ level: 'silent' });

if (!SECRET || SECRET.length < 48) throw new Error('Segredo local inválido.');
if (!Number.isInteger(PORT) || PORT < 1024 || PORT > 65535) throw new Error('Porta local inválida.');
if (!SESSION_ROOT) throw new Error('Diretório da sessão não informado.');
fs.mkdirSync(SESSION_ROOT, { recursive: true, mode: 0o700 });
try { fs.chmodSync(SESSION_ROOT, 0o700); } catch (_) {}

const state = {
  status: 'disconnected',
  qr: null,
  pairingCode: null,
  pairingExpiresAt: 0,
  countryCode: String(config.country_code || 'BR').toUpperCase(),
  phone: '',
  error: '',
  lastDisconnectCode: 0,
  socket: null,
  starting: null,
  reconnectTimer: null,
  reconnectAttempt: 0,
  manualStop: false,
  updatedAt: new Date().toISOString(),
};

function touch() { state.updatedAt = new Date().toISOString(); }
function readInboundQueue() {
  try {
    const raw = fs.readFileSync(INBOUND_FILE, 'utf8');
    const rows = JSON.parse(raw);
    return Array.isArray(rows) ? rows.filter((row) => row && typeof row === 'object') : [];
  } catch (_) {
    return [];
  }
}
function writeInboundQueue(rows) {
  const directory = path.dirname(INBOUND_FILE);
  fs.mkdirSync(directory, { recursive: true, mode: 0o700 });
  const temporary = `${INBOUND_FILE}.tmp`;
  fs.writeFileSync(temporary, JSON.stringify(rows), { encoding: 'utf8', mode: 0o600 });
  fs.renameSync(temporary, INBOUND_FILE);
}
function clearInboundQueue() {
  try { fs.rmSync(INBOUND_FILE, { force: true }); } catch (_) {}
  try { fs.rmSync(`${INBOUND_FILE}.tmp`, { force: true }); } catch (_) {}
}
function enqueueInbound(row) {
  const id = String(row?.provider_message_id || '').trim();
  if (!id) return false;
  const rows = readInboundQueue();
  if (rows.some((item) => String(item?.provider_message_id || '') === id)) return false;
  if (rows.length >= MAX_INBOUND_QUEUE) {
    state.error = 'A fila local de mensagens recebidas atingiu o limite de segurança.';
    touch();
    return false;
  }
  rows.push(row);
  writeInboundQueue(rows);
  return true;
}
function acknowledgeInbound(ids) {
  const accepted = new Set((Array.isArray(ids) ? ids : []).map((id) => String(id || '').trim()).filter(Boolean));
  if (!accepted.size) return 0;
  const rows = readInboundQueue();
  const next = rows.filter((row) => !accepted.has(String(row?.provider_message_id || '')));
  if (next.length !== rows.length) writeInboundQueue(next);
  return rows.length - next.length;
}
function inboundBatch(limit = 20) {
  const safe = Math.max(1, Math.min(50, Number(limit) || 20));
  return readInboundQueue().slice(0, safe);
}
function expirePairingCodeIfNeeded() {
  if (state.pairingCode && state.pairingExpiresAt && Date.now() >= state.pairingExpiresAt) {
    state.pairingCode = null;
    state.pairingExpiresAt = 0;
    if (state.status === 'pairing') state.status = 'disconnected';
    if (!state.error) state.error = 'O código expirou. Gere um novo código de conexão.';
    touch();
  }
}
function publicState() {
  expirePairingCodeIfNeeded();
  return {
    ok: true,
    status: state.status,
    qr: state.qr,
    pairing_code: state.pairingCode,
    pairing_expires_at: state.pairingExpiresAt || null,
    country_code: state.countryCode,
    phone: state.phone,
    error: state.error || null,
    disconnect_code: state.lastDisconnectCode || null,
    inbound_pending: readInboundQueue().length,
    updated_at: state.updatedAt,
  };
}
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
async function readBody(req) {
  const chunks = [];
  let size = 0;
  for await (const chunk of req) {
    size += chunk.length;
    if (size > MAX_BODY) throw new Error('Payload grande demais.');
    chunks.push(chunk);
  }
  if (!chunks.length) return {};
  const value = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
}
function disconnectCode(lastDisconnect) {
  const error = lastDisconnect?.error;
  if (!error) return 0;
  try { return new Boom(error).output.statusCode || 0; }
  catch (_) { return Number(error?.output?.statusCode || error?.statusCode || 0); }
}
function phoneFromSocket(socket) {
  const raw = String(socket?.user?.id || '');
  const left = raw.split('@')[0].split(':')[0];
  const phone = left.replace(/\D+/g, '');
  return /^\d{8,15}$/.test(phone) ? phone : '';
}
function phoneFromMessageKey(key) {
  const primary = String(key?.remoteJid || '');
  if (primary.endsWith('@g.us') || primary.endsWith('@broadcast') || primary === 'status@broadcast') return '';
  const candidates = [key?.remoteJidAlt, key?.remoteJid, key?.participantAlt, key?.participant];
  for (const raw of candidates) {
    const jid = String(raw || '');
    if (!jid.endsWith('@s.whatsapp.net')) continue;
    let digits = jid.split('@')[0].split(':')[0].replace(/\D+/g, '').replace(/^0+/, '');
    if (digits.length === 10 || digits.length === 11) digits = `55${digits}`;
    if (/^\d{10,15}$/.test(digits)) return digits;
  }
  return '';
}
function unwrapMessage(message) {
  let value = message && typeof message === 'object' ? message : {};
  for (let i = 0; i < 4; i += 1) {
    const next = value?.ephemeralMessage?.message
      || value?.viewOnceMessage?.message
      || value?.viewOnceMessageV2?.message
      || value?.viewOnceMessageV2Extension?.message;
    if (!next || typeof next !== 'object') break;
    value = next;
  }
  return value;
}
function parseInboundMessage(message) {
  const value = unwrapMessage(message);
  if (typeof value.conversation === 'string') return { message_type: 'text', text: value.conversation, payload: {} };
  if (value.extendedTextMessage) return { message_type: 'text', text: String(value.extendedTextMessage.text || ''), payload: {} };
  if (value.buttonsResponseMessage) return { message_type: 'text', text: String(value.buttonsResponseMessage.selectedDisplayText || value.buttonsResponseMessage.selectedButtonId || ''), payload: { interactive: true } };
  if (value.listResponseMessage) return { message_type: 'text', text: String(value.listResponseMessage.title || value.listResponseMessage.singleSelectReply?.selectedRowId || ''), payload: { interactive: true } };
  if (value.templateButtonReplyMessage) return { message_type: 'text', text: String(value.templateButtonReplyMessage.selectedDisplayText || value.templateButtonReplyMessage.selectedId || ''), payload: { interactive: true } };
  if (value.imageMessage) return { message_type: 'image', text: String(value.imageMessage.caption || ''), payload: { mimetype: String(value.imageMessage.mimetype || '') } };
  if (value.videoMessage) return { message_type: 'video', text: String(value.videoMessage.caption || ''), payload: { mimetype: String(value.videoMessage.mimetype || '') } };
  if (value.audioMessage) return { message_type: 'audio', text: '', payload: { mimetype: String(value.audioMessage.mimetype || ''), seconds: Number(value.audioMessage.seconds || 0) } };
  if (value.documentMessage) return { message_type: 'document', text: String(value.documentMessage.caption || ''), payload: { mimetype: String(value.documentMessage.mimetype || ''), file_name: String(value.documentMessage.fileName || '').slice(0, 180) } };
  if (value.stickerMessage) return { message_type: 'sticker', text: '', payload: { mimetype: String(value.stickerMessage.mimetype || '') } };
  if (value.contactMessage || value.contactsArrayMessage) return { message_type: 'contact', text: String(value.contactMessage?.displayName || ''), payload: {} };
  if (value.locationMessage) return { message_type: 'location', text: String(value.locationMessage.name || value.locationMessage.address || ''), payload: { latitude: Number(value.locationMessage.degreesLatitude || 0), longitude: Number(value.locationMessage.degreesLongitude || 0) } };
  return { message_type: 'unknown', text: '', payload: { keys: Object.keys(value).slice(0, 12) } };
}
function captureInbound(message) {
  const key = message?.key || {};
  if (key.fromMe) return false;
  const providerId = String(key.id || '').trim();
  const phone = phoneFromMessageKey(key);
  if (!providerId || !phone) return false;
  const parsed = parseInboundMessage(message?.message);
  const rawTimestamp = message?.messageTimestamp;
  const timestamp = Number(typeof rawTimestamp === 'number' ? rawTimestamp : rawTimestamp?.toString?.() || 0);
  return enqueueInbound({
    provider_message_id: providerId.slice(0, 190),
    phone,
    name: String(message?.pushName || '').trim().slice(0, 180),
    message_type: parsed.message_type,
    text: String(parsed.text || '').trim().slice(0, 4000),
    timestamp: Number.isFinite(timestamp) && timestamp > 0 ? timestamp : Math.floor(Date.now() / 1000),
    payload: parsed.payload || {},
  });
}
function normalizePairPhone(value) {
  const digits = String(value || '').replace(/\D+/g, '').replace(/^0+/, '');
  if (!/^\d{8,15}$/.test(digits)) throw new Error('Número inválido. Selecione o país e informe o número corretamente.');
  return digits;
}
function normalizeRecipient(value) {
  let digits = String(value || '').replace(/\D+/g, '').replace(/^0+/, '');
  if (digits.length === 10 || digits.length === 11) digits = `55${digits}`;
  if (!/^\d{10,15}$/.test(digits)) throw new Error('Número de destino inválido.');
  return digits;
}
function normalizeCountryCode(value) {
  const code = String(value || '').trim().toUpperCase();
  return /^[A-Z]{2}$/.test(code) ? code : 'BR';
}
function normalizeMediaType(value) {
  const type = String(value || '').trim().toLowerCase();
  if (type === 'pdf') return 'document';
  if (type === 'image' || type === 'document') return type;
  return '';
}
function safeFilename(value, fallback = 'documento.pdf') {
  const raw = String(value || '').replace(/[\u0000-\u001f\u007f]/g, '').trim();
  const name = path.basename(raw).slice(0, 180);
  return name || fallback;
}
function cancelReconnect() {
  if (state.reconnectTimer) clearTimeout(state.reconnectTimer);
  state.reconnectTimer = null;
}
function scheduleReconnect(delayOverride = null) {
  if (state.manualStop || state.starting || state.reconnectTimer) return;
  state.reconnectAttempt = Math.min(state.reconnectAttempt + 1, 8);
  const delay = delayOverride == null
    ? Math.min(60000, 2000 * (2 ** (state.reconnectAttempt - 1)))
    : delayOverride;
  state.status = 'reconnecting';
  touch();
  state.reconnectTimer = setTimeout(() => {
    state.reconnectTimer = null;
    startSession().catch(() => {});
  }, delay);
}
async function removeSessionFiles() {
  await fs.promises.rm(SESSION_ROOT, { recursive: true, force: true });
  await fs.promises.mkdir(SESSION_ROOT, { recursive: true, mode: 0o700 });
}
async function resolveWhatsAppVersion() {
  try {
    const live = await fetchLatestWaWebVersion({
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
        'Accept': '*/*',
      },
      timeout: 12000,
    });
    if (live?.isLatest && Array.isArray(live.version)) return live.version;
  } catch (_) {}
  try {
    const fallback = await fetchLatestBaileysVersion({ timeout: 12000 });
    if (Array.isArray(fallback?.version)) return fallback.version;
  } catch (_) {}
  return undefined;
}
async function resetForFreshPairing() {
  state.manualStop = true;
  cancelReconnect();
  const socket = state.socket;
  state.socket = null;
  if (socket) {
    try { socket.end(new Error('Novo pareamento solicitado')); } catch (_) {}
  }
  await new Promise((resolve) => setTimeout(resolve, 350));
  await removeSessionFiles();
  clearInboundQueue();
  state.status = 'disconnected';
  state.qr = null;
  state.pairingCode = null;
  state.pairingExpiresAt = 0;
  state.phone = '';
  state.error = '';
  state.lastDisconnectCode = 0;
  state.reconnectAttempt = 0;
  state.starting = null;
  state.manualStop = false;
  touch();
}
async function waitUntilPairingReady(timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (state.qr && state.socket) return;
    if (state.status === 'error') throw new Error(state.error || 'Falha ao preparar a conexão com o WhatsApp.');
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('O WhatsApp não ficou pronto para gerar o código. Tente novamente.');
}

async function startSession() {
  state.manualStop = false;
  cancelReconnect();
  if (state.socket && ['connected', 'starting', 'qr', 'pairing', 'reconnecting'].includes(state.status)) return state;
  if (state.starting) return state.starting;

  state.status = state.reconnectAttempt > 0 ? 'reconnecting' : 'starting';
  state.error = '';
  state.qr = null;
  if (state.status !== 'pairing') {
    state.pairingCode = null;
    state.pairingExpiresAt = 0;
  }
  touch();

  const promise = (async () => {
    const { state: authState, saveCreds } = await useMultiFileAuthState(SESSION_ROOT);
    const version = await resolveWhatsAppVersion();
    const socket = makeWASocket({
      auth: {
        creds: authState.creds,
        keys: makeCacheableSignalKeyStore(authState.keys, logger),
      },
      ...(version ? { version } : {}),
      countryCode: state.countryCode,
      logger,
      printQRInTerminal: false,
      markOnlineOnConnect: false,
      syncFullHistory: false,
      generateHighQualityLinkPreview: false,
      browser: Browsers.windows('Chrome'),
      connectTimeoutMs: 60_000,
      keepAliveIntervalMs: 15_000,
      defaultQueryTimeoutMs: 60_000,
    });
    state.socket = socket;

    socket.ev.on('creds.update', () => {
      saveCreds().catch((error) => {
        state.error = String(error?.message || 'Falha ao salvar a sessão.').slice(0, 400);
        touch();
      });
    });

    socket.ev.on('messages.upsert', (event) => {
      if (event?.type !== 'notify' || !Array.isArray(event?.messages)) return;
      for (const message of event.messages) {
        try { captureInbound(message); }
        catch (error) {
          state.error = String(error?.message || 'Falha ao registrar mensagem recebida.').slice(0, 400);
          touch();
        }
      }
    });

    socket.ev.on('connection.update', (update) => {
      const { connection, qr, lastDisconnect } = update;
      if (qr) {
        state.qr = qr;
        if (!state.pairingCode) state.status = 'qr';
        state.error = '';
        touch();
      }
      if (connection === 'connecting' && !['qr', 'pairing'].includes(state.status)) {
        state.status = state.reconnectAttempt > 0 ? 'reconnecting' : 'starting';
        touch();
      }
      if (connection === 'open') {
        state.status = 'connected';
        state.qr = null;
        state.pairingCode = null;
        state.pairingExpiresAt = 0;
        state.error = '';
        state.lastDisconnectCode = 0;
        state.reconnectAttempt = 0;
        state.phone = phoneFromSocket(socket);
        touch();
      }
      if (connection === 'close') {
        if (state.socket !== socket) return;
        state.socket = null;
        state.qr = null;
        const code = disconnectCode(lastDisconnect);
        state.lastDisconnectCode = code;
        const loggedOut = code === DisconnectReason.loggedOut;
        const restartRequired = code === DisconnectReason.restartRequired || code === 515;
        if (state.manualStop) {
          state.status = 'disconnected';
          state.pairingCode = null;
          state.pairingExpiresAt = 0;
          state.error = '';
          touch();
          return;
        }
        if (loggedOut) {
          state.status = 'disconnected';
          state.pairingCode = null;
          state.pairingExpiresAt = 0;
          state.phone = '';
          state.error = 'Sessão encerrada pelo WhatsApp. Gere um novo código.';
          state.reconnectAttempt = 0;
          touch();
          removeSessionFiles().catch(() => {});
          return;
        }
        if (restartRequired) {
          state.status = 'reconnecting';
          state.error = '';
          touch();
          scheduleReconnect(350);
          return;
        }
        state.pairingCode = null;
        state.pairingExpiresAt = 0;
        state.status = 'error';
        state.error = code === 405
          ? 'O WhatsApp recusou a versão do cliente (405). Gere um novo código; o Connect atualizará a versão Web automaticamente.'
          : `Conexão com o WhatsApp encerrada${code ? ` (código ${code})` : ''}. Gere um novo código.`;
        touch();
        if (code !== 405) scheduleReconnect();
      }
    });
    return state;
  })()
    .catch((error) => {
      state.socket = null;
      state.status = state.qr ? 'qr' : state.pairingCode ? 'pairing' : 'error';
      state.error = String(error?.message || error || 'Falha ao iniciar a sessão.').slice(0, 400);
      touch();
      throw error;
    })
    .finally(() => {
      state.starting = null;
      if (!state.manualStop && state.status === 'disconnected') scheduleReconnect();
    });

  state.starting = promise;
  return promise;
}

async function requestPairingCode(phone, countryCode) {
  phone = normalizePairPhone(phone);
  if (state.status === 'connected') return publicState();

  state.countryCode = normalizeCountryCode(countryCode || state.countryCode);
  await resetForFreshPairing();
  await startSession();
  if (!state.socket) throw new Error('Mecanismo do WhatsApp ainda não iniciou. Tente novamente.');
  await waitUntilPairingReady();

  const code = await state.socket.requestPairingCode(phone);
  if (!code) throw new Error('O WhatsApp não retornou o código de conexão.');
  state.pairingCode = String(code).replace(/\s+/g, '');
  state.pairingExpiresAt = Date.now() + 120_000;
  state.qr = null;
  state.status = 'pairing';
  state.error = '';
  state.lastDisconnectCode = 0;
  touch();
  return publicState();
}

async function logoutSession() {
  state.manualStop = true;
  cancelReconnect();
  const socket = state.socket;
  state.socket = null;
  try {
    if (socket) {
      try { await socket.logout(); }
      catch (_) { try { socket.end(new Error('Logout EventMenu Connect')); } catch (_) {} }
    }
  } finally {
    await removeSessionFiles().catch(() => {});
    clearInboundQueue();
    state.status = 'disconnected';
    state.qr = null;
    state.pairingCode = null;
    state.pairingExpiresAt = 0;
    state.phone = '';
    state.error = '';
    state.lastDisconnectCode = 0;
    state.reconnectAttempt = 0;
    touch();
  }
  return publicState();
}

async function downloadMedia(value, redirects = 0) {
  if (redirects > MAX_REDIRECTS) throw new Error('A mídia excedeu o limite de redirecionamentos.');
  let parsed;
  try { parsed = new URL(String(value || '')); }
  catch (_) { throw new Error('Endereço da mídia inválido.'); }
  if (!['http:', 'https:'].includes(parsed.protocol)) throw new Error('A mídia precisa usar HTTP ou HTTPS.');

  return new Promise((resolve, reject) => {
    const transport = parsed.protocol === 'https:' ? https : http;
    const request = transport.get(parsed, {
      headers: {
        'User-Agent': 'EventMenu-Connect-Android/1.0',
        'Accept': '*/*',
      },
    }, (response) => {
      const status = Number(response.statusCode || 0);
      if (status >= 300 && status < 400 && response.headers.location) {
        const next = new URL(response.headers.location, parsed).toString();
        response.resume();
        downloadMedia(next, redirects + 1).then(resolve, reject);
        return;
      }
      if (status < 200 || status >= 300) {
        response.resume();
        reject(new Error(`Não foi possível baixar a mídia (HTTP ${status || 0}).`));
        return;
      }

      const declared = Number(response.headers['content-length'] || 0);
      if (declared > MAX_MEDIA) {
        response.resume();
        reject(new Error('A mídia ultrapassa o limite de 20 MB.'));
        return;
      }

      const chunks = [];
      let size = 0;
      response.on('data', (chunk) => {
        size += chunk.length;
        if (size > MAX_MEDIA) {
          response.destroy(new Error('A mídia ultrapassa o limite de 20 MB.'));
          return;
        }
        chunks.push(chunk);
      });
      response.on('end', () => {
        if (!size) {
          reject(new Error('A mídia recebida está vazia.'));
          return;
        }
        const headerMime = String(response.headers['content-type'] || '').split(';')[0].trim().toLowerCase();
        resolve({ buffer: Buffer.concat(chunks), mime: headerMime });
      });
      response.on('error', reject);
    });
    request.setTimeout(MEDIA_TIMEOUT, () => request.destroy(new Error('O download da mídia excedeu o tempo limite.')));
    request.on('error', reject);
  });
}

async function sendMessage(body) {
  if (!state.socket || state.status !== 'connected') throw new Error('WhatsApp não conectado.');
  const phone = normalizeRecipient(body.phone);
  const message = String(body.message || '').trim();
  if (message.length > 4000) throw new Error('Mensagem grande demais.');

  const mediaType = normalizeMediaType(body.media_type);
  const mediaUrl = String(body.media_url || '').trim();
  let result;

  if (!mediaType && !mediaUrl) {
    if (!message) throw new Error('Mensagem inválida.');
    result = await state.socket.sendMessage(`${phone}@s.whatsapp.net`, { text: message });
  } else {
    if (!mediaType) throw new Error('Tipo de mídia não suportado.');
    if (!mediaUrl) throw new Error('Endereço da mídia não informado.');
    const downloaded = await downloadMedia(mediaUrl);
    const requestedMime = String(body.media_mime || '').trim().toLowerCase();
    const mime = requestedMime || downloaded.mime;

    if (mediaType === 'image') {
      const payload = { image: downloaded.buffer };
      if (message) payload.caption = message;
      if (mime && mime.startsWith('image/')) payload.mimetype = mime;
      result = await state.socket.sendMessage(`${phone}@s.whatsapp.net`, payload);
    } else {
      const payload = {
        document: downloaded.buffer,
        mimetype: mime || 'application/pdf',
        fileName: safeFilename(body.media_filename, 'documento.pdf'),
      };
      if (message) payload.caption = message;
      result = await state.socket.sendMessage(`${phone}@s.whatsapp.net`, payload);
    }
  }

  return { ok: true, message_id: String(result?.key?.id || '') };
}

const server = http.createServer(async (req, res) => {
  try {
    if (!authorized(req)) return json(res, 401, { ok: false, error: 'Não autorizado.' });
    const url = new URL(req.url || '/', `http://${HOST}:${PORT}`);
    if (req.method === 'GET' && url.pathname === '/health') {
      return json(res, 200, { ok: true, engine: 'baileys-embedded', node: process.version, status: state.status, inbound_pending: readInboundQueue().length });
    }
    if (req.method === 'GET' && url.pathname === '/state') return json(res, 200, publicState());
    if (req.method === 'GET' && url.pathname === '/inbound') return json(res, 200, { ok: true, messages: inboundBatch(url.searchParams.get('limit')) });
    if (req.method === 'POST' && url.pathname === '/inbound/ack') {
      const body = await readBody(req);
      return json(res, 200, { ok: true, acknowledged: acknowledgeInbound(body.ids) });
    }
    if (req.method === 'POST' && url.pathname === '/start') {
      startSession().catch(() => {});
      return json(res, 202, publicState());
    }
    if (req.method === 'POST' && url.pathname === '/pair') {
      const body = await readBody(req);
      return json(res, 200, await requestPairingCode(body.phone, body.country_code));
    }
    if (req.method === 'POST' && url.pathname === '/logout') return json(res, 200, await logoutSession());
    if (req.method === 'POST' && url.pathname === '/send') return json(res, 200, await sendMessage(await readBody(req)));
    return json(res, 404, { ok: false, error: 'Rota não encontrada.' });
  } catch (error) {
    return json(res, 500, { ok: false, error: String(error?.message || 'Erro interno.').slice(0, 400) });
  }
});

server.listen(PORT, HOST, async () => {
  try {
    const creds = path.join(SESSION_ROOT, 'creds.json');
    if (fs.existsSync(creds)) startSession().catch(() => {});
  } catch (_) {}
});

for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => {
    state.manualStop = true;
    cancelReconnect();
    try { state.socket?.end(new Error('Encerramento do EventMenu Connect')); } catch (_) {}
    server.close(() => process.exit(0));
  });
}
