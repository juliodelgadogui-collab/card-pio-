<?php

declare(strict_types=1);

namespace EventMenu\Support;

final class DeploymentInfo
{
    public static function current(): array
    {
        $root = dirname(__DIR__, 2);
        $immutable = self::read($root . '/app/deployment.json');
        $runtime = self::read($root . '/storage/deployment.json');
        $selected = $immutable ?: $runtime;
        $immutableSha=(string)($immutable['commit_sha']??'');$runtimeSha=(string)($runtime['commit_sha']??'');
        $sameBuild=$immutableSha!==''&&$runtimeSha!==''&&hash_equals($immutableSha,$runtimeSha);
        return [
            'commit_sha' => (string)($selected['commit_sha'] ?? 'unknown'),
            'runtime_commit_sha' => $runtimeSha!==''?$runtimeSha:'unknown',
            'branch' => (string)($selected['branch'] ?? 'unknown'),
            'version' => (string)($selected['version'] ?? 'unversioned'),
            'environment' => (string)($selected['environment'] ?? (getenv('APP_ENV') ?: 'production')),
            'built_at' => $selected['built_at'] ?? null,
            'deployed_at' => $sameBuild?($runtime['deployed_at']??null):null,
            'build_id' => (string)($selected['build_id'] ?? ''),
            'metadata_consistent' => $sameBuild,
            'immutable_present' => (bool)$immutable,
            'runtime_present' => (bool)$runtime,
        ];
    }

    private static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) return [];
        $raw = @file_get_contents($path);
        if ($raw === false || trim($raw) === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
