<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class EventOrderPickupService
{
    public function resolve(int $eventId, string $raw): array
    {
        $this->assertAccess();
        $tenantId = Auth::tenantId();
        if (!$tenantId) throw new RuntimeException('Empresa inválida.');
        if ($eventId < 1) throw new RuntimeException('Selecione o evento antes de ler o pedido.');

        $pdo = Database::connection();
        $this->assertEvent($pdo, $tenantId, $eventId);
        $order = $this->loadOrder($pdo, $tenantId, $eventId, $this->normalizeToken($raw), false);
        $this->assertUnit($order);
        return $this->compose($pdo, $order);
    }

    public function deliver(int $eventId, string $raw): array
    {
        $this->assertAccess();
        $tenantId = Auth::tenantId();
        $userId = Auth::id();
        if (!$tenantId || !$userId) throw new RuntimeException('Sessão inválida.');
        if ($eventId < 1) throw new RuntimeException('Selecione o evento antes de entregar o pedido.');
        $token = $this->normalizeToken($raw);

        return Database::transaction(function (PDO $pdo) use ($tenantId, $userId, $eventId, $token): array {
            $this->assertEvent($pdo, $tenantId, $eventId);
            $order = $this->loadOrder($pdo, $tenantId, $eventId, $token, true);
            $this->assertUnit($order);

            $status = (string)$order['status'];
            if ($status === 'completed') return $this->compose($pdo, $order);
            if ($status === 'cancelled') throw new RuntimeException('Este pedido foi cancelado e não pode ser entregue.');
            if ((int)$order['total_cents'] > 0 && (string)$order['payment_status'] !== 'paid') {
                throw new RuntimeException('O pagamento ainda não foi confirmado. Não entregue o pedido.');
            }
            if ($status !== 'ready') {
                throw new RuntimeException('Este pedido ainda não está pronto para retirada.');
            }

            $pdo->prepare('UPDATE orders SET status="completed" WHERE id=? AND tenant_id=? AND status="ready"')
                ->execute([(int)$order['id'], $tenantId]);
            (new OrderHistoryService())->record(
                $pdo,
                $tenantId,
                (int)$order['id'],
                'ready',
                'completed',
                'event_pickup',
                'Pedido do evento entregue após leitura do QR.',
                $userId
            );
            Auth::audit('event.order_picked_up', 'order', (string)$order['id'], [
                'event_id' => $eventId,
                'unit_id' => $order['unit_id'] ?? null,
                'source' => 'eventmenu_go_qr',
            ]);

            $order['status'] = 'completed';
            return $this->compose($pdo, $order);
        });
    }

    private function assertAccess(): void
    {
        if (!Auth::can('events.bar')) throw new RuntimeException('Sua função não possui acesso à retirada de pedidos do evento.');
        $shift = (new WorkShiftService())->current();
        if (!$shift || (string)$shift['mode'] !== 'events') throw new RuntimeException('Use esta função durante um turno no modo Eventos.');
    }

    private function assertEvent(PDO $pdo, int $tenantId, int $eventId): void
    {
        $s = $pdo->prepare('SELECT id,status,bar_enabled FROM events WHERE id=? AND tenant_id=? LIMIT 1');
        $s->execute([$eventId, $tenantId]);
        $event = $s->fetch();
        if (!$event) throw new RuntimeException('Evento não encontrado.');
        if ((string)$event['status'] === 'cancelled') throw new RuntimeException('Este evento foi cancelado.');
        if (!(int)($event['bar_enabled'] ?? 1)) throw new RuntimeException('O bar está desativado para este evento.');
    }

    private function loadOrder(PDO $pdo, int $tenantId, int $eventId, string $token, bool $lock): array
    {
        $sql = 'SELECT o.*,c.name customer_name,c.phone customer_phone,e.name event_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id LEFT JOIN events e ON e.id=o.event_id AND e.tenant_id=o.tenant_id WHERE o.tenant_id=? AND o.event_id=? AND o.public_token=? AND o.channel IN ("bar","event_bar") LIMIT 1';
        if ($lock) $sql = Database::portableSql($pdo, str_replace(' LIMIT 1', ' LIMIT 1 FOR UPDATE', $sql));
        $s = $pdo->prepare($sql);
        $s->execute([$tenantId, $eventId, $token]);
        $order = $s->fetch();
        if (!$order) throw new RuntimeException('Este QR não pertence a um pedido deste evento.');
        return $order;
    }

    private function compose(PDO $pdo, array $order): array
    {
        $items = $pdo->prepare('SELECT id,name_snapshot,quantity,unit_price_cents,total_cents FROM order_items WHERE order_id=? ORDER BY id');
        $items->execute([(int)$order['id']]);
        $rows = $items->fetchAll();
        $status = (string)$order['status'];
        $paid = (int)$order['total_cents'] <= 0 || (string)$order['payment_status'] === 'paid';

        return [
            'id' => (int)$order['id'],
            'event_id' => (int)$order['event_id'],
            'event_name' => (string)($order['event_name'] ?? ''),
            'public_token' => (string)$order['public_token'],
            'status' => $status,
            'payment_status' => (string)$order['payment_status'],
            'total_cents' => (int)$order['total_cents'],
            'customer_name' => trim((string)($order['customer_name'] ?? '')),
            'customer_phone' => trim((string)($order['customer_phone'] ?? '')),
            'already_delivered' => $status === 'completed',
            'can_deliver' => $status === 'ready' && $paid,
            'items' => $rows,
        ];
    }

    private function normalizeToken(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') throw new RuntimeException('QR do pedido vazio.');
        if (preg_match('/^[a-f0-9]{40}$/i', $raw)) return strtolower($raw);
        if (preg_match('/EVENTMENU:ORDER:([a-f0-9]{40})/i', $raw, $m)) return strtolower($m[1]);
        $query = (string)(parse_url($raw, PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            parse_str($query, $params);
            foreach (['token','t','order'] as $key) {
                $value = trim((string)($params[$key] ?? ''));
                if (preg_match('/^[a-f0-9]{40}$/i', $value)) return strtolower($value);
            }
        }
        if (preg_match('/(?:token|t|order)=([a-f0-9]{40})/i', $raw, $m)) return strtolower($m[1]);
        throw new RuntimeException('QR de pedido inválido.');
    }

    private function assertUnit(array $order): void
    {
        $orderUnit = (int)($order['unit_id'] ?? 0);
        if ($orderUnit < 1) return;
        $current = (new OperatingUnitService())->currentId();
        if ($current && $current !== $orderUnit && !Auth::isSuperAdmin()) {
            throw new RuntimeException('Este pedido pertence a outra unidade do evento.');
        }
    }
}
