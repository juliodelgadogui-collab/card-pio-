<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class ProviderRefundService
{
    public function refund(array $payment, string $idempotencyKey): array
    {
        $provider = strtolower((string)($payment['provider'] ?? ''));
        if ($provider === 'manual') {
            return ['completed' => true, 'provider_refund_id' => 'CASH-REFUND-' . strtoupper(bin2hex(random_bytes(8))), 'payload' => ['status' => 'completed']];
        }

        $gateway = $this->gateway((int)$payment['tenant_id'], $provider);
        $config = Crypto::decryptJson($gateway['config_encrypted']);
        return match ($provider) {
            'stripe' => $this->refundStripe($gateway, $config, $payment, $idempotencyKey),
            'pagbank' => $this->refundPagBank($gateway, $config, $payment, $idempotencyKey),
            'mercadopago' => $this->refundMercadoPago($gateway, $config, $payment, $idempotencyKey),
            'efi','inter' => (new BankPixRefundService())->request($provider,$config,$payment,$idempotencyKey),
            default => throw new RuntimeException('Provedor não suporta estorno.'),
        };
    }

    public function check(array $refund, array $payment): array
    {
        $provider = strtolower((string)$payment['provider']);
        if ($provider === 'manual') return ['completed' => true, 'payload' => ['status' => 'completed']];
        $gateway = $this->gateway((int)$payment['tenant_id'], $provider);
        $config = Crypto::decryptJson($gateway['config_encrypted']);
        return match ($provider) {
            'stripe' => $this->checkStripe($gateway, $config, $refund, $payment),
            'pagbank' => $this->checkPagBank($config, $refund, $payment),
            'mercadopago' => $this->checkMercadoPago($config, $refund, $payment),
            'efi','inter' => (new BankPixRefundService())->check($provider,$config,$refund,$payment),
            default => throw new RuntimeException('Provedor não suporta consulta de estorno.'),
        };
    }

    private function gateway(int $tenantId, string $provider): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider=? LIMIT 1');
        $stmt->execute([$tenantId, $provider]);
        $gateway = $stmt->fetch();
        if (!$gateway) throw new RuntimeException('Configuração do gateway não encontrada para o estorno.');
        return $gateway;
    }

    private function refundStripe(array $gateway, array $config, array $payment, string $idempotencyKey): array
    {
        if (!class_exists('Stripe\\StripeClient')) throw new RuntimeException('Stripe SDK não instalado.');
        $key = (string)($config['secret_key'] ?? '');
        if ($key === '') throw new RuntimeException('Chave Stripe ausente.');
        $client = new \Stripe\StripeClient($key);
        $account = $client->accounts->retrieve();
        if ((string)$account->id !== (string)$gateway['account_reference']) throw new RuntimeException('Conta Stripe divergente no estorno.');
        $pi = $client->paymentIntents->retrieve((string)$payment['provider_payment_id'], []);
        if ((int)($pi->metadata->tenant_id ?? 0) !== (int)$payment['tenant_id'] || (int)($pi->metadata->order_id ?? 0) !== (int)$payment['order_id']) {
            throw new RuntimeException('Metadados Stripe divergentes no estorno.');
        }
        if (strtoupper((string)$pi->currency) !== 'BRL' || (int)($pi->amount_received ?: $pi->amount) !== (int)$payment['amount_cents']) {
            throw new RuntimeException('Valor ou moeda Stripe divergente no estorno.');
        }
        $refund = $client->refunds->create(
            ['payment_intent' => (string)$pi->id, 'reason' => 'requested_by_customer'],
            ['idempotency_key' => $idempotencyKey]
        );
        $status = strtolower((string)$refund->status);
        return [
            'completed' => $status === 'succeeded',
            'provider_refund_id' => (string)$refund->id,
            'payload' => json_decode(json_encode($refund, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    private function checkStripe(array $gateway, array $config, array $refund, array $payment): array
    {
        if (!class_exists('Stripe\\StripeClient')) throw new RuntimeException('Stripe SDK não instalado.');
        $key = (string)($config['secret_key'] ?? '');
        if ($key === '') throw new RuntimeException('Chave Stripe ausente.');
        $client = new \Stripe\StripeClient($key);
        $account = $client->accounts->retrieve();
        if ((string)$account->id !== (string)$gateway['account_reference']) throw new RuntimeException('Conta Stripe divergente.');
        $object = $client->refunds->retrieve((string)$refund['provider_refund_id'], []);
        if ((string)($object->payment_intent ?? '') !== (string)$payment['provider_payment_id'] || (int)$object->amount !== (int)$refund['amount_cents']) {
            throw new RuntimeException('Reembolso Stripe divergente.');
        }
        return ['completed' => strtolower((string)$object->status) === 'succeeded', 'payload' => json_decode(json_encode($object, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)];
    }

    private function refundPagBank(array $gateway, array $config, array $payment, string $idempotencyKey): array
    {
        $token = (string)($config['token'] ?? '');
        if ($token === '') throw new RuntimeException('Token PagBank ausente.');
        $base = rtrim((string)($config['api_base'] ?? 'https://api.pagseguro.com'), '/');
        $chargeId = (string)$payment['provider_payment_id'];
        $charge = $this->httpJson('GET', $base . '/charges/' . rawurlencode($chargeId), ['Authorization: Bearer ' . $token]);
        if ((string)($charge['id'] ?? '') !== $chargeId) throw new RuntimeException('Cobrança PagBank divergente.');
        $currency = strtoupper((string)($charge['amount']['currency'] ?? 'BRL'));
        $paid = (int)($charge['amount']['summary']['paid'] ?? $charge['amount']['value'] ?? 0);
        if ($currency !== 'BRL' || $paid < (int)$payment['amount_cents']) throw new RuntimeException('Valor ou moeda PagBank divergente.');
        $response = $this->httpJson('POST', $base . '/charges/' . rawurlencode($chargeId) . '/cancel', [
            'Authorization: Bearer ' . $token,
            'x-idempotency-key: ' . substr(hash('sha256', $idempotencyKey), 0, 64),
        ], ['amount' => ['value' => (int)$payment['amount_cents']]]);
        $refunded = (int)($response['amount']['summary']['refunded'] ?? 0);
        return ['completed' => $refunded >= (int)$payment['amount_cents'], 'provider_refund_id' => $chargeId, 'payload' => $response];
    }

    private function checkPagBank(array $config, array $refund, array $payment): array
    {
        $token = (string)($config['token'] ?? '');
        $base = rtrim((string)($config['api_base'] ?? 'https://api.pagseguro.com'), '/');
        $charge = $this->httpJson('GET', $base . '/charges/' . rawurlencode((string)$payment['provider_payment_id']), ['Authorization: Bearer ' . $token]);
        $refunded = (int)($charge['amount']['summary']['refunded'] ?? 0);
        return ['completed' => $refunded >= (int)$refund['amount_cents'], 'payload' => $charge];
    }

    private function refundMercadoPago(array $gateway, array $config, array $payment, string $idempotencyKey): array
    {
        $token = (string)($config['access_token'] ?? '');
        if ($token === '') throw new RuntimeException('Access token Mercado Pago ausente.');
        $paymentId = (string)$payment['provider_payment_id'];
        $remote = $this->httpJson('GET', 'https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId), ['Authorization: Bearer ' . $token]);
        [$tenantId, $orderId] = $this->parseReference((string)($remote['external_reference'] ?? ''));
        if ($tenantId !== (int)$payment['tenant_id'] || $orderId !== (int)$payment['order_id']) throw new RuntimeException('Referência Mercado Pago divergente.');
        if ((string)($remote['collector_id'] ?? '') !== (string)$gateway['account_reference']) throw new RuntimeException('Conta Mercado Pago divergente.');
        if (strtoupper((string)($remote['currency_id'] ?? 'BRL')) !== 'BRL' || (int)round(((float)($remote['transaction_amount'] ?? 0)) * 100) !== (int)$payment['amount_cents']) {
            throw new RuntimeException('Valor ou moeda Mercado Pago divergente.');
        }
        $response = $this->httpJson('POST', 'https://api.mercadopago.com/v1/payments/' . rawurlencode($paymentId) . '/refunds', [
            'Authorization: Bearer ' . $token,
            'X-Idempotency-Key: ' . $idempotencyKey,
        ]);
        if ((string)($response['payment_id'] ?? $paymentId) !== $paymentId) throw new RuntimeException('Reembolso Mercado Pago divergente.');
        $amount = (int)round(((float)($response['amount'] ?? 0)) * 100);
        return ['completed' => $amount === (int)$payment['amount_cents'], 'provider_refund_id' => (string)($response['id'] ?? ''), 'payload' => $response];
    }

    private function checkMercadoPago(array $config, array $refund, array $payment): array
    {
        $token = (string)($config['access_token'] ?? '');
        if ($token === '') throw new RuntimeException('Access token Mercado Pago ausente.');
        $response = $this->httpJson('GET', 'https://api.mercadopago.com/v1/payments/' . rawurlencode((string)$payment['provider_payment_id']) . '/refunds/' . rawurlencode((string)$refund['provider_refund_id']), ['Authorization: Bearer ' . $token]);
        $amount = (int)round(((float)($response['amount'] ?? 0)) * 100);
        return ['completed' => $amount === (int)$refund['amount_cents'], 'payload' => $response];
    }

    private function parseReference(string $reference): array
    {
        if (!preg_match('/^eventmenu:(\d+):(\d+)$/', $reference, $m)) throw new RuntimeException('Referência do pagamento inválida.');
        return [(int)$m[1], (int)$m[2]];
    }

    private function httpJson(string $method, string $url, array $headers = [], ?array $body = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Falha ao iniciar comunicação com o provedor.');
        $headers[] = 'Accept: application/json';
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 30]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $status < 200 || $status >= 300) throw new RuntimeException('Provedor recusou o estorno (HTTP ' . $status . ')' . ($error ? ' - ' . $error : ''));
        $data = json_decode((string)$response, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Resposta inválida do provedor de estorno.');
        return $data;
    }
}
