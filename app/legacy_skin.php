<?php

declare(strict_types=1);

/**
 * Aplica a identidade visual do EventMenu antigo a uma rota operacional
 * sem duplicar sua lógica/segurança. A rota original continua sendo a
 * fonte de verdade para permissões, ações POST e regras de negócio.
 */
function em_render_legacy_route(string $target,string $screen):never
{
    global $pdo;
    ob_start();
    require $target;
    $html=(string)ob_get_clean();
    $css=match($screen){
        'kitchen'=><<<'CSS'
<style>
.content{max-width:none}.topbar{margin-bottom:16px}.grid.delivery-worker-grid,.kds-board{gap:12px}.content>.grid:first-of-type .metric{border-radius:14px;box-shadow:none}.content>.grid:first-of-type .metric strong{font-size:25px}.content section.grid article.card{border-radius:14px;box-shadow:0 5px 20px rgba(30,27,65,.04);padding:16px}.content section.grid article.card h2{font-size:20px}.content section.grid article.card p{line-height:1.45}.content section.grid article.card button.primary{border-radius:11px;background:#6d4aff;box-shadow:none}.content section.grid article.card .alert{border-radius:10px}.content section.grid article.card .badge{border-radius:7px}.content section.grid{grid-template-columns:repeat(3,minmax(260px,1fr))!important}@media(max-width:1150px){.content section.grid{grid-template-columns:repeat(2,minmax(260px,1fr))!important}}@media(max-width:680px){.content section.grid{grid-template-columns:1fr!important}.content section.grid article.card{padding:14px}}
</style>
CSS,
        'delivery'=><<<'CSS'
<style>
.content>.grid:first-of-type .metric{border-radius:14px;box-shadow:none}.delivery-worker-grid{grid-template-columns:repeat(3,minmax(280px,1fr))!important;gap:12px}.delivery-worker-grid article.card{border-radius:14px;box-shadow:0 5px 20px rgba(30,27,65,.04);padding:16px}.delivery-worker-grid article.card .section-head{margin-bottom:10px}.delivery-worker-grid article.card .alert{border-radius:10px;background:#faf9fd}.delivery-worker-grid article.card button.primary{background:#6d4aff;box-shadow:none;border-radius:11px}.delivery-worker-grid article.card a[href^="tel:"]{color:#6d4aff;font-weight:800}.delivery-worker-grid article.card h2{font-size:20px}@media(max-width:1180px){.delivery-worker-grid{grid-template-columns:repeat(2,minmax(280px,1fr))!important}}@media(max-width:680px){.delivery-worker-grid{grid-template-columns:1fr!important}}
</style>
CSS,
        'restaurant'=><<<'CSS'
<style>
.content>.grid{grid-template-columns:280px minmax(0,1fr)!important;align-items:start}.content>.grid>section.card:first-child{position:sticky;top:18px}.content>.grid>section.card:last-child>.grid{grid-template-columns:repeat(4,minmax(190px,1fr))!important;gap:10px}.content>.grid>section.card:last-child article.card{border-radius:13px;box-shadow:none;border:1px solid var(--line);padding:14px}.content>.grid>section.card:last-child article.card h3{font-size:16px}.content>.grid>section.card:last-child article.card .badge{border-radius:7px}.content>.grid>section.card:last-child article.card a{color:#6d4aff;font-weight:800}.content>section.card:first-of-type{border-left:4px solid #f0ad32}.content>section.card:first-of-type article{background:#fffaf0!important}@media(max-width:1250px){.content>.grid>section.card:last-child>.grid{grid-template-columns:repeat(3,minmax(190px,1fr))!important}}@media(max-width:900px){.content>.grid{grid-template-columns:1fr!important}.content>.grid>section.card:first-child{position:static}.content>.grid>section.card:last-child>.grid{grid-template-columns:repeat(2,minmax(170px,1fr))!important}}@media(max-width:520px){.content>.grid>section.card:last-child>.grid{grid-template-columns:1fr!important}}
</style>
CSS,
        'fulfillment'=><<<'CSS'
<style>
.content>section.card{border-radius:14px;box-shadow:0 5px 20px rgba(30,27,65,.035)}.content>section.card:first-of-type{border-left:4px solid #6d4aff}.content>section.card .section-head h2{font-size:18px}.content>section.card .grid article{background:#fff;border-radius:13px!important;box-shadow:none}.content>section.card .grid article h3{font-size:16px;margin-bottom:8px}.content>section.card .grid article p strong{color:#6b6576}.content>section.card .grid article p span{color:#6d4aff;font-weight:950}.content>section.card .grid article form button.primary{background:#6d4aff;box-shadow:none;border-radius:11px}.content .table th{background:#faf9fd}.content .table tr:hover td{background:#fcfbff}.content .badge{border-radius:7px}.content input[name="code"]{font-size:16px;font-weight:750}.content>section.card:first-of-type button.primary{background:#6d4aff;box-shadow:none}@media(max-width:680px){.content>section.card .grid{grid-template-columns:1fr!important}.content>section.card{padding:14px}}
</style>
CSS,
        'cash'=><<<'CSS'
<style>
.content .metric{border-radius:14px;box-shadow:none}.content .metric strong{font-size:25px}.content>.grid{gap:12px}.content section.card{border-radius:14px}.content .table th{background:#faf9fd}.content .table td,.content .table th{padding:12px}.content button.primary{background:#6d4aff;box-shadow:none}.content .badge{border-radius:7px}
</style>
CSS,
        'customers'=><<<'CSS'
<style>
.content>.grid{grid-template-columns:320px minmax(0,1fr)!important;align-items:start}.content>.grid>section:first-child{position:sticky;top:18px}.content .table tr:hover td{background:#faf9ff}.content .table th{background:#faf9fd}.content .card{border-radius:14px}.content button.primary{background:#6d4aff;box-shadow:none}.content .badge{border-radius:7px}@media(max-width:900px){.content>.grid{grid-template-columns:1fr!important}.content>.grid>section:first-child{position:static}}
</style>
CSS,
        'reports'=><<<'CSS'
<style>
.content>.grid{gap:12px}.content .metric{border-radius:14px;box-shadow:none;border-top:3px solid #6d4aff}.content .metric strong{font-size:25px}.content .card{border-radius:14px}.content .table th{background:#faf9fd}.content .badge{border-radius:7px}
</style>
CSS,
        'payments'=><<<'CSS'
<style>
.content>.grid{gap:12px}.content .metric{border-radius:14px;box-shadow:none}.content .card{border-radius:14px}.content .table th{background:#faf9fd}.content .badge{border-radius:7px}.content button.primary{background:#6d4aff;box-shadow:none}
</style>
CSS,
        default=>'',
    };
    if($css!=='')$html=str_replace('</head>',$css.'</head>',$html);
    echo $html;
    exit;
}
