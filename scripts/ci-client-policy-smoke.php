<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use EventMenu\Services\ClientPolicyGateService;
use EventMenu\Services\ClientPolicyService;

function cp_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$pdo = Database::connection();
$pdo->query('SELECT 1 FROM platform_client_policies LIMIT 1');
$pdo->query('SELECT 1 FROM platform_policy_signing LIMIT 1');

$_SESSION['user_id'] = 999999;
$_SESSION['tenant_id'] = null;
$_SESSION['role'] = 'super_admin';
$_SESSION['name'] = 'CI Super Admin';
unset($_SESSION['acting_tenant_id']);

$fingerprintHex = str_repeat('AB', 32);
$fingerprint = implode(':', str_split($fingerprintHex, 2));
$service = new ClientPolicyService();
$policy = $service->save('android', [
    'enabled' => true,
    'maintenance' => false,
    'min_version' => '0.2.0',
    'recommended_version' => '0.3.0',
    'ttl_seconds' => 3600,
    'features' => ['delivery' => true, 'hub' => false],
    'allowed_signing_fingerprints' => [$fingerprint],
]);
cp_assert($policy['platform'] === 'android', 'Política Android não foi salva.');
cp_assert(!empty($policy['enabled']), 'Política Android ficou desativada.');
cp_assert(($policy['features']['delivery'] ?? false) === true, 'Feature delivery não foi persistida.');

$envelope = $service->signedEnvelope('android');
cp_assert(($envelope['algorithm'] ?? '') === 'RS256', 'Envelope não usa RS256.');
cp_assert(!isset($envelope['private_key']) && !isset($envelope['private_key_pem']), 'Envelope expôs chave privada.');
$key = $service->publicKeyBundle();
cp_assert(($key['key_id'] ?? '') === ($envelope['key_id'] ?? ''), 'Key ID do envelope divergente.');
$payloadRaw = base64_decode((string)$envelope['payload_b64'], true);
$signature = base64_decode((string)$envelope['signature_b64'], true);
cp_assert($payloadRaw !== false && $signature !== false, 'Envelope base64 inválido.');
cp_assert(openssl_verify($payloadRaw, $signature, (string)$key['public_key_pem'], OPENSSL_ALGO_SHA256) === 1, 'Assinatura RS256 inválida.');
$payload = json_decode($payloadRaw, true, 512, JSON_THROW_ON_ERROR);
cp_assert(($payload['platform'] ?? '') === 'android', 'Payload assinou plataforma errada.');
cp_assert(($payload['min_version'] ?? '') === '0.2.0', 'Versão mínima ausente do payload.');
cp_assert(!empty($payload['expires_at']), 'Expiração ausente do payload.');

$_SERVER['HTTP_X_EVENTMENU_CLIENT'] = 'android';
$_SERVER['HTTP_X_EVENTMENU_VERSION'] = '0.2.0';
$_SERVER['HTTP_X_EVENTMENU_SIGNING_FINGERPRINT'] = $fingerprint;
(new ClientPolicyGateService())->assertCurrentRequestAllowed();

$originalScriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '');
$_SERVER['SCRIPT_NAME'] = '/1/api-go-delivery.php';
(new ClientPolicyGateService())->assertCurrentRequestAllowed();
$_SERVER['SCRIPT_NAME'] = '/1/api-hub.php';
$featureBlocked = false;
try {
    (new ClientPolicyGateService())->assertCurrentRequestAllowed();
} catch (RuntimeException $e) {
    $featureBlocked = str_contains($e->getMessage(), 'recurso foi desativado') && str_contains($e->getMessage(), 'hub');
}
cp_assert($featureBlocked, 'Gate não bloqueou API do Hub desativada pela política remota.');
$_SERVER['SCRIPT_NAME'] = $originalScriptName;

$_SERVER['HTTP_X_EVENTMENU_VERSION'] = '0.1.9';
$blocked = false;
try {
    (new ClientPolicyGateService())->assertCurrentRequestAllowed();
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'Atualização obrigatória');
}
cp_assert($blocked, 'Gate não bloqueou cliente abaixo da versão mínima.');

// O contingência não pode continuar autorizando clientes com uma política antiga
// só porque a assinatura ainda é criptograficamente válida.
$signing = $pdo->query('SELECT private_key_encrypted FROM platform_policy_signing WHERE id=1')->fetch();
cp_assert(is_array($signing) && !empty($signing['private_key_encrypted']), 'Chave privada de teste não encontrada.');
$expiredPayload = $payload;
$expiredPayload['issued_at'] = gmdate('c', time() - 7200);
$expiredPayload['expires_at'] = gmdate('c', time() - 3600);
$expiredRaw = json_encode($expiredPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$expiredSignature = '';
cp_assert(openssl_sign($expiredRaw, $expiredSignature, Crypto::decrypt((string)$signing['private_key_encrypted']), OPENSSL_ALGO_SHA256), 'Não foi possível assinar envelope expirado de teste.');
$service->cacheEnvelope('android', [
    'ok' => true,
    'service' => 'eventmenu-client-policy',
    'algorithm' => 'RS256',
    'key_id' => (string)$key['key_id'],
    'payload_b64' => base64_encode($expiredRaw),
    'signature_b64' => base64_encode($expiredSignature),
    'payload' => $expiredPayload,
]);

$previousRole = getenv('EVENTMENU_NODE_ROLE');
putenv('EVENTMENU_NODE_ROLE=contingency');
$_ENV['EVENTMENU_NODE_ROLE'] = 'contingency';
$_SERVER['HTTP_X_EVENTMENU_VERSION'] = '0.2.0';
$expiredBlocked = false;
try {
    (new ClientPolicyGateService())->assertCurrentRequestAllowed();
} catch (RuntimeException $e) {
    $expiredBlocked = str_contains($e->getMessage(), 'Autorização remota indisponível') || str_contains($e->getMessage(), 'expirada');
}
cp_assert($expiredBlocked, 'Contingência aceitou política assinada expirada.');

if ($previousRole === false || $previousRole === '') {
    putenv('EVENTMENU_NODE_ROLE');
    unset($_ENV['EVENTMENU_NODE_ROLE']);
} else {
    putenv('EVENTMENU_NODE_ROLE=' . $previousRole);
    $_ENV['EVENTMENU_NODE_ROLE'] = $previousRole;
}
$service->cacheEnvelope('android', $envelope);

unset($_SERVER['HTTP_X_EVENTMENU_CLIENT'], $_SERVER['HTTP_X_EVENTMENU_VERSION'], $_SERVER['HTTP_X_EVENTMENU_SIGNING_FINGERPRINT']);
if ($originalScriptName === '') unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME'] = $originalScriptName;
echo "client-policy smoke ok\n";
