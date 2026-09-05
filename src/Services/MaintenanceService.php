<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;

final class MaintenanceService
{
    public function run():array
    {
        $pdo=Database::connection();$result=[];
        $result['released_ticket_reservations']=(new TicketService())->releaseExpired();
        $stmt=$pdo->prepare('UPDATE nfc_payment_intents SET status="expired" WHERE status="created" AND expires_at<CURRENT_TIMESTAMP');$stmt->execute();$result['expired_nfc_intents']=$stmt->rowCount();
        $stmt=$pdo->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE revoked_at IS NULL AND expires_at<CURRENT_TIMESTAMP');$stmt->execute();$result['expired_api_tokens']=$stmt->rowCount();
        try{$stmt=$pdo->prepare('UPDATE events SET status="closed" WHERE status="published" AND ends_at IS NOT NULL AND ends_at<CURRENT_TIMESTAMP');$stmt->execute();$result['closed_events']=$stmt->rowCount();}catch(\Throwable){$result['closed_events']=0;}
        try{(new LoginThrottleService())->cleanup();$result['login_throttle_cleanup']=true;}catch(\Throwable){$result['login_throttle_cleanup']=false;}
        try{
            if(Database::isSqlite($pdo))$pdo->exec("DELETE FROM api_tokens WHERE revoked_at IS NOT NULL AND revoked_at < datetime('now','-90 days')");
            else $pdo->exec('DELETE FROM api_tokens WHERE revoked_at IS NOT NULL AND revoked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY)');
            $result['old_api_tokens_removed']=true;
        }catch(\Throwable){$result['old_api_tokens_removed']=false;}
        return $result;
    }
}
