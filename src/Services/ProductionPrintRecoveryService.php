<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class ProductionPrintRecoveryService
{
    private const STALE_SECONDS=300;

    /** @return array{retry:int,failed:int} */
    public function recoverCurrentUnit():array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $unitId=(int)(new OperatingUnitService())->requireCurrent()['id'];
        return $this->recover($tenantId,$unitId);
    }

    /** @return array{retry:int,failed:int} */
    public function recover(int $tenantId,int $unitId):array
    {
        if($tenantId<1||$unitId<1)throw new RuntimeException('Unidade de produção inválida.');
        $staleBefore=gmdate('Y-m-d H:i:s',time()-self::STALE_SECONDS);
        return Database::transaction(function(PDO$pdo)use($tenantId,$unitId,$staleBefore):array{
            $failed=$pdo->prepare('UPDATE production_print_queue SET status="failed",claimed_at=NULL,claimed_device_id=NULL,last_error="Impressão interrompida repetidamente. Verifique a impressora e tente novamente.",next_attempt_at=NULL,failed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND unit_id=? AND status="processing" AND claimed_at IS NOT NULL AND claimed_at<? AND attempts>=5');
            $failed->execute([$tenantId,$unitId,$staleBefore]);

            $retry=$pdo->prepare('UPDATE production_print_queue SET status="error",claimed_at=NULL,claimed_device_id=NULL,last_error="Impressão interrompida antes da confirmação. Nova tentativa liberada.",next_attempt_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND unit_id=? AND status="processing" AND claimed_at IS NOT NULL AND claimed_at<? AND attempts<5');
            $retry->execute([$tenantId,$unitId,$staleBefore]);

            return['retry'=>$retry->rowCount(),'failed'=>$failed->rowCount()];
        });
    }
}
