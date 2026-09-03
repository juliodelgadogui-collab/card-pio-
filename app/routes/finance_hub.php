<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

if(!Auth::can('payments.manage')&&!Auth::can('reports.view')){http_response_code(403);exit('Acesso negado.');}
em_require_tenant();
$links=[
 ['payments','Pagamentos','Transações, status, gateways e reconciliação.','payments.manage','💳'],
 ['cash','Caixa','Abertura, fechamento, sangrias, reforços e recebimentos.','payments.manage','💵'],
 ['reports','Relatórios financeiros','Faturamento, canais, produtos e desempenho.','reports.view','📈'],
 ['gateways','Gateways e NFC','Credenciais, webhooks e dispositivos de pagamento.','gateways.manage','📡'],
];
$todayStart=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
$s=$pdo->prepare('SELECT COALESCE(SUM(total_cents),0),COUNT(*) FROM orders WHERE tenant_id=? AND payment_status="paid" AND created_at>=?');$s->execute([Auth::tenantId(),$todayStart]);[$todayRevenue,$todayPaid]=$s->fetch(PDO::FETCH_NUM)?:[0,0];
$p=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND status IN ("created","pending","authorized")');$p->execute([Auth::tenantId()]);$pending=(int)$p->fetchColumn();
em_header('Financeiro','finance-hub');
?>
<style>.finance-metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:11px;margin-bottom:15px}.finance-metric{border-radius:14px;border-top:3px solid #6d4aff}.finance-metric span{color:var(--muted);font-size:11px;font-weight:800}.finance-metric strong{display:block;font-size:25px;margin-top:8px}.finance-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.finance-card{text-decoration:none;color:inherit;border-radius:14px}.finance-card i{font-style:normal;font-size:22px;display:block;margin-bottom:10px}.finance-card h3{margin:0 0 6px}.finance-card p{margin:0;min-height:48px}@media(max-width:1000px){.finance-grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:600px){.finance-metrics{grid-template-columns:1fr 1fr}.finance-metrics .finance-metric:last-child{grid-column:1/-1}.finance-grid{grid-template-columns:1fr}}</style>
<section class="finance-metrics"><article class="card finance-metric"><span>Recebido hoje</span><strong><?= em_money($todayRevenue) ?></strong></article><article class="card finance-metric"><span>Vendas pagas hoje</span><strong><?= (int)$todayPaid ?></strong></article><article class="card finance-metric"><span>Pagamentos em andamento</span><strong><?= $pending ?></strong></article></section><section class="finance-grid"><?php foreach($links as[$route,$title,$desc,$permission,$icon]):if(!Auth::can($permission))continue;?><a class="card finance-card" href="<?= Security::e(em_url('/?route='.$route)) ?>"><i><?= $icon ?></i><h3><?= Security::e($title) ?></h3><p class="muted"><?= Security::e($desc) ?></p></a><?php endforeach;?></section>
<?php em_footer();
