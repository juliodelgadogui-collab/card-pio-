<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;

final class MaintenanceService
{
    public function run():array
    {
        $pdo=Database::connection();$result=[];$runtime=new RuntimeStatusService();
        $result['released_ticket_reservations']=(new TicketService())->releaseExpired();
        $result['released_stock_reservations']=(new StockReservationService())->releaseExpired();

        $stmt=$pdo->prepare('UPDATE nfc_payment_intents SET status="expired" WHERE status="created" AND expires_at<CURRENT_TIMESTAMP');
        $stmt->execute();$result['expired_nfc_intents']=$stmt->rowCount();

        $stmt=$pdo->prepare('UPDATE api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE revoked_at IS NULL AND expires_at<CURRENT_TIMESTAMP');
        $stmt->execute();$result['expired_api_tokens']=$stmt->rowCount();

        try{$stmt=$pdo->prepare('UPDATE api_refresh_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE revoked_at IS NULL AND expires_at<CURRENT_TIMESTAMP');$stmt->execute();$result['expired_api_refresh_tokens']=$stmt->rowCount();}catch(\Throwable){$result['expired_api_refresh_tokens']=0;}
        try{$stmt=$pdo->prepare('UPDATE events SET status="closed" WHERE status="published" AND ends_at IS NOT NULL AND ends_at<CURRENT_TIMESTAMP');$stmt->execute();$result['closed_events']=$stmt->rowCount();}catch(\Throwable){$result['closed_events']=0;}
        try{(new LoginThrottleService())->cleanup();$result['login_throttle_cleanup']=true;}catch(\Throwable){$result['login_throttle_cleanup']=false;}
        try{$result['api_rate_limits_removed']=(new ApiRateLimitService())->cleanup();}catch(\Throwable){$result['api_rate_limits_removed']=0;}
        try{$result['password_reset_tokens_removed']=(new PasswordResetService())->cleanup();}catch(\Throwable){$result['password_reset_tokens_removed']=0;}
        try{$stmt=$pdo->prepare('DELETE FROM app_notifications WHERE expires_at IS NOT NULL AND expires_at<=CURRENT_TIMESTAMP');$stmt->execute();$result['expired_notifications_removed']=$stmt->rowCount();}catch(\Throwable){$result['expired_notifications_removed']=0;}

        try{if(Database::isSqlite($pdo))$pdo->exec("DELETE FROM api_tokens WHERE revoked_at IS NOT NULL AND revoked_at < datetime('now','-90 days')");else$pdo->exec('DELETE FROM api_tokens WHERE revoked_at IS NOT NULL AND revoked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY)');$result['old_api_tokens_removed']=true;}catch(\Throwable){$result['old_api_tokens_removed']=false;}
        try{if(Database::isSqlite($pdo))$pdo->exec("DELETE FROM api_refresh_tokens WHERE revoked_at IS NOT NULL AND revoked_at < datetime('now','-90 days')");else$pdo->exec('DELETE FROM api_refresh_tokens WHERE revoked_at IS NOT NULL AND revoked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 90 DAY)');$result['old_api_refresh_tokens_removed']=true;}catch(\Throwable){$result['old_api_refresh_tokens_removed']=false;}

        try{
            $gpsDays=max(1,min(30,(int)env('DELIVERY_GPS_HISTORY_DAYS',7)));
            $stmt=$pdo->prepare('DELETE FROM delivery_tracking_tokens WHERE expires_at<=CURRENT_TIMESTAMP');$stmt->execute();$result['expired_delivery_tracking_tokens']=$stmt->rowCount();
            if(Database::isSqlite($pdo)){
                $stmt=$pdo->prepare("DELETE FROM delivery_location_history WHERE captured_at < datetime('now', ?)");$stmt->execute(['-'.$gpsDays.' days']);
            }else{
                $stmt=$pdo->prepare('DELETE FROM delivery_location_history WHERE captured_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL '.$gpsDays.' DAY)');$stmt->execute();
            }
            $result['old_delivery_gps_points_removed']=$stmt->rowCount();
            $stmt=$pdo->prepare('DELETE FROM delivery_live_locations WHERE order_id IN (SELECT id FROM orders WHERE status IN ("completed","cancelled"))');$stmt->execute();$result['finished_delivery_live_locations_removed']=$stmt->rowCount();
        }catch(\Throwable){$result['delivery_gps_cleanup']=false;}

        try{$result['customer_confirmations']=(new CustomerCommunicationService())->queueRecentOrderConfirmations();}catch(\Throwable$e){$result['customer_confirmations']=['error'=>mb_substr($e->getMessage(),0,250)];}

        // O principal verifica o nó adicional a cada execução do cron. No servidor
        // de contingência o próprio serviço retorna "skipped", evitando loops.
        try{$result['contingency_probe']=(new PlatformFailoverService())->probeSecondary();}catch(\Throwable$e){$result['contingency_probe']=['status'=>'error','message'=>mb_substr($e->getMessage(),0,250)];}

        try{$jobs=new BackgroundJobService();$jobs->enqueue('backup.daily',[],null,'backup:'.gmdate('Y-m-d'),null,2);$result['jobs']=$jobs->runBatch(max(1,(int)env('QUEUE_BATCH_SIZE',40)),'cron');$result['job_cleanup']=$jobs->purge();}catch(\Throwable$e){$result['jobs']=['error'=>mb_substr($e->getMessage(),0,300)];}
        try{
            $before=gmdate('Y-m-d H:i:s',time()-90*86400);$stmt=$pdo->prepare('DELETE FROM customer_communications WHERE created_at<? AND status IN ("sent","skipped")');$stmt->execute([$before]);$result['old_customer_communications_removed']=$stmt->rowCount();
        }catch(\Throwable){$result['old_customer_communications_removed']=0;}
        $runtime->set('cron.last_run',isset($result['jobs']['error'])?'warning':'ok','Manutenção executada.',['result'=>$result]);
        return$result;
    }
}
