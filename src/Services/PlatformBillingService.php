<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class PlatformBillingService
{
    public function generate(PDO $pdo, int $tenantId, string $periodStart, string $periodEnd, ?string $dueAt = null): array
    {
        [$start, $end] = $this->period($periodStart, $periodEnd);
        $invoice = $this->ensureInvoice($pdo, $tenantId, $start, $end, $dueAt);
        if (in_array((string)$invoice['status'], ['paid','cancelled'], true)) return $this->detail($pdo, (int)$invoice['id']);

        $this->appendSubscription($pdo, $invoice, $start, $end);
        $this->appendMarketplaceCommissions($pdo, $invoice, $start, $end);
        $this->recompute($pdo, (int)$invoice['id']);
        return $this->detail($pdo, (int)$invoice['id']);
    }

    public function issue(PDO $pdo, int $invoiceId, ?string $dueAt = null): array
    {
        $invoice = $this->lockedInvoice($pdo, $invoiceId);
        if ((string)$invoice['status'] === 'paid') return $this->detail($pdo, $invoiceId);
        if ((string)$invoice['status'] === 'cancelled') throw new RuntimeException('Fatura cancelada não pode ser emitida.');
        $dueAt = $this->normalDateTime($dueAt) ?: ($invoice['due_at'] ?: gmdate('Y-m-d H:i:s', time() + 10 * 86400));
        $this->recompute($pdo, $invoiceId);
        $pdo->prepare('UPDATE platform_invoices SET status="open",issued_at=COALESCE(issued_at,CURRENT_TIMESTAMP),due_at=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$dueAt, $invoiceId]);
        return $this->detail($pdo, $invoiceId);
    }

    public function markPaid(PDO $pdo, int $invoiceId, ?int $paidCents = null): array
    {
        $invoice = $this->lockedInvoice($pdo, $invoiceId);
        if ((string)$invoice['status'] === 'paid') return $this->detail($pdo, $invoiceId);
        if ((string)$invoice['status'] === 'cancelled') throw new RuntimeException('Fatura cancelada não pode ser liquidada.');
        $this->recompute($pdo, $invoiceId);
        $invoice = $this->lockedInvoice($pdo, $invoiceId);
        $total = max(0, (int)$invoice['total_cents']);
        $paid = $paidCents === null ? $total : max(0, $paidCents);
        if ($paid < $total) throw new RuntimeException('O valor informado é menor que o total da fatura.');
        $pdo->prepare('UPDATE platform_invoices SET status="paid",paid_cents=?,paid_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$paid, $invoiceId]);
        $pdo->prepare('UPDATE marketplace_order_commissions SET status="paid",paid_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE invoice_item_id IN (SELECT id FROM platform_invoice_items WHERE invoice_id=?) AND status="invoiced"')->execute([$invoiceId]);
        return $this->detail($pdo, $invoiceId);
    }

    public function addAdjustment(PDO $pdo, int $invoiceId, int $tenantId, string $description, int $amountCents, string $referenceKey): array
    {
        $invoice = $this->lockedInvoice($pdo, $invoiceId);
        if ((int)$invoice['tenant_id'] !== $tenantId) throw new RuntimeException('Fatura pertence a outra empresa.');
        if (in_array((string)$invoice['status'], ['paid','cancelled'], true)) throw new RuntimeException('Esta fatura não aceita novos lançamentos.');
        $description = mb_substr(trim($description), 0, 500);
        $referenceKey = mb_substr(trim($referenceKey), 0, 190);
        if ($description === '' || $referenceKey === '') throw new RuntimeException('Descrição e referência são obrigatórias.');
        $type = $amountCents < 0 ? 'credit_adjustment' : 'other_service';
        $sql = Database::portableSql($pdo, 'INSERT IGNORE INTO platform_invoice_items (invoice_id,tenant_id,item_type,reference_key,description,quantity,unit_amount_cents,amount_cents) VALUES (?,?,?,?,?,1,?,?)');
        $pdo->prepare($sql)->execute([$invoiceId, $tenantId, $type, $referenceKey, $description, $amountCents, $amountCents]);
        $this->recompute($pdo, $invoiceId);
        return $this->detail($pdo, $invoiceId);
    }

    public function createMarketplaceReversalCredit(PDO $pdo, array $commission, string $reason): void
    {
        $commissionId = (int)$commission['id'];
        $tenantId = (int)$commission['tenant_id'];
        $amount = max(0, (int)$commission['commission_cents']);
        if ($amount === 0) return;
        $reference = 'marketplace-reversal:' . $commissionId;
        $exists = $pdo->prepare('SELECT id FROM platform_invoice_items WHERE tenant_id=? AND reference_key=? LIMIT 1');
        $exists->execute([$tenantId, $reference]);
        if ($exists->fetchColumn()) return;

        $invoice = null;
        if (!empty($commission['invoice_item_id'])) {
            $s = $pdo->prepare('SELECT i.* FROM platform_invoices i JOIN platform_invoice_items li ON li.invoice_id=i.id WHERE li.id=? AND i.tenant_id=? LIMIT 1');
            $s->execute([(int)$commission['invoice_item_id'], $tenantId]);
            $candidate = $s->fetch();
            if ($candidate && in_array((string)$candidate['status'], ['draft','open'], true)) $invoice = $candidate;
        }
        if (!$invoice) {
            $now = new DateTimeImmutable('now');
            $start = $now->format('Y-m-01');
            $end = $now->format('Y-m-t');
            $invoice = $this->ensureInvoice($pdo, $tenantId, $start, $end, null);
            if (in_array((string)$invoice['status'], ['paid','cancelled'], true)) {
                $next = $now->modify('first day of next month');
                $invoice = $this->ensureInvoice($pdo, $tenantId, $next->format('Y-m-01'), $next->format('Y-m-t'), null);
            }
        }
        $description = 'Crédito por estorno do pedido #' . (int)$commission['order_id'];
        $snapshot = json_encode(['marketplace_commission_id'=>$commissionId,'reason'=>mb_substr(trim($reason),0,500)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = Database::portableSql($pdo, 'INSERT IGNORE INTO platform_invoice_items (invoice_id,tenant_id,item_type,reference_key,order_id,description,quantity,unit_amount_cents,amount_cents,snapshot_json) VALUES (?,?,?,?,?, ?,1,?,?,?)');
        $pdo->prepare($sql)->execute([(int)$invoice['id'],$tenantId,'credit_adjustment',$reference,(int)$commission['order_id'],$description,-$amount,-$amount,$snapshot]);
        $this->recompute($pdo, (int)$invoice['id']);
    }

    public function detail(PDO $pdo, int $invoiceId): array
    {
        $s = $pdo->prepare('SELECT i.*,t.name tenant_name FROM platform_invoices i JOIN tenants t ON t.id=i.tenant_id WHERE i.id=? LIMIT 1');
        $s->execute([$invoiceId]);
        $invoice = $s->fetch();
        if (!$invoice) throw new RuntimeException('Fatura não encontrada.');
        $items = $pdo->prepare('SELECT li.*,mc.calculation_base_cents,mc.commission_bps,mc.commission_cents FROM platform_invoice_items li LEFT JOIN marketplace_order_commissions mc ON mc.id=li.marketplace_commission_id WHERE li.invoice_id=? ORDER BY li.id');
        $items->execute([$invoiceId]);
        $invoice['items'] = $items->fetchAll();
        return $invoice;
    }

    private function ensureInvoice(PDO $pdo, int $tenantId, string $start, string $end, ?string $dueAt): array
    {
        $tenant = $pdo->prepare('SELECT id FROM tenants WHERE id=? LIMIT 1');
        $tenant->execute([$tenantId]);
        if (!$tenant->fetchColumn()) throw new RuntimeException('Empresa não encontrada.');
        $s = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM platform_invoices WHERE tenant_id=? AND period_start=? AND period_end=? FOR UPDATE'));
        $s->execute([$tenantId, $start, $end]);
        if ($row = $s->fetch()) return $row;
        $dueAt = $this->normalDateTime($dueAt);
        $pdo->prepare('INSERT INTO platform_invoices (tenant_id,period_start,period_end,due_at,status) VALUES (?,?,?, ?,"draft")')->execute([$tenantId,$start,$end,$dueAt]);
        return $this->lockedInvoice($pdo, (int)$pdo->lastInsertId());
    }

    private function appendSubscription(PDO $pdo, array $invoice, string $start, string $end): void
    {
        $s = $pdo->prepare('SELECT ts.*,p.code plan_code,p.name plan_name,p.monthly_cents,p.yearly_cents FROM tenant_subscriptions ts JOIN saas_plans p ON p.id=ts.plan_id WHERE ts.tenant_id=? AND ts.status IN ("trial","active","past_due") LIMIT 1');
        $s->execute([(int)$invoice['tenant_id']]);
        $sub = $s->fetch();
        if (!$sub) return;
        $charge = 0;
        $cycle = (string)$sub['billing_cycle'];
        if ($cycle === 'monthly') $charge = (int)($sub['custom_price_cents'] ?? $sub['monthly_cents']);
        elseif ($cycle === 'custom') $charge = (int)($sub['custom_price_cents'] ?? $sub['monthly_cents']);
        elseif ($cycle === 'yearly' && $this->annualChargeFallsInPeriod((string)$sub['starts_at'], $start, $end)) $charge = (int)($sub['custom_price_cents'] ?? $sub['yearly_cents']);
        if ($charge <= 0) return;
        $reference = 'subscription:' . (int)$sub['id'] . ':' . $start . ':' . $end;
        $description = 'Mensalidade EventMenu — ' . (string)$sub['plan_name'];
        if ($cycle === 'yearly') $description = 'Plano anual EventMenu — ' . (string)$sub['plan_name'];
        $snapshot = json_encode(['subscription_id'=>(int)$sub['id'],'plan_id'=>(int)$sub['plan_id'],'plan_code'=>$sub['plan_code'],'billing_cycle'=>$cycle], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sql = Database::portableSql($pdo, 'INSERT IGNORE INTO platform_invoice_items (invoice_id,tenant_id,item_type,reference_key,description,quantity,unit_amount_cents,amount_cents,snapshot_json) VALUES (?,?,?,?,?,1,?,?,?)');
        $pdo->prepare($sql)->execute([(int)$invoice['id'],(int)$invoice['tenant_id'],'subscription',$reference,$description,$charge,$charge,$snapshot]);
    }

    private function appendMarketplaceCommissions(PDO $pdo, array $invoice, string $start, string $end): void
    {
        $q = $pdo->prepare('SELECT mc.* FROM marketplace_order_commissions mc WHERE mc.tenant_id=? AND mc.status="due" AND date(COALESCE(mc.due_at,mc.created_at)) BETWEEN ? AND ? ORDER BY mc.id');
        $q->execute([(int)$invoice['tenant_id'],$start,$end]);
        foreach ($q->fetchAll() as $commission) {
            $reference = 'marketplace-commission:' . (int)$commission['id'];
            $description = 'Comissão EventMenu Delivery — Pedido #' . (int)$commission['order_id'];
            $snapshot = json_encode([
                'products_gross_cents'=>(int)$commission['products_gross_cents'],
                'product_discount_cents'=>(int)$commission['product_discount_cents'],
                'calculation_base_cents'=>(int)$commission['calculation_base_cents'],
                'commission_bps'=>(int)$commission['commission_bps'],
                'commission_cents'=>(int)$commission['commission_cents'],
                'rule_snapshot'=>json_decode((string)$commission['rule_snapshot'], true),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sql = Database::portableSql($pdo, 'INSERT IGNORE INTO platform_invoice_items (invoice_id,tenant_id,item_type,reference_key,order_id,marketplace_commission_id,description,quantity,unit_amount_cents,amount_cents,snapshot_json) VALUES (?,?,?,?,?,?,?,1,?,?,?)');
            $insert = $pdo->prepare($sql);
            $insert->execute([(int)$invoice['id'],(int)$invoice['tenant_id'],'marketplace_commission',$reference,(int)$commission['order_id'],(int)$commission['id'],$description,(int)$commission['commission_cents'],(int)$commission['commission_cents'],$snapshot]);
            $item = $pdo->prepare('SELECT id FROM platform_invoice_items WHERE tenant_id=? AND reference_key=? LIMIT 1');
            $item->execute([(int)$invoice['tenant_id'],$reference]);
            $itemId = (int)$item->fetchColumn();
            if ($itemId > 0) $pdo->prepare('UPDATE marketplace_order_commissions SET status="invoiced",invoice_item_id=?,invoiced_at=COALESCE(invoiced_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND status="due"')->execute([$itemId,(int)$commission['id']]);
        }
    }

    private function recompute(PDO $pdo, int $invoiceId): void
    {
        $s = $pdo->prepare('SELECT COALESCE(SUM(CASE WHEN amount_cents>0 THEN amount_cents ELSE 0 END),0) subtotal,COALESCE(SUM(CASE WHEN amount_cents<0 THEN -amount_cents ELSE 0 END),0) credits,COALESCE(SUM(amount_cents),0) total FROM platform_invoice_items WHERE invoice_id=?');
        $s->execute([$invoiceId]);
        $totals = $s->fetch() ?: ['subtotal'=>0,'credits'=>0,'total'=>0];
        $pdo->prepare('UPDATE platform_invoices SET subtotal_cents=?,credits_cents=?,total_cents=?,updated_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$totals['subtotal'],(int)$totals['credits'],max(0,(int)$totals['total']),$invoiceId]);
    }

    private function lockedInvoice(PDO $pdo, int $invoiceId): array
    {
        $s = $pdo->prepare(Database::portableSql($pdo, 'SELECT * FROM platform_invoices WHERE id=? FOR UPDATE'));
        $s->execute([$invoiceId]);
        return $s->fetch() ?: throw new RuntimeException('Fatura não encontrada.');
    }

    private function period(string $start, string $end): array
    {
        $s = DateTimeImmutable::createFromFormat('!Y-m-d', trim($start));
        $e = DateTimeImmutable::createFromFormat('!Y-m-d', trim($end));
        if (!$s || !$e || $s->format('Y-m-d') !== trim($start) || $e->format('Y-m-d') !== trim($end) || $e < $s) throw new RuntimeException('Período da fatura inválido.');
        return [$s->format('Y-m-d'),$e->format('Y-m-d')];
    }

    private function normalDateTime(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        try { return (new DateTimeImmutable($value))->format('Y-m-d H:i:s'); }
        catch (\Throwable) { throw new RuntimeException('Data de vencimento inválida.'); }
    }

    private function annualChargeFallsInPeriod(string $startsAt, string $start, string $end): bool
    {
        try {
            $origin = new DateTimeImmutable($startsAt);
            $periodStart = new DateTimeImmutable($start);
            $periodEnd = new DateTimeImmutable($end);
            $anniversary = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart->format('Y') . '-' . $origin->format('m-d'));
            if (!$anniversary) return false;
            return $anniversary >= $periodStart && $anniversary <= $periodEnd;
        } catch (\Throwable) { return false; }
    }
}
