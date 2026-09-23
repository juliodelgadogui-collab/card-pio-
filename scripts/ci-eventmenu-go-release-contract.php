<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$gradle = (string) file_get_contents($root . '/mobile/eventmenu-go/app/build.gradle.kts');
$workflowPath = $root . '/.github/workflows/eventmenu-go-release.yml';

foreach ([
    'EVENTMENU_VERSION_CODE',
    'EVENTMENU_VERSION_NAME',
    'EVENTMENU_RELEASE_STORE_FILE',
    'EVENTMENU_RELEASE_STORE_PASSWORD',
    'EVENTMENU_RELEASE_KEY_ALIAS',
    'EVENTMENU_RELEASE_KEY_PASSWORD',
    'releaseRequested && releaseSigningCount != releaseSigning.size',
    'signingConfig = signingConfigs.getByName("release")',
] as $needle) {
    if (!str_contains($gradle, $needle)) {
        fwrite(STDERR, "EventMenu GO release contract missing: {$needle}\n");
        exit(1);
    }
}

if (!is_file($workflowPath)) {
    fwrite(STDERR, "EventMenu GO release workflow is missing.\n");
    exit(1);
}
$workflow = (string) file_get_contents($workflowPath);
foreach ([
    'eventmenu-go-v*',
    'fetch-depth: 0',
    'git merge-base --is-ancestor',
    'EVENTMENU_GO_RELEASE_KEYSTORE_B64',
    'EVENTMENU_APK_PUBLISH_TOKEN',
    ':app:assembleRelease',
    'apksigner verify',
    '/api/apk/publish',
    'sha256sum',
] as $needle) {
    if (!str_contains($workflow, $needle)) {
        fwrite(STDERR, "EventMenu GO release workflow contract missing: {$needle}\n");
        exit(1);
    }
}

if (str_contains($workflow, ':app:assembleDebug') || str_contains($workflow, 'app-debug.apk')) {
    fwrite(STDERR, "Official release workflow must never publish a debug APK.\n");
    exit(1);
}

fwrite(STDOUT, "EventMenu GO signed release contracts OK.\n");
