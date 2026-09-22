import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.resolve(process.cwd());
const main = fs.readFileSync(path.join(root, 'node-runtime/main.js'), 'utf8');
const worker = fs.readFileSync(path.join(root, 'app/src/main/java/br/com/eventmenu/connect/ConnectWorkerService.kt'), 'utf8');
const store = fs.readFileSync(path.join(root, 'app/src/main/java/br/com/eventmenu/connect/SessionStore.kt'), 'utf8');
const manifest = fs.readFileSync(path.join(root, 'app/src/main/AndroidManifest.xml'), 'utf8');
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

assert(worker.includes('savePendingOutboundAck'), 'Android não persiste ACK depois do envio.');
assert(worker.includes('flushPendingAcks'), 'Android não reconcilia ACKs pendentes antes de novos envios.');
assert(worker.includes('eventmenu-outbox-$id'), 'ID estável da outbox não é enviado ao runtime Node.');
assert(worker.includes('NUNCA chamamos fail()'), 'Separação entre falha de envio e falha de ACK não está explícita.');
assert(worker.includes('NET_CAPABILITY_VALIDATED'), 'Worker não valida conectividade real antes de sincronizar.');
assert(store.includes('pending_outbound_acks'), 'Recibos pendentes não sobrevivem a reinício do processo.');

assert(manifest.includes('android:usesCleartextTraffic="false"'), 'Aplicativo ainda libera HTTP externo globalmente.');
assert(manifest.includes('android:networkSecurityConfig="@xml/network_security_config"'), 'Network Security Config não está ativado.');
assert(network.includes('cleartextTrafficPermitted="false"'), 'Configuração base ainda permite cleartext.');
assert(network.includes('127.0.0.1'), 'Loopback interno do Node não foi preservado.');

console.log('EventMenu Connect hardening smoke OK');
