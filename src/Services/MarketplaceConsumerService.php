<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class MarketplaceConsumerService
{
    public function createOrder(PDO $pdo, string $entryToken, array $payload): array
    {
        $claims = (new MarketplaceEntryTokenService())->consume($pdo, $entryToken);
        $items = $payload['items'] ?? [];
        if (!is_array($items) || !$items || count($items) > 80) throw new RuntimeException('Adicione pelo menos um item ao pedido.');
        $cart = [];
        foreach ($items as $row) {
            if (!is_array($row)) continue;
            $productId = (int)($row['product_id'] ?? 0);
            $qty = round((float)($row['quantity'] ?? $row['qty'] ?? 0), 3);
            if ($productId < 1 || !is_finite($qty) || $qty <= 0 || $qty > 99) continue;
            $optionIds = $row['option_ids'] ?? [];
            if (!is_array($optionIds)) $optionIds = [];
            $optionIds = array_values(array_unique(array_filter(array_map('intval', $optionIds), static fn(int $id): bool => $id > 0)));
            if (count($optionIds) > 60) throw new RuntimeException('Há opções demais em um item do pedido.');
            $cart[] = ['product_id'=>$productId,'qty'=>$qty,'option_ids'=>$optionIds];
        }
        if (!$cart) throw new RuntimeException('Adicione pelo menos um item disponível ao pedido.');

        $name = mb_substr(trim((string)($payload['name'] ?? '')), 0, 160);
        $phone = mb_substr(trim((string)($payload['phone'] ?? '')), 0, 30);
        $address = mb_substr(trim((string)($payload['address'] ?? '')), 0, 1000);
        if ($name === '' || $phone === '' || $address === '') throw new RuntimeException('Informe nome, telefone e endereço para a entrega.');

        $order = (new PublicMenuService())->create(
            (int)$claims['tenant_id'],
            $cart,
            $name,
            $phone,
            $address,
            null,
            'delivery',
            null,
            MarketplaceCommissionService::ORDER_SOURCE,
            isset($claims['campaign']) ? (string)$claims['campaign'] : null,
            (int)$claims['unit_id']
        );
        return $this->publicOrderByToken($pdo, (string)$order['public_token']);
    }

    public function publicOrderByToken(PDO $pdo, string $publicToken): array
    {
        $publicToken = strtolower(trim($publicToken));
        if (!preg_match('/^[a-f0-9]{40}$/', $publicToken)) throw new RuntimeException('Pedido não encontrado.');
        $s = $pdo->prepare('SELECT o.id,o.public_token,o.tenant_id,o.unit_id,o.channel,o.order_source,o.status,o.payment_status,o.subtotal_cents,o.discount_cents,o.delivery_fee_cents,o.total_cents,o.created_at,t.name tenant_name,ou.name unit_name FROM orders o JOIN tenants t ON t.id=o.tenant_id LEFT JOIN operating_units ou ON ou.id=o.unit_id WHERE o.public_token=? AND o.order_source=? AND t.status="active" LIMIT 1');
        $s->execute([$publicToken, MarketplaceCommissionService::ORDER_SOURCE]);
        $order = $s->fetch();
        if (!$order) throw new RuntimeException('Pedido não encontrado.');

        $itemsQ = $pdo->prepare('SELECT oi.id,oi.name_snapshot,oi.unit_price_cents,oi.quantity,oi.total_cents FROM order_items oi WHERE oi.order_id=? ORDER BY oi.id');
        $itemsQ->execute([(int)$order['id']]);
        $items = $itemsQ->fetchAll();
        $modifierMap = [];
        try {
            $m = $pdo->prepare('SELECT order_item_id,group_name_snapshot,option_name_snapshot,unit_price_delta_cents,total_delta_cents FROM order_item_modifiers WHERE tenant_id=? AND order_id=? ORDER BY id');
            $m->execute([(int)$order['tenant_id'], (int)$order['id']]);
            foreach ($m->fetchAll() as $row) $modifierMap[(int)$row['order_item_id']][] = [
                'group'=>(string)$row['group_name_snapshot'],
                'name'=>(string)$row['option_name_snapshot'],
                'price_delta_cents'=>(int)$row['unit_price_delta_cents'],
            ];
        } catch (\Throwable) {}
        foreach ($items as &$item) {
            $item['id'] = (int)$item['id'];
            $item['unit_price_cents'] = (int)$item['unit_price_cents'];
            $item['quantity'] = (float)$item['quantity'];
            $item['total_cents'] = (int)$item['total_cents'];
            $item['modifiers'] = $modifierMap[(int)$item['id']] ?? [];
        }
        unset($item);

        $tracking = null;
        if ((string)$order['channel'] === 'delivery') {
            try {
                $existing = (new DeliveryPublicTrackingService())->existingForPublicOrder((int)$order['tenant_id'], (int)$order['id'], $publicToken);
                if ($existing) {
                    $query = (string)(parse_url((string)$existing['url'], PHP_URL_QUERY) ?? '');
                    parse_str($query, $params);
                    $trackingToken = trim((string)($params['token'] ?? ''));
                    if ($trackingToken !== '') $tracking = ['active'=>true,'token'=>$trackingToken,'expires_at'=>$existing['expires_at']];
                }
            } catch (\Throwable) {}
        }

        return [
            'order_number'=>(int)$order['id'],
            'public_token'=>$publicToken,
            'store_name'=>(string)$order['tenant_name'],
            'unit_name'=>(string)($order['unit_name'] ?? ''),
            'status'=>(string)$order['status'],
            'status_label'=>$this->orderStatusLabel((string)$order['status']),
            'payment_status'=>(string)$order['payment_status'],
            'payment_status_label'=>$this->paymentStatusLabel((string)$order['payment_status']),
            'subtotal_cents'=>(int)$order['subtotal_cents'],
            'discount_cents'=>(int)$order['discount_cents'],
            'delivery_fee_cents'=>(int)$order['delivery_fee_cents'],
            'total_cents'=>(int)$order['total_cents'],
            'created_at'=>$order['created_at'],
            'timeline'=>$this->timeline((string)$order['status']),
            'items'=>$items,
            'tracking'=>$tracking,
        ];
    }

    public function trackingStatus(string $token): array
    {
        $raw = (new DeliveryPublicTrackingService())->publicStatus($token);
        $location = null;
        if (!empty($raw['tracking_active']) && is_array($raw['location'] ?? null)) {
            $location = [
                'latitude'=>(float)$raw['location']['latitude'],
                'longitude'=>(float)$raw['location']['longitude'],
                'accuracy_m'=>$raw['location']['accuracy_m'] !== null ? (float)$raw['location']['accuracy_m'] : null,
                'recorded_at'=>$raw['location']['recorded_at'],
            ];
        }
        return [
            'tracking_active'=>(bool)$raw['tracking_active'],
            'status'=>(string)$raw['status'],
            'status_label'=>$this->orderStatusLabel((string)$raw['status']),
            'location'=>$location,
            'route_started_at'=>$raw['route_started_at'],
            'arrived_at'=>$raw['arrived_at'],
            'completed_at'=>$raw['completed_at'],
            'expires_at'=>$raw['expires_at'],
        ];
    }

    private function orderStatusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'pending' => 'Pedido recebido',
            'confirmed' => 'Confirmado',
            'preparing' => 'Em preparo',
            'ready' => 'Pronto',
            'out_for_delivery' => 'Saiu para entrega',
            'completed' => 'Entregue',
            'cancelled' => 'Cancelado',
            default => 'Em andamento',
        };
    }

    private function paymentStatusLabel(string $status): string
    {
        return match (strtolower($status)) {
            'paid' => 'Pagamento confirmado',
            'pending','processing','created' => 'Aguardando pagamento',
            'refunded','partially_refunded' => 'Pagamento estornado',
            'failed','cancelled' => 'Pagamento não concluído',
            default => 'Aguardando pagamento',
        };
    }

    private function timeline(string $status): array
    {
        $steps = [
            ['key'=>'pending','label'=>'Pedido recebido'],
            ['key'=>'preparing','label'=>'Em preparo'],
            ['key'=>'ready','label'=>'Pronto'],
            ['key'=>'out_for_delivery','label'=>'Saiu para entrega'],
            ['key'=>'completed','label'=>'Entregue'],
        ];
        $rank = ['pending'=>0,'confirmed'=>1,'preparing'=>1,'ready'=>2,'out_for_delivery'=>3,'completed'=>99,'cancelled'=>-1];
        $current = $rank[strtolower($status)] ?? 0;
        foreach ($steps as $i => &$step) {
            $step['done'] = $status === 'completed' || ($status !== 'cancelled' && $i < $current);
            $step['current'] = $status !== 'cancelled' && $status !== 'completed' && $i === $current;
        }
        unset($step);
        return $steps;
    }
}
