<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\FiscalTransmissionService;
use EventMenu\Services\FiscalTransmitterFactory;

$limit = isset($argv[1]) ? max(1, min(50, (int)$argv[1])) : 10;

if (!FiscalTransmitterFactory::isConfigured()) {
    fwrite(STDOUT, "EventMenu Fiscal: nenhum transmissor SEFAZ configurado; fila preservada.\n");
    exit(0);
}

try {
    $transmitter = FiscalTransmitterFactory::make();
    $pdo = Database::connection();
    $sql = 'SELECT id,tenant_id FROM fiscal_documents WHERE status="queued" ORDER BY id LIMIT '.$limit;
    $documents = $pdo->query($sql)->fetchAll();
    if (!$documents) {
        fwrite(STDOUT, "EventMenu Fiscal: fila vazia.\n");
        exit(0);
    }

    $service = new FiscalTransmissionService();
    $ok = 0;
    $failed = 0;
    foreach ($documents as $document) {
        $id = (int)$document['id'];
        $tenantId = (int)$document['tenant_id'];
        try {
            $result = $service->transmit($tenantId, $id, $transmitter);
            $status = (string)($result['status'] ?? 'unknown');
            fwrite(STDOUT, "Documento {$id}: {$status}\n");
            $ok++;
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $lower = strtolower($message);
            if (str_contains($lower, 'sqlstate') || str_contains($lower, 'stack trace')) $message = 'falha técnica';
            fwrite(STDERR, "Documento {$id}: {$message}\n");
            $failed++;
        }
    }

    fwrite(STDOUT, "EventMenu Fiscal: {$ok} processado(s), {$failed} falha(s).\n");
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    $message = $e->getMessage();
    $lower = strtolower($message);
    if (str_contains($lower, 'sqlstate') || str_contains($lower, 'stack trace')) $message = 'falha técnica ao iniciar o worker';
    fwrite(STDERR, "EventMenu Fiscal: {$message}\n");
    exit(1);
}
