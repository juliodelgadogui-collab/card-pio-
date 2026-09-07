<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\BackgroundJobService;
use EventMenu\Services\BackupService;
use EventMenu\Services\SystemHealthService;

if(!Auth::isSuperAdmin()){http_response_code(403);exit('Acesso restrito ao Super ADM.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$action=(string)($_POST['action']??'');
    try{
        if($action==='backup'){$result=(new BackupService())->run();em_flash('ok','Backup concluído: '.($result['file']??'arquivo criado').'.');}
        elseif($action==='jobs'){$result=(new BackgroundJobService())->runBatch(80,'super-admin');em_flash('ok','Fila processada: '.(int)$result['completed'].' concluída(s), '.(int)$result['retried'].' reagendada(s).');}
        else throw new RuntimeException('Ação inválida.');
    }catch(Throwable$e){em_flash('error','Não foi possível concluir: '.$e->getMessage());}
    em_go('system-health');
}

$health=(new SystemHealthService())->snapshot();
$labels=['database'=>'Banco de dados','cron'=>'Cron / manutenção','queue'=>'Fila assíncrona','push'=>'Notificações push','backup'=>'Backup','gateways'=>'Gateways','webhooks'=>'Webhooks','storage'=>'Armazenamento'];
$stateLabel=['ok'=>'OK','warning'=>'Atenção','error'=>'Erro','disabled'=>'Desativado'];
$overall=$health['overall'];
em_header('Saúde do sistema','system-health');
?>
<section class="page-hero"><div><span class="eyebrow">INFRAESTRUTURA</span><h2>Saúde do EventMenu</h2><p>Banco, cron, fila, push, backups, pagamentos e armazenamento em uma única visão.</p></div><div class="hero-actions"><a class="button secondary" href="<?= Security::e(app_url('?route=system-health')) ?>">Atualizar</a></div></section>
<section class="metric-grid" style="margin-bottom:18px"><div class="metric-card"><span>Estado geral</span><strong><?= Security::e($stateLabel[$overall]??$overall) ?></strong><small><?= Security::e(date('d/m/Y H:i:s')) ?></small></div><div class="metric-card"><span>Ambiente</span><strong><?= Security::e(strtoupper((string)$health['app']['environment'])) ?></strong><small><?= !empty($health['app']['debug'])?'debug ligado':'debug desligado' ?></small></div><div class="metric-card"><span>Versão mínima GO</span><strong><?= Security::e((string)$health['app']['min_app_version']) ?></strong><small>compatibilidade do aplicativo</small></div></section>
<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px">
<?php foreach($health['checks']as$key=>$check):$state=(string)$check['state'];?>
<section class="card"><div class="section-head"><div><span class="eyebrow"><?= Security::e(strtoupper($labels[$key]??$key)) ?></span><h2 style="margin-top:5px"><?= Security::e($stateLabel[$state]??$state) ?></h2></div><span class="status-pill <?= $state==='ok'?'active':($state==='error'?'cancelled':'pending') ?>"><?= Security::e($stateLabel[$state]??$state) ?></span></div><p><?= Security::e((string)$check['message']) ?></p><?php if(!empty($check['details'])):?><details><summary>Detalhes</summary><pre style="white-space:pre-wrap;font-size:11px;overflow:auto"><?= Security::e(json_encode($check['details'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details><?php endif;?></section>
<?php endforeach;?>
</div>
<section class="card" style="margin-top:18px"><div class="section-head"><div><span class="eyebrow">MANUTENÇÃO</span><h2>Ações seguras</h2><p class="muted">O cron deve executar a cada minuto. Backup diário e notificações pendentes são processados pela fila.</p></div></div><div class="actions"><form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="jobs"><button class="primary">Processar fila agora</button></form><form method="post" onsubmit="return confirm('Executar um backup agora?')"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="backup"><button class="secondary">Executar backup agora</button></form></div></section>
<?php em_footer();
