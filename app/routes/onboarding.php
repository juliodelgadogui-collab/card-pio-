<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;
use EventMenu\Services\OnboardingService;

Auth::requirePermission('settings.manage');
$tenantId=em_require_tenant();
$service=new OnboardingService();

if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();
    if((string)($_POST['action']??'')==='complete'){
        $service->complete($tenantId);
        Auth::audit('tenant.onboarding_completed','tenant',(string)$tenantId);
        em_flash('ok','Configuração inicial concluída. Você pode voltar a este checklist quando quiser.');
        em_go('dashboard');
    }
}

$items=$service->checklist($tenantId);
$labels=[
 'company'=>['Empresa e operação','Revise nome, tipo de operação, taxa de entrega e preferências gerais.'],
 'brand'=>['Logo e identidade','Envie logo/capa e deixe a marca pronta para painel, app e cardápio.'],
 'unit'=>['Unidade','Cadastre ou revise o local de operação usado por caixa, cozinha, estoque e pedidos.'],
 'products'=>['Cardápio / produtos','Cadastre os primeiros produtos e categorias para começar a operar.'],
 'payments'=>['Formas de pagamento','Configure Pix/cartão/NFC somente quando tiver as credenciais reais do provedor.'],
 'team'=>['Equipe','Crie usuários e funções para gerente, caixa, cozinha, garçom e demais operadores.'],
 'email'=>['E-mail','Configure SMTP para recuperação de senha e avisos enviados pelo sistema.'],
];
$done=count(array_filter($items,static fn($v)=>!empty($v['done'])));$total=count($items);$percent=$total?round(($done/$total)*100):0;

em_header('Primeiros passos','settings');
?>
<section class="page-hero">
  <div><span class="eyebrow">PRIMEIRO ACESSO</span><h2>Vamos deixar a empresa pronta para operar</h2><p>Siga a ordem abaixo. Você não precisa terminar tudo de uma vez e pode voltar a este checklist depois.</p></div>
  <div class="hero-actions"><span class="status-pill active"><?= (int)$done ?>/<?= (int)$total ?> concluídos</span></div>
</section>
<section class="card" style="margin-bottom:20px">
  <div style="display:flex;justify-content:space-between;gap:14px;align-items:center"><div><strong>Progresso da configuração</strong><p class="muted" style="margin:4px 0 0"><?= (int)$percent ?>% concluído</p></div><strong style="font-size:24px"><?= (int)$percent ?>%</strong></div>
  <div style="height:10px;border-radius:999px;background:#eeeaf6;overflow:hidden;margin-top:14px"><div style="height:100%;width:<?= (int)$percent ?>%;background:linear-gradient(90deg,#5b34d6,#159b63)"></div></div>
</section>
<div class="metric-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
<?php $n=0;foreach($items as$key=>$item):$n++;$meta=$labels[$key];?>
<a class="card" href="<?= Security::e(app_url('?route='.$item['route'])) ?>" style="text-decoration:none;color:inherit;position:relative">
  <div style="display:flex;gap:14px;align-items:flex-start"><span style="width:36px;height:36px;border-radius:12px;display:grid;place-items:center;background:<?= $item['done']?'#e9f8f1':'#eee9fb' ?>;color:<?= $item['done']?'#14734d':'#5b34d6' ?>;font-weight:900"><?= $item['done']?'✓':$n ?></span><div><span class="eyebrow"><?= $item['done']?'CONCLUÍDO':'PENDENTE' ?></span><h3 style="margin:4px 0 6px"><?= Security::e($meta[0]) ?></h3><p class="muted" style="margin:0"><?= Security::e($meta[1]) ?></p></div></div>
  <span class="button secondary compact" style="margin-top:14px"><?= $item['done']?'Revisar':'Configurar' ?></span>
</a>
<?php endforeach;?>
</div>
<section class="card" style="margin-top:20px">
  <div class="section-head"><div><span class="eyebrow">FINALIZAR</span><h2>Entrar no painel normalmente</h2></div></div>
  <p class="muted">Você pode concluir mesmo com itens opcionais pendentes. O EventMenu não vai ativar gateways ou inventar credenciais de pagamento automaticamente.</p>
  <form method="post"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><input type="hidden" name="action" value="complete"><button class="primary" type="submit">Concluir configuração inicial</button></form>
</section>
<?php em_footer(); ?>
