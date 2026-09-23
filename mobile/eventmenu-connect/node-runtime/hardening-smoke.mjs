import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(process.cwd());
const main = fs.readFileSync(path.join(root, 'node-runtime/main.js'), 'utf8');
const worker = fs.readFileSync(path.join(root, 'app/src/main/java/br/com/eventmenu/connect/ConnectWorkerService.kt'), 'utf8');
const store = fs.readFileSync(path.join(root, 'app/src/main/java/br/com/eventmenu/connect/SessionStore.kt'), 'utf8');
const manifest = fs.readFileSync(path.join(root, 'app/src/main/AndroidManifest.xml'), 'utf8');
const backgroundGuard = fs.readFileSync(path.join(root, 'app/src/main/java/br/com/eventmenu/connect/EventMenuConnectApplication.kt'), 'utf8');
const resilience = fs.readFileSync(path.join(root, 'app/src/main/java/br/com/eventmenu/connect/ConnectionResilienceSupervisor.kt'), 'utf8');
const packageReceiver = fs.readFileSync(path.join(root, 'app/src/main/java/br/com/eventmenu/connect/PackageUpdatedReceiver.kt'), 'utf8');
const network = fs.readFileSync(path.join(root, 'app/src/main/res/xml/network_security_config.xml'), 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

assert(main.includes('eventmenu-whatsapp-sent.json'), 'Ledger persistente de envios não encontrado.');
assert(main.includes('idempotency_key'), 'Runtime Node não recebe chave de idempotência.');
assert(main.includes('duplicate_prevented'), 'Runtime Node não sinaliza replay idempotente.');
assert(main.includes('const transientDisconnect'), 'Reconexões transitórias não estão classificadas.');
assert(main.includes('408') && main.includes('428') && main.includes('503'), 'Códigos transitórios esperados não estão protegidos.');
assert(main.includes("parsed.protocol !== 'https:'"), 'Download de mídia ainda aceita transporte externo sem HTTPS.');

const pairingStart = main.indexOf('async function resetForFreshPairing()');
const pairingEnd = main.indexOf('async function waitUntilPairingReady', pairingStart);
const pairingBlock = pairingStart >= 0 && pairingEnd > pairingStart ? main.slice(pairingStart, pairingEnd) : '';
assert(pairingBlock.length > 0 && !pairingBlock.includes('clearInboundQueue()'), 'Novo pareamento ainda apaga mensagens inbound não confirmadas.');
assert(main.includes('async function logoutSession()') && main.slice(main.indexOf('async function logoutSession()')).includes('clearInboundQueue()'), 'Logout completo precisa limpar inbound para impedir mistura entre contas.');

// Pairing-code hardening: pair-success normally closes the first socket with 515.
assert(main.includes('let credsSaveChain = Promise.resolve()'), 'Pareamento não serializa a persistência das credenciais.');
assert(main.includes('await credsSaveChain'), '515 pode reiniciar antes de as credenciais novas serem persistidas.');
assert(main.includes('scheduleReconnect(0, false)'), '515 não está configurado para reconexão imediata sem backoff.');
assert(main.includes('markOnlineOnConnect: true'), 'Novo vínculo não marca a sessão online durante a abertura inicial.');
assert(main.includes('pairingRequestPhone') && main.includes('hasActivePairingCode()'), 'Geração concorrente de códigos ainda pode sobrescrever o estado do pareamento.');
assert(main.includes('pairing_last_failure_code'), 'Diagnóstico técnico do último erro de pareamento não está exposto.');
assert(main.includes('fetchLatestBaileysVersion') && main.indexOf('fetchLatestBaileysVersion({ timeout: 12000 })') < main.indexOf('fetchLatestWaWebVersion({'), 'Runtime não prioriza a versão de protocolo compatível com o Baileys instalado.');

assert(worker.includes('savePendingOutboundAck'), 'Android não persiste ACK depois do envio.');
assert(worker.includes('flushPendingAcks'), 'Android não reconcilia ACKs pendentes antes de novos envios.');
assert(worker.includes('api.claim(1)'), 'Worker deve manter somente um lease outbound por vez.');
assert(worker.includes('eventmenu-tenant-${store.tenantId()}-outbox-$id'), 'Idempotência local não está isolada por empresa.');
assert(worker.includes('NUNCA chamamos fail()'), 'Separação entre falha de envio e falha de ACK não está explícita.');
assert(worker.includes('NET_CAPABILITY_VALIDATED'), 'Worker não valida conectividade real antes de sincronizar.');
assert(store.includes('pending_outbound_acks'), 'Recibos pendentes não sobrevivem a reinício do processo.');
assert(store.includes('.putInt("tenant_id"') && store.includes('fun tenantId(): Int'), 'Identidade da empresa não é persistida para isolar a idempotência.');
assert(store.includes('.remove("tenant_id")'), 'Logout do EventMenu não limpa a identidade local da empresa.');

assert(manifest.includes('android:usesCleartextTraffic="false"'), 'Aplicativo ainda libera HTTP externo globalmente.');
assert(manifest.includes('android:networkSecurityConfig="@xml/network_security_config"'), 'Network Security Config não está ativado.');
assert(manifest.includes('android.permission.REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'), 'Connect não pode solicitar liberação da otimização de bateria.');
assert(manifest.includes('android:stopWithTask="false"'), 'Serviço ainda pode ser encerrado junto com a tela/tarefa.');
assert(manifest.includes('android:name=".EventMenuConnectApplication"'), 'Guardião de pareamento em segundo plano não está ativado.');
assert(manifest.includes('android:name=".PackageUpdatedReceiver"') && manifest.includes('android.intent.action.MY_PACKAGE_REPLACED'), 'Connect não retoma automaticamente após atualização do APK.');
assert(backgroundGuard.includes('PowerManager.PARTIAL_WAKE_LOCK'), 'Pareamento não mantém CPU/socket acordados ao trocar de aplicativo.');
assert(backgroundGuard.includes('lock.acquire(5 * 60 * 1000L)'), 'Wake lock de pareamento precisa ter timeout curto e explícito.');
assert(backgroundGuard.includes('ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS'), 'App não oferece liberação de otimização de bateria.');
assert(backgroundGuard.includes('ConnectionResilienceSupervisor(this).also { it.start() }'), 'Supervisor de resiliência não inicia com o processo do Connect.');

// Functional resilience stays internal; no technical dashboard is required.
assert(resilience.includes('registerDefaultNetworkCallback'), 'Troca/retorno de rede não é observada pelo Connect.');
assert(resilience.includes('NET_CAPABILITY_VALIDATED'), 'Supervisor não confirma internet validada antes de recuperar.');
assert(resilience.includes('PowerManager.PARTIAL_WAKE_LOCK'), 'Transição de conexão não protege CPU/socket em segundo plano.');
assert(resilience.includes('engine.startQr()'), 'Supervisor não consegue estimular retomada segura da sessão existente.');
assert(resilience.includes('"reconnecting", "starting" -> stuckFor >= 90_000'), 'Watchdog não detecta reconexão travada.');
assert(resilience.includes('"error" -> stuckFor >= 20_000'), 'Watchdog não recupera falhas transitórias persistentes.');
assert(resilience.includes('status == "disconnected" || isTerminal(current)'), 'Autorreparo pode reabrir pareamento após logout explícito.');
assert(packageReceiver.includes('Intent.ACTION_MY_PACKAGE_REPLACED') && packageReceiver.includes('startConnectService(context)'), 'Receiver de atualização não retoma o serviço.');

assert(network.includes('cleartextTrafficPermitted="false"'), 'Configuração base ainda permite cleartext.');
assert(network.includes('127.0.0.1'), 'Loopback interno do Node não foi preservado.');

// Inbound LID hardening: modern WhatsApp may deliver an opaque @lid before PN metadata.
assert(/lid-mapping\.update/.test(main), 'runtime must consume Baileys LID→PN mapping updates');
assert(/eventmenu-whatsapp-inbound-unresolved\.json/.test(main), 'LID-only inbound must be durably preserved instead of discarded');
assert(/inbound_unresolved/.test(main), 'runtime state must expose unresolved inbound diagnostics');
assert(/remoteJidAlt/.test(main), 'runtime must prefer alternate phone JID metadata when available');

console.log('EventMenu Connect hardening smoke OK');
