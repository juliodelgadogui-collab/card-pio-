<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class PaymentCollectionService
{
    public function register(int $paymentId,string $method,string $source):void
    {
        $tenantId=Auth::tenantId();$userId=Auth::id();if(!$tenantId||!$userId||$paymentId<1)throw new RuntimeException('Contexto de recebimento inválido.');
        $method=strtolower(trim($method));if(!in_array($method,['pix','card'],true))throw new RuntimeException('Método de recebimento inválido.');$source=mb_substr(trim($source),0,60);if($source==='')$source='eventmenu_go';
        $pdo=Database::connection();$p=$pdo->prepare('SELECT id FROM payments WHERE id=? AND tenant_id=? LIMIT 1');$p->execute([$paymentId,$tenantId]);if(!$p->fetchColumn())throw new RuntimeException('Pagamento não encontrado.');
        $shift=(new WorkShiftService())->current();$shiftId=$shift?(int)$shift['id']:null;
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO payment_collection_contexts (tenant_id,payment_id,user_id,work_shift_id,method,source) VALUES (?,?,?,?,?,?)');$pdo->prepare($sql)->execute([$tenantId,$paymentId,$userId,$shiftId,$method,$source]);
    }

    public function recordConfirmedForTenant(int $tenantId,int $paymentId):void
    {
        if($tenantId<1||$paymentId<1)return;$pdo=Database::connection();
        $s=$pdo->prepare('SELECT c.*,p.order_id,p.amount_cents,p.status FROM payment_collection_contexts c JOIN payments p ON p.id=c.payment_id AND p.tenant_id=c.tenant_id WHERE c.tenant_id=? AND c.payment_id=? LIMIT 1');$s->execute([$tenantId,$paymentId]);$context=$s->fetch();if(!$context||$context['status']!=='paid'||empty($context['work_shift_id']))return;
        $key='payment-collection:'.$paymentId.':shift:'.$context['work_shift_id'];
        $sql=Database::portableSql($pdo,'INSERT IGNORE INTO work_shift_movements (tenant_id,work_shift_id,user_id,order_id,payment_id,type,method,direction,amount_cents,notes,idempotency_key) VALUES (?,?,?,?,?,"payment_collection",?,"in",?, ?,?)');
        $pdo->prepare($sql)->execute([$tenantId,$context['work_shift_id'],$context['user_id'],$context['order_id'],$paymentId,$context['method'],$context['amount_cents'],'Recebimento eletrônico confirmado pelo servidor ('.$context['source'].')',$key]);
    }
}
