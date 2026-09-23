<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$portal = $root . '/portal-root';

$required = [
    '.htaccess',
    'index.php',
    'downloads.php',
    'download.php',
    'updates.php',
    'admin-apks.php',
    'api-apk-publish.php',
    'lib/apk_registry.php',
    'data/apps.json',
    'assets/site.css',
];

foreach ($required as $file) {
    if (!is_file($portal . '/' . $file)) {
        fwrite(STDERR, "Portal file missing: {$file}\n");
        exit(1);
    }
}

$htaccess = (string) file_get_contents($portal . '/.htaccess');
foreach (['^data(?:/|$)', '^storage(?:/|$)', '^lib(?:/|$)'] as $rule) {
    if (!str_contains($htaccess, $rule)) {
        fwrite(STDERR, "Protected portal route missing from .htaccess: {$rule}\n");
        exit(1);
    }
}

$registry = (string) file_get_contents($portal . '/lib/apk_registry.php');
foreach (['EM_APK_MAX_BYTES', 'is_uploaded_file', 'FILEINFO_MIME_TYPE', 'PK\\x03\\x04', 'basename($file)'] as $needle) {
    if (!str_contains($registry, $needle)) {
        fwrite(STDERR, "APK registry hardening contract missing: {$needle}\n");
        exit(1);
    }
}

$admin = (string) file_get_contents($portal . '/admin-apks.php');
foreach (['EVENTMENU_APK_ADMIN_TOKEN', 'hash_equals', 'session_regenerate_id', 'apk_csrf'] as $needle) {
    if (!str_contains($admin, $needle)) {
        fwrite(STDERR, "Admin security contract missing: {$needle}\n");
        exit(1);
    }
}

$publisher = (string) file_get_contents($portal . '/api-apk-publish.php');
foreach (['EVENTMENU_APK_PUBLISH_TOKEN', 'hash_equals', 'Cache-Control', "'publish_failed'"] as $needle) {
    if (!str_contains($publisher, $needle)) {
        fwrite(STDERR, "Publisher security contract missing: {$needle}\n");
        exit(1);
    }
}
$leakNeedle = "'message' => \$e->getMessage()";
if (str_contains($publisher, $leakNeedle)) {
    fwrite(STDERR, "Publisher must not expose internal exception messages.\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($portal . '/data/apps.json'), true);
if (!is_array($manifest) || !isset($manifest['apps']['eventmenu-go'], $manifest['apps']['delyvre'])) {
    fwrite(STDERR, "Portal app manifest is invalid.\n");
    exit(1);
}

fwrite(STDOUT, "Portal root contracts OK.\n");
