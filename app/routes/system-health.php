<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use EventMenu\Services\BackgroundJobService;
use EventMenu\Services\BackupService;
use EventMenu\Services\FcmPushService;
use EventMenu\Services\RuntimeStatusService;
use EventMenu\Services\SystemHealthService;

if(!Auth::isSuperAdmin()){http_response_code(403);exit('Acesso restrito ao Super ADM.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='backup'){$result=(new BackupService())->run();em_flash('ok','Backup concluído: '.($result['file']??'arquivo criado').'.');}
        elseif($action==='jobs'){$result=(new BackgroundJobService())->runBatch(80,'super-admin');em_flash('ok','Fila processada: '.(int)$result['completed'].' concluída(s), '.(int)$result['retried'].' reagendada(s).');}
        elseif($action==='push-test'){
            $target=trim((string)($_POST['target']??''));
            if(!preg_match('/^(\d+):(\d+)$/',$target,$m))throw new RuntimeException('Selecione um aparelho válido para o teste.');
            $tenantId=(int)$m[1];$userId=(int)$m[2];if($tenantId<1||$userId<1)throw new RuntimeException('Selecione um aparelho válido para o teste.');
            $pdo=Database::connection();$fcm=new FcmPushService();
            if(!$fcm->configured())throw new RuntimeException('Configure o Firebase no servidor antes de testar o push.');
            $s=$pdo->prepare('SELECT COUNT(*) FROM push_devices pd JOIN users u ON u.id=pd.user_id AND u.tenant_id=pd.tenant_id JOIN tenants t ON t.id=pd.tenant_id WHERE pd.tenant_id=? AND pd.user_id=? AND pd.active=1 AND u.status="active" AND t.status="active"');$s->execute([$tenantId,$userId]);$deviceCount=(int)$s->fetchColumn();
            if($deviceCount<1)throw new RuntimeException('Este usuário não possui aparelho ativo para receber push.');

            $dedupe='system-health:push-test:'.$tenantId.':'.$userId.':'.gmdate('YmdHi');
            $s=$pdo->prepare('SELECT id FROM app_notifications WHERE tenant_id=? AND user_id=? AND dedupe_key=? LIMIT 1');$s->execute([$tenantId,$userId,$dedupe]);
            if($s->fetchColumn())throw new RuntimeException('Aguarde um minuto antes de repetir o teste para este usuário.');

            $expiresAt=gmdate('Y-m-d H:i:s',time()+600);
            $i=$pdo->prepare('INSERT INTO app_notifications (tenant_id,unit_id,user_id,mode,type,priority,title,message,entity_type,entity_id,dedupe_key,expires_at) VALUES (?,NULL,?,NULL,"system.push_test","info","Teste de notificação","Push do EventMenu funcionando neste aparelho.","system","push-test",?,?)');
            $i->execute([$tenantId,$userId,$dedupe,$expiresAt]);$notificationId=(int)$pdo->lastInsertId();if($notificationId<1)throw new RuntimeException('Não foi possível criar a notificação de teste.');

            $runtime=new RuntimeStatusService();
            try{$result=$fcm->sendNotification($notificationId);}
            catch(Throwable $e){try{$runtime->set('push.last_test','error','Teste de push falhou.',['tenant_id'=>$tenantId,'user_id'=>$userId,'notification_id'=>$notificationId]);}catch(Throwable){}throw new RuntimeException('Falha no teste de push. Verifique o Firebase e tente novamente.');}
            $sent=(int)($result['sent']??0);$devices=(int)($result['devices']??$deviceCount);
            if($sent<1){$runtime->set('push.last_test','warning','Teste de push sem entrega.',['tenant_id'=>$tenantId,'user_id'=>$userId,'notification_id'=>$notificationId,'devices'=>$devices,'sent'=>$sent]);throw new RuntimeException('O Firebase respondeu, mas nenhum aparelho recebeu o teste. Abra o EventMenu GO nesse aparelho e tente novamente.');}
            $runtime->set('push.last_test','ok','Teste de push enviado.',['tenant_id'=>$tenantId,'user_id'=>$userId,'notification_id'=>$notificationId,'devices'=>$devices,'sent'=>$sent]);
            em_flash('ok','Notificação de teste enviada para '.$sent.' aparelho(s).');
        }
        else throw new RuntimeException('Ação inválida.');
    }catch(Throwable$e){em_flash('error','Não foi possível concluir: '.$e->getMessage());}
    em_go('system-health');
}

