<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

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

$_SERVER['HTTP_X_EVENTMENU_VERSION'] = '0.1.9';
$blocked = false;
try {
    (new ClientPolicyGateService())->assertCurrentRequestAllowed();
} catch (RuntimeException $e) {
    $blocked = str_contains($e->getMessage(), 'Atualização obrigatória');
}
cp_assert($blocked, 'Gate não bloqueou cliente abaixo da versão mínima.');

unset($_SERVER['HTTP_X_EVENTMENU_CLIENT'], $_SERVER['HTTP_X_EVENTMENU_VERSION'], $_SERVER['HTTP_X_EVENTMENU_SIGNING_FINGERPRINT']);
echo "client-policy smoke ok\n";
