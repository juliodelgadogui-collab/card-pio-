<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalTransmissionService
{
    public function transmit(int $tenantId, int $documentId, FiscalTransmitterInterface $transmitter): array
    {
        if ($tenantId < 1 || $documentId < 1) throw new RuntimeException('Documento fiscal inválido.');

        $claimed = $this->claim($tenantId, $documentId);
        if (($claimed['document']['status'] ?? '') === 'authorized') return $claimed['document'];

        try {
            $result = $transmitter->transmit($claimed['document'], $claimed['snapshot']);
            return $this->applyVerifiedResult($tenantId, $documentId, $result);
        } catch (Throwable $e) {
            $this->recordError($tenantId, $documentId, $e->getMessage());
            throw $e;
        }
    }

    /** @return array{document:array,snapshot:array} */
    private function claim(int $tenantId, int $documentId): array
    {
        return Database::transaction(function (PDO $pdo) use ($tenantId, $documentId): array {
            $q = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$documentId, $tenantId]);
            $document = $q->fetch();
            if (!$document) throw new RuntimeException('Documento fiscal não encontrado.');

            $status = (string)$document['status'];
            if ($status === 'authorized') {
                return ['document' => $this->publicDocument($document), 'snapshot' => []];
            }
            if (in_array($status, ['cancelled', 'rejected', 'contingency'], true)) {
                throw new RuntimeException('O documento fiscal não pode ser transmitido no estado atual.');
            }
            if ($status === 'processing') {
                throw new RuntimeException('O documento fiscal já está em processamento.');
            }
            if (!in_array($status, ['queued', 'error'], true)) {
                throw new RuntimeException('Estado fiscal inválido para transmissão.');
            }

            $snapshot = $this->decryptSnapshot($document);
            // cNF precisa ser estável entre tentativas. Ele é derivado do snapshot imutável,
            // sem alterar o snapshot persistido nem depender de aleatoriedade do adaptador.
            $snapshot['numeric_code'] = $this->numericCode((string)$document['snapshot_hash']);

            $pdo->prepare('UPDATE fiscal_documents SET status="processing",transmission_attempts=transmission_attempts+1,transmission_started_at=CURRENT_TIMESTAMP,last_transmission_at=CURRENT_TIMESTAMP,rejection_code=NULL,rejection_message=NULL,issued_at=COALESCE(issued_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')
                ->execute([$documentId, $tenantId]);
            $document['status'] = 'processing';
            $document['rejection_code'] = null;
            $document['rejection_message'] = null;
            $document['transmission_attempts'] = ((int)($document['transmission_attempts'] ?? 0)) + 1;
            $document['numeric_code'] = $snapshot['numeric_code'];
            return ['document' => $this->publicDocument($document), 'snapshot' => $snapshot];
        });
    }

    private function applyVerifiedResult(int $tenantId, int $documentId, array $result): array
    {
        if (empty($result['verified'])) {
            throw new RuntimeException('O transmissor não comprovou a verificação da resposta da SEFAZ.');
        }

        $status = strtolower(trim((string)($result['status'] ?? '')));
        return match ($status) {
            'authorized' => $this->recordAuthorized($tenantId, $documentId, $result),
            'rejected' => $this->recordRejected($tenantId, $documentId, $result),
            default => throw new RuntimeException('Resposta fiscal verificada, porém com estado não reconhecido.'),
        };
    }

    private function recordAuthorized(int $tenantId, int $documentId, array $result): array
    {
        $accessKey = preg_replace('/\D+/', '', (string)($result['access_key'] ?? '')) ?? '';
        $protocol = mb_substr(trim((string)($result['protocol'] ?? '')), 0, 120);
        $xml = trim((string)($result['xml'] ?? ''));
        if (strlen($accessKey) !== 44) throw new RuntimeException('A SEFAZ não retornou uma chave de acesso válida.');
        if ($protocol === '') throw new RuntimeException('A SEFAZ não retornou protocolo de autorização.');
        if ($xml === '' || strlen($xml) > 4 * 1024 * 1024) throw new RuntimeException('XML autorizado ausente ou inválido.');
        if (!$this->validXml($xml)) throw new RuntimeException('XML fiscal autorizado é inválido.');
        if (!str_contains($xml, $accessKey)) throw new RuntimeException('A chave de acesso não confere com o XML autorizado.');
        $responseEncrypted = Crypto::encrypt($this->responseMetadata($result));

        return Database::transaction(function (PDO $pdo) use ($tenantId, $documentId, $accessKey, $protocol, $xml, $responseEncrypted): array {
            $q = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$documentId, $tenantId]);
            $document = $q->fetch();
            if (!$document) throw new RuntimeException('Documento fiscal não encontrado.');
            if ((string)$document['status'] === 'authorized') return $this->publicDocument($document);
            if ((string)$document['status'] !== 'processing') throw new RuntimeException('Documento fiscal não está em processamento.');

            $dupe = $pdo->prepare('SELECT id FROM fiscal_documents WHERE access_key=? AND id<>? LIMIT 1');
            $dupe->execute([$accessKey, $documentId]);
            if ($dupe->fetchColumn()) throw new RuntimeException('A chave de acesso já pertence a outro documento fiscal.');

            $encrypted = Crypto::encrypt($xml);
            $pdo->prepare('UPDATE fiscal_documents SET status="authorized",access_key=?,protocol=?,xml_encrypted=?,response_encrypted=?,rejection_code=NULL,rejection_message=NULL,authorized_at=CURRENT_TIMESTAMP,last_transmission_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')
                ->execute([$accessKey, $protocol, $encrypted, $responseEncrypted, $documentId, $tenantId]);
            return $this->loadPublic($pdo, $tenantId, $documentId);
        });
    }

    private function recordRejected(int $tenantId, int $documentId, array $result): array
    {
        $code = mb_substr(trim((string)($result['rejection_code'] ?? '')), 0, 32);
        $message = mb_substr(trim((string)($result['rejection_message'] ?? '')), 0, 1000);
        if ($code === '' || $message === '') throw new RuntimeException('Rejeição da SEFAZ sem código ou motivo verificável.');
        $responseEncrypted = Crypto::encrypt($this->responseMetadata($result));

        return Database::transaction(function (PDO $pdo) use ($tenantId, $documentId, $code, $message, $responseEncrypted): array {
            $q = $pdo->prepare(Database::portableSql($pdo, 'SELECT status FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$documentId, $tenantId]);
            $status = $q->fetchColumn();
            if ($status === false) throw new RuntimeException('Documento fiscal não encontrado.');
            if ($status === 'authorized') throw new RuntimeException('Documento autorizado não pode ser convertido em rejeitado.');
            if ($status !== 'processing') throw new RuntimeException('Documento fiscal não está em processamento.');
            $pdo->prepare('UPDATE fiscal_documents SET status="rejected",rejection_code=?,rejection_message=?,response_encrypted=?,last_transmission_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')
                ->execute([$code, $message, $responseEncrypted, $documentId, $tenantId]);
            return $this->loadPublic($pdo, $tenantId, $documentId);
        });
    }

    public function retryError(int $tenantId, int $documentId): array
    {
        return Database::transaction(function (PDO $pdo) use ($tenantId, $documentId): array {
            $q = $pdo->prepare(Database::portableSql($pdo, 'SELECT status FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$documentId, $tenantId]);
            $status = $q->fetchColumn();
            if ($status === false) throw new RuntimeException('Documento fiscal não encontrado.');
            if ($status !== 'error') throw new RuntimeException('Somente erro técnico pode voltar para a fila automaticamente.');
            $pdo->prepare('UPDATE fiscal_documents SET status="queued",transmission_started_at=NULL,rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')
                ->execute([$documentId, $tenantId]);
            return $this->loadPublic($pdo, $tenantId, $documentId);
        });
    }

    private function recordError(int $tenantId, int $documentId, string $message): void
    {
        $safe = mb_substr($this->friendlyError($message), 0, 1000);
        try {
            $pdo = Database::connection();
            $pdo->prepare('UPDATE fiscal_documents SET status="error",rejection_code="TECHNICAL",rejection_message=?,last_transmission_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="processing"')
                ->execute([$safe, $documentId, $tenantId]);
        } catch (Throwable) {
            // A falha original precisa continuar sendo a causa principal para o worker.
        }
    }

    private function decryptSnapshot(array $document): array
    {
        if (empty($document['snapshot_encrypted']) || empty($document['snapshot_hash'])) {
            throw new RuntimeException('Snapshot fiscal não encontrado.');
        }
        $snapshot = Crypto::decryptJson((string)$document['snapshot_encrypted']);
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
        if (!hash_equals((string)$document['snapshot_hash'], hash('sha256', $json))) {
            throw new RuntimeException('Integridade do snapshot fiscal não confere.');
        }
        return $snapshot;
    }

    private function numericCode(string $snapshotHash): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $snapshotHash)) throw new RuntimeException('Hash do snapshot fiscal inválido.');
        $value = hexdec(substr($snapshotHash, 0, 7)) % 100000000;
        return str_pad((string)$value, 8, '0', STR_PAD_LEFT);
    }

    private function responseMetadata(array $result): array
    {
        return [
            'status' => mb_substr((string)($result['status'] ?? ''), 0, 30),
            'verified' => !empty($result['verified']),
            'access_key' => mb_substr((string)($result['access_key'] ?? ''), 0, 64),
            'protocol' => mb_substr((string)($result['protocol'] ?? ''), 0, 120),
            'rejection_code' => mb_substr((string)($result['rejection_code'] ?? ''), 0, 32),
            'rejection_message' => mb_substr((string)($result['rejection_message'] ?? ''), 0, 1000),
            'retryable' => !empty($result['retryable']),
            'received_at' => gmdate('c'),
        ];
    }

    private function validXml(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $parsed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            return $parsed !== false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function loadPublic(PDO $pdo, int $tenantId, int $documentId): array
    {
        $q = $pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');
        $q->execute([$documentId, $tenantId]);
        $row = $q->fetch();
        if (!$row) throw new RuntimeException('Documento fiscal não encontrado.');
        return $this->publicDocument($row);
    }

    private function publicDocument(array $row): array
    {
        unset($row['xml_encrypted'], $row['cancellation_xml_encrypted'], $row['snapshot_encrypted'], $row['response_encrypted']);
        return $row;
    }

    private function friendlyError(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'sqlstate') || str_contains($lower, 'stack trace') || str_contains($lower, 'exception')) {
            return 'Falha técnica durante a transmissão fiscal.';
        }
        return $message !== '' ? $message : 'Falha técnica durante a transmissão fiscal.';
    }
}
