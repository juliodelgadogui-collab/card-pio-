<?php
declare(strict_types=1);
require __DIR__ . '/lib/apk_registry.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$expected = trim((string) getenv('EVENTMENU_APK_PUBLISH_TOKEN'));
if (strlen($expected) < 24) {
    respond(503, ['ok' => false, 'error' => 'publisher_not_configured']);
}

$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$provided = '';
if (preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $m)) {
    $provided = trim($m[1]);
}
if ($provided === '') {
    $provided = trim((string) ($_SERVER['HTTP_X_EVENTMENU_TOKEN'] ?? ''));
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    respond(401, ['ok' => false, 'error' => 'unauthorized']);
}

if (!isset($_FILES['apk']) || !is_array($_FILES['apk'])) {
    respond(422, ['ok' => false, 'error' => 'apk_required']);
}
if ((int) $_FILES['apk']['error'] !== UPLOAD_ERR_OK) {
    respond(422, ['ok' => false, 'error' => 'upload_failed', 'upload_code' => (int) $_FILES['apk']['error']]);
}

try {
    $slug = strtolower(trim((string) ($_POST['app'] ?? '')));
    $meta = em_publish_apk(
        $slug,
        trim((string) ($_POST['version'] ?? '')),
        (int) ($_POST['version_code'] ?? 0),
        (string) $_FILES['apk']['tmp_name'],
        (string) $_FILES['apk']['name']
    );

    $manifest = em_read_manifest();
    $latestUrl = (string) ($manifest['apps'][$slug]['latest_url'] ?? ('https://go.gestao2.store/apk/' . $slug . '/latest.apk'));
    respond(201, [
        'ok' => true,
        'app' => $slug,
        'version' => $meta['version'],
        'version_code' => $meta['version_code'],
        'size_bytes' => $meta['size_bytes'],
        'sha256' => $meta['sha256'],
        'latest_url' => $latestUrl,
    ]);
} catch (Throwable $e) {
    respond(422, ['ok' => false, 'error' => 'publish_failed', 'message' => $e->getMessage()]);
}