$health=(new SystemHealthService())->snapshot();
$labels=['database'=>'Banco de dados','cron'=>'Cron / manutenção','worker'=>'Worker da fila','queue'=>'Fila assíncrona','push'=>'Notificações push','backup'=>'Backup','gateways'=>'Gateways','webhooks'=>'Webhooks','storage'=>'Armazenamento'];
$stateLabel=['ok'=>'OK','warning'=>'Atenção','error'=>'Erro','disabled'=>'Desativado'];
$overall=$health['overall'];
$pushTargets=[];
try{
    $s=Database::connection()->query('SELECT pd.tenant_id,pd.user_id,t.name tenant_name,u.name user_name,COUNT(*) devices,MAX(pd.last_seen_at) last_seen FROM push_devices pd JOIN users u ON u.id=pd.user_id AND u.tenant_id=pd.tenant_id JOIN tenants t ON t.id=pd.tenant_id WHERE pd.active=1 AND u.status="active" AND t.status="active" GROUP BY pd.tenant_id,pd.user_id,t.name,u.name ORDER BY t.name,u.name LIMIT 200');
    $pushTargets=$s->fetchAll();
}catch(Throwable){}
em_header('Saúde do sistema','system-health');
?>
<section class="page-hero"><div><span class="eyebrow">INFRAESTRUTURA</span><h2>Saúde do EventMenu</h2><p>Banco, cron, worker, fila, push, backups, pagamentos e armazenamento em uma única visão.</p></div><div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=system-health')) ?>">Atualizar</a></div></section>
<section class="metric-grid" style="margin-bottom:18px"><div class="metric-card"><span>Estado geral</span><strong><?= Security::e($stateLabel[$overall]??$overall) ?></strong><small><?= Security::e(date('d/m/Y H:i:s')) ?></small></div><div class="metric-card"><span>Ambiente</span><strong><?= Security::e(strtoupper((string)$health['app']['environment'])) ?></strong><small><?= !empty($health['app']['debug'])?'debug ligado':'debug desligado' ?></small></div><div class="metric-card"><span>Versão mínima GO</span><strong><?= Security::e((string)$health['app']['min_app_version']) ?></strong><small>compatibilidade do aplicativo</small></div></section>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
<?php foreach($health['checks']as$key=>$check):$state=(string)$check['state'];?>
<section class="card"><div class="section-head"><div><span class="eyebrow"><?= Security::e(strtoupper($labels[$key]??$key)) ?></span><h2 style="margin-top:5px"><?= Security::e($stateLabel[$state]??$state) ?></h2></div><span class="status-pill <?= $state==='ok'?'active':($state==='error'?'cancelled':'pending') ?>"><?= Security::e($stateLabel[$state]??$state) ?></span></div><p><?= Security::e((string)$check['message']) ?></p><?php if(!empty($check['details'])):?><details><summary>Detalhes</summary><pre style="white-space:pre-wrap;font-size:11px;overflow:auto"><?= Security::e(json_encode($check['details'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif;?></section>
<?php endforeach;?>
</div>
<section class="card" style="margin-top:18px"><div class="section-head"><div><span class="eyebrow">MANUTENÇÃO</span><h2>Ações seguras</h2><p class="muted">O cron deve executar a cada minuto. Backup diário e notificações pendentes são processados pela fila.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="jobs"><button class="primary">Processar fila agora</button></form><form method="post" onsubmit="return confirm('Executar um backup agora?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="backup"><button class="secondary">Executar backup agora</button></form></div></section>
<section class="card" style="margin-top:18px"><div class="section-head"><div><span class="eyebrow">TESTE DE PUSH</span><h2>Validar notificação em um aparelho</h2><p class="muted">Envia uma única notificação de diagnóstico. Nenhum token, ID do aparelho ou credencial é exibido.</p></div></div><?php if($pushTargets):?><form method="post" class="form-grid" style="align-items:end"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="push-test"><label style="grid-column:span 2">Empresa e usuário<select name="target" required><option value="">Selecione</option><?php foreach($pushTargets as$target):?><option value="<?= (int)$target['tenant_id'] ?>:<?= (int)$target['user_id'] ?>"><?= Security::e((string)$target['tenant_name'].' — '.(string)$target['user_name'].' · '.(int)$target['devices'].' aparelho(s)') ?></option><?php endforeach;?></select></label><div><button class="primary">Enviar teste</button></div></form><?php else:?><p class="muted">Nenhum aparelho ativo registrado no EventMenu GO. Abra o aplicativo, faça login e aguarde o registro do push para testar.</p><?php endif;?></section>
<?php em_footer();
