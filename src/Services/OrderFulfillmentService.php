<?php

declare(strict_types=1);

namespace EventMenu\Services;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class OrderFulfillmentService
{
    private const QR_CHANNELS = ['counter','pickup','delivery'];
    private const EPSILON = 0.0005;

    public function supportsChannel(string $channel): bool
    {
        return in_array(strtolower(trim($channel)), self::QR_CHANNELS, true);
    }

    public function qrPayload(string $publicToken): string
    {
        $token = $this->normalizeToken($publicToken);
        return \app_absolute_url('?route=pickup&token='.rawurlencode($token));
    }

    public function qrDataUri(string $publicToken, int $size = 320): string
    {
        $size = max(180, min(700, $size));
        $qr = new QrCode(
            data: $this->qrPayload($publicToken),
            encoding: new Encoding('ISO-8859-1'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );
        return (new SvgWriter())->write($qr)->getDataUri();
    }

    public function normalizeToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') throw new RuntimeException('QR do pedido vazio.');
        if (preg_match('/^[a-f0-9]{40}$/i', $raw)) return strtolower($raw);
        if (preg_match('/EVENTMENU:ORDER:([a-f0-9]{40})/i', $raw, $m)) return strtolower($m[1]);

        $query = (string)(parse_url($raw, PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            parse_str($query, $params);
            foreach (['token','t','order'] as $key) {
                $candidate = trim((string)($params[$key] ?? ''));
                if (preg_match('/^[a-f0-9]{40}$/i', $candidate)) return strtolower($candidate);
            }
        }
        if (preg_match('/(?:token|t|order)=([a-f0-9]{40})/i', $raw, $m)) return strtolower($m[1]);
        throw new RuntimeException('QR de pedido inválido.');
    }

    public function detailsByToken(string $rawToken): array
    {
        $tenantId = Auth::tenantId();
        if (!$tenantId) throw new RuntimeException('Empresa não selecionada.');
        $pdo = Database::connection();
        $order = $this->fetchOrderByToken($pdo, $tenantId, $this->normalizeToken($rawToken), false);
        $this->assertCanView($order);
        return $this->composeDetails($pdo, $order);
    }

    public function publicProgress(int $tenantId, int $orderId): array
    {
        if ($tenantId < 1 || $orderId < 1) return $this->emptyProgress();
        $pdo = Database::connection();
        $items = $this->fetchItems($pdo, $orderId);
        return $this->progressFromItems($items);
    }

    public function fulfill(string $rawToken, array $quantities, string $batchKey = '', string $notes = ''): array
    {
        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        if (!$tenantId || !$userId) throw new RuntimeException('Sessão inválida.');
        $token = $this->normalizeToken($rawToken);
        $batchKey = preg_replace('/[^A-Za-z0-9:_-]/', '', trim($batchKey)) ?: bin2hex(random_bytes(16));
        $batchKey = mb_substr($batchKey, 0, 80);
        $notes = mb_substr(trim($notes), 0, 500);

        $requested = [];
        foreach ($quantities as $itemId => $rawQty) {
            $id = (int)$itemId;
            $qty = round((float)str_replace(',', '.', (string)$rawQty), 3);
            if ($id > 0 && is_finite($qty) && $qty > self::EPSILON) $requested[$id] = $qty;
        }
        if (!$requested) throw new RuntimeException('Informe pelo menos um item retirado.');

        return Database::transaction(function (PDO $pdo) use ($tenantId, $userId, $token, $batchKey, $notes, $requested): array {
            $order = $this->fetchOrderByToken($pdo, $tenantId, $token, true);
            $this->assertCanFulfill($order);
            if (!$this->supportsChannel((string)$order['channel'])) throw new RuntimeException('Pedido de mesa/comanda não usa retirada por QR.');
            if ((string)$order['status'] === 'cancelled') throw new RuntimeException('Pedido cancelado não pode ter itens retirados.');

            $before = $this->composeDetails($pdo, $order);
            if ((string)$order['status'] === 'completed') {
                if (($before['progress']['status'] ?? '') === 'fulfilled') return $before;
                throw new RuntimeException('Pedido encerrado possui itens pendentes. Solicite revisão de um gerente.');
            }
            if ((int)$order['total_cents'] > 0 && (string)$order['payment_status'] !== 'paid') {
                throw new RuntimeException('Pagamento ainda não confirmado. Receba o pedido antes de liberar produtos.');
            }

            $dup = $pdo->prepare('SELECT id FROM order_item_fulfillments WHERE tenant_id=? AND batch_key=? LIMIT 1');
            $dup->execute([$tenantId, $batchKey]);
            if ($dup->fetchColumn()) return $this->composeDetails($pdo, $order);

            $currentStatus = (string)$order['status'];
            if ($currentStatus === 'pending') {
                $pdo->prepare('UPDATE orders SET status="confirmed" WHERE id=? AND tenant_id=?')->execute([(int)$order['id'], $tenantId]);
                $pdo->prepare('UPDATE stock_reservations SET expires_at=NULL WHERE tenant_id=? AND order_id=? AND status="reserved"')->execute([$tenantId, (int)$order['id']]);
                (new OrderHistoryService())->record($pdo, $tenantId, (int)$order['id'], 'pending', 'confirmed', 'fulfillment', 'Pedido aceito ao iniciar retirada por QR.', $userId);
                $currentStatus = 'confirmed';
                $order['status'] = 'confirmed';
            }

            $insert = $pdo->prepare('INSERT INTO order_item_fulfillments (tenant_id,order_id,order_item_id,quantity,fulfilled_by,source,batch_key,notes) VALUES (?,?,?,?,?,"qr",?,?)');
            foreach ($requested as $itemId => $qty) {
                $itemStmt = $pdo->prepare(Database::portableSql($pdo, 'SELECT id,name_snapshot,quantity FROM order_items WHERE id=? AND order_id=? FOR UPDATE'));
                $itemStmt->execute([$itemId, (int)$order['id']]);
                $item = $itemStmt->fetch();
                if (!$item) throw new RuntimeException('Um item selecionado não pertence a este pedido.');

                $sum = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM order_item_fulfillments WHERE tenant_id=? AND order_item_id=?');
                $sum->execute([$tenantId, $itemId]);
                $already = (float)$sum->fetchColumn();
                $remaining = max(0.0, (float)$item['quantity'] - $already);
                if ($qty - $remaining > self::EPSILON) {
                    throw new RuntimeException('Quantidade de '.$item['name_snapshot'].' maior que o saldo restante ('.$this->formatQty($remaining).').');
                }
                $insert->execute([$tenantId, (int)$order['id'], $itemId, $qty, $userId, $batchKey, $notes ?: null]);
            }

            $details = $this->composeDetails($pdo, $order);
            if (($details['progress']['status'] ?? '') === 'fulfilled' && in_array((string)$order['channel'], ['counter','pickup'], true)) {
                if ($currentStatus !== 'completed') {
                    $pdo->prepare('UPDATE orders SET status="completed" WHERE id=? AND tenant_id=?')->execute([(int)$order['id'], $tenantId]);
                    (new OrderHistoryService())->record($pdo, $tenantId, (int)$order['id'], $currentStatus, 'completed', 'fulfillment', 'Todos os itens foram entregues por retirada QR.', $userId);
                    $details['order']['status'] = 'completed';
                }
            }

            Auth::audit('order.fulfillment', 'order', (string)$order['id'], [
                'batch_key' => $batchKey,
                'items' => $requested,
                'fulfillment_status' => $details['progress']['status'] ?? 'pending',
            ]);
            return $details;
        });
    }

    private function fetchOrderByToken(PDO $pdo, int $tenantId, string $token, bool $lock): array
    {
        $sql = 'SELECT o.*,c.name customer_name,c.phone customer_phone,u.name delivery_name,rt.name table_name,ou.name unit_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN users u ON u.id=o.assigned_delivery_user_id LEFT JOIN restaurant_tables rt ON rt.id=o.table_id LEFT JOIN operating_units ou ON ou.id=o.unit_id WHERE o.tenant_id=? AND o.public_token=? LIMIT 1';
        if ($lock) $sql = Database::portableSql($pdo, str_replace(' LIMIT 1', ' LIMIT 1 FOR UPDATE', $sql));
        $s = $pdo->prepare($sql);
        $s->execute([$tenantId, $token]);
        $order = $s->fetch();
        if (!$order) throw new RuntimeException('Pedido não encontrado para este QR.');
        return $order;
    }

    private function fetchItems(PDO $pdo, int $orderId): array
    {
        $s = $pdo->prepare('SELECT oi.*,COALESCE((SELECT SUM(f.quantity) FROM order_item_fulfillments f WHERE f.order_item_id=oi.id),0) fulfilled_quantity FROM order_items oi WHERE oi.order_id=? ORDER BY oi.id');
        $s->execute([$orderId]);
        $items = $s->fetchAll();
        foreach ($items as &$item) {
            $ordered = (float)$item['quantity'];
            $fulfilled = min($ordered, max(0.0, (float)$item['fulfilled_quantity']));
            $item['ordered_quantity'] = $ordered;
            $item['fulfilled_quantity'] = $fulfilled;
            $item['remaining_quantity'] = max(0.0, $ordered - $fulfilled);
        }
        unset($item);
        return $items;
    }

    private function composeDetails(PDO $pdo, array $order): array
    {
        $items = $this->fetchItems($pdo, (int)$order['id']);
        $historyStmt = $pdo->prepare('SELECT f.id,f.quantity,f.source,f.notes,f.created_at,oi.name_snapshot,u.name fulfilled_by_name FROM order_item_fulfillments f JOIN order_items oi ON oi.id=f.order_item_id LEFT JOIN users u ON u.id=f.fulfilled_by WHERE f.tenant_id=? AND f.order_id=? ORDER BY f.id DESC LIMIT 80');
        $historyStmt->execute([(int)$order['tenant_id'], (int)$order['id']]);
        return [
            'order' => $order,
            'items' => $items,
            'progress' => $this->progressFromItems($items),
            'history' => $historyStmt->fetchAll(),
            'qr_payload' => $this->qrPayload((string)$order['public_token']),
        ];
    }

    private function progressFromItems(array $items): array
    {
        $ordered = 0.0;
        $fulfilled = 0.0;
        foreach ($items as $item) {
            $ordered += (float)($item['ordered_quantity'] ?? $item['quantity'] ?? 0);
            $fulfilled += (float)($item['fulfilled_quantity'] ?? 0);
        }
        $remaining = max(0.0, $ordered - $fulfilled);
        $status = $fulfilled <= self::EPSILON ? 'pending' : ($remaining <= self::EPSILON ? 'fulfilled' : 'partial');
        return [
            'status' => $status,
            'ordered_quantity' => round($ordered, 3),
            'fulfilled_quantity' => round($fulfilled, 3),
            'remaining_quantity' => round($remaining, 3),
        ];
    }

    private function emptyProgress(): array
    {
        return ['status'=>'pending','ordered_quantity'=>0.0,'fulfilled_quantity'=>0.0,'remaining_quantity'=>0.0];
    }

    private function assertCanView(array $order): void
    {
        $allowed = Auth::can('orders.view') || Auth::can('orders.manage') || Auth::can('orders.dispatch') || Auth::can('orders.fulfill');
        if (!$allowed && Auth::can('orders.delivery') && (string)$order['channel'] === 'delivery' && (int)($order['assigned_delivery_user_id'] ?? 0) === (int)Auth::id()) $allowed = true;
        if (!$allowed) throw new RuntimeException('Sua função não pode visualizar esta retirada.');
    }

    private function assertCanFulfill(array $order): void
    {
        if (Auth::can('orders.fulfill')) return;
        if (Auth::can('orders.delivery') && (string)$order['channel'] === 'delivery' && (int)($order['assigned_delivery_user_id'] ?? 0) === (int)Auth::id()) return;
        throw new RuntimeException('Sua função não pode entregar itens deste pedido.');
    }

    private function formatQty(float $qty): string
    {
        if (abs($qty - round($qty)) < self::EPSILON) return (string)(int)round($qty);
        return rtrim(rtrim(number_format($qty, 3, ',', '.'), '0'), ',');
    }
}
