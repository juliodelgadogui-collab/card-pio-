<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

if(!Auth::check()){http_response_code(403);exit('Acesso negado.');}
$role=Auth::role();
$cards=[
 ['Primeiros passos','Configure sua empresa, unidade, equipe e módulos antes de iniciar a operação.','settings-hub','settings.manage'],
 ['Cardápio Digital','Personalize capa, logo e categorias. Depois cadastre produtos, adicionais e combos.','menu-editor','catalog.manage'],
 ['PDV / Balcão','Registre vendas presenciais. Caixa pode receber; balconista registra sem confirmar pagamento.','pos','orders.create'],
 ['Pedidos','Acompanhe novos pedidos, preparo, entrega e conclusão em uma única fila.','orders','orders.view'],
 ['Mesas e comandas','Crie mesas, abra comandas, gere QR e acompanhe chamadas de garçom.','restaurant','tables.manage'],
 ['Cozinha / KDS','Veja somente pedidos liberados para preparo e marque como pronto.','kitchen','orders.kitchen'],
 ['Delivery','Atribua pedidos aos motoboys; cada entregador vê somente as próprias entregas.','delivery','delivery.assign'],
 ['Eventos e ingressos','Crie eventos, tipos de ingresso, venda online, QR e check-in.','events','events.manage'],
 ['Financeiro','Consulte pagamentos e reconciliação. Pagamentos online só confirmam após validação do provedor.','payments','payments.manage'],
 ['Relatórios','Acompanhe vendas, canais, produtos, eventos e desempenho da operação.','reports','reports.any'],
];
em_header('Ajuda','help');
?>
<style>.help-hero{background:linear-gradient(135deg,#201530,#6d4aff);color:#fff;border:0;border-radius:18px;padding:28px;margin-bottom:16px}.help-hero h2{color:#fff;margin:0 0 7px;font-size:26px}.help-hero p{color:#ddd3ff;margin:0;max-width:700px}.help-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.help-card{border-radius:14px}.help-card h3{margin:0 0 7px}.help-card p{min-height:54px}.help-security{margin-top:16px;border-left:4px solid #6d4aff}@media(max-width:980px){.help-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.help-grid{grid-template-columns:1fr}.help-card p{min-height:0}.help-hero{padding:21px}}</style>
<section class="help-hero"><h2>Central de Ajuda EventMenu</h2><p>Atalhos rápidos para operar o sistema no dia a dia. Cada funcionário continua vendo apenas os recursos permitidos para sua função.</p></section><section class="help-grid"><?php foreach($cards as[$title,$description,$route,$permission]):$allowed=$permission==='reports.any'?(Auth::can('reports.view')||Auth::can('reports.own')):Auth::can($permission);if(!$allowed)continue;?><a class="card help-card" href="<?= Security::e(em_url('/?route='.$route)) ?>"><h3><?= Security::e($title) ?></h3><p class="muted"><?= Security::e($description) ?></p><span class="button secondary">Abrir</span></a><?php endforeach;?></section><section class="card help-security"><h2>Regras importantes de segurança</h2><p class="muted">Garçom, balconista e motoboy não confirmam pagamentos. Motoboy só acessa entregas atribuídas a ele. Pagamentos online são confirmados pelo servidor após validação do gateway, e não apenas pelo retorno do navegador ou aplicativo.</p></section>
<?php em_footer();
