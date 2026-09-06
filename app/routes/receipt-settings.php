<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

Auth::requirePermission('settings.manage');$tenantId=em_require_tenant();
$s=$pdo->prepare('SELECT * FROM tenants WHERE id=? LIMIT 1');$s->execute([$tenantId]);$tenant=$s->fetch();if(!$tenant)exit('Empresa não encontrada.');$settings=json_decode((string)($tenant['settings']??'{}'),true);if(!is_array($settings))$settings=[];
$clean=static fn(mixed$v,int$max=300):string=>mb_substr(trim((string)$v),0,$max);
if($_SERVER['REQUEST_METHOD']==='POST'){
    em_post_csrf();$paper=(string)($_POST['receipt_paper_width']??'80');if(!in_array($paper,['58','80'],true))$paper='80';$logo=$clean($_POST['receipt_logo_url']??'',600);if($logo!==''&&(!filter_var($logo,FILTER_VALIDATE_URL)||!in_array(strtolower((string)parse_url($logo,PHP_URL_SCHEME)),['http','https'],true)))$logo='';
    $settings=array_merge($settings,[
        'receipt_trade_name'=>$clean($_POST['receipt_trade_name']??'',120),
        'receipt_legal_name'=>$clean($_POST['receipt_legal_name']??'',160),
        'receipt_document'=>$clean($_POST['receipt_document']??'',30),
        'receipt_state_registration'=>$clean($_POST['receipt_state_registration']??'',40),
        'receipt_municipal_registration'=>$clean($_POST['receipt_municipal_registration']??'',40),
        'receipt_address'=>$clean($_POST['receipt_address']??'',260),
        'receipt_phone'=>$clean($_POST['receipt_phone']??'',40),
        'receipt_email'=>$clean($_POST['receipt_email']??'',120),
        'receipt_website'=>$clean($_POST['receipt_website']??'',160),
        'receipt_logo_url'=>$logo,
        'receipt_footer'=>$clean($_POST['receipt_footer']??'',300),
        'receipt_paper_width'=>$paper,
        'receipt_show_order_qr'=>isset($_POST['receipt_show_order_qr']),
        'receipt_show_ticket_qr'=>isset($_POST['receipt_show_ticket_qr']),
    ]);
    $u=$pdo->prepare('UPDATE tenants SET settings=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([json_encode($settings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$tenantId]);Auth::audit('receipt.settings','tenant',(string)$tenantId,['paper_width'=>$paper]);em_flash('ok','Dados do cupom térmico salvos.');em_go('receipt-settings');
}
$last=$pdo->prepare('SELECT id FROM orders WHERE tenant_id=? ORDER BY id DESC LIMIT 1');$last->execute([$tenantId]);$lastId=(int)($last->fetchColumn()?:0);
em_header('Impressão térmica','settings');
?>
<section class="page-hero"><div><span class="eyebrow">CUPOM TÉRMICO</span><h2>Dados impressos do estabelecimento</h2><p>Configure a identificação que aparece nos comprovantes de restaurante e eventos. O layout é otimizado para impressoras térmicas de 58 mm e 80 mm.</p></div><div class="hero-actions"><?php if($lastId):?><a class="button primary" target="_blank" href="<?= Security::e(app_url('?route=receipt&id='.$lastId)) ?>">Visualizar último cupom</a><?php endif;?><a class="button secondary" href="<?= Security::e(app_url('?route=settings')) ?>">Voltar às configurações</a></div></section>
<div class="alert"><strong>Importante:</strong> o EventMenu imprime um comprovante operacional em formato de cupom. Ele só deve ser identificado como NFC-e/NF-e ou documento fiscal depois que o módulo fiscal estiver integrado e autorizado.</div>
<form method="post" class="card" style="margin-top:14px"><input type="hidden" name="_csrf" value="<?= em_csrf() ?>"><div class="section-head"><div><span class="eyebrow">IDENTIFICAÇÃO</span><h2>Restaurante / empresa organizadora</h2></div></div><div class="form-grid">
<label>Nome fantasia<input name="receipt_trade_name" value="<?= Security::e($settings['receipt_trade_name']??$tenant['name']) ?>" placeholder="Nome exibido no topo"></label><label>Razão social<input name="receipt_legal_name" value="<?= Security::e($settings['receipt_legal_name']??'') ?>"></label><label>CPF / CNPJ<input name="receipt_document" value="<?= Security::e($settings['receipt_document']??'') ?>"></label><label>Inscrição estadual<input name="receipt_state_registration" value="<?= Security::e($settings['receipt_state_registration']??'') ?>"></label><label>Inscrição municipal<input name="receipt_municipal_registration" value="<?= Security::e($settings['receipt_municipal_registration']??'') ?>"></label><label>Telefone / WhatsApp<input name="receipt_phone" value="<?= Security::e($settings['receipt_phone']??($settings['whatsapp']??'')) ?>"></label><label class="span-2">Endereço completo<input name="receipt_address" value="<?= Security::e($settings['receipt_address']??'') ?>" placeholder="Rua, número, bairro, cidade/UF, CEP"></label><label>E-mail<input type="email" name="receipt_email" value="<?= Security::e($settings['receipt_email']??'') ?>"></label><label>Site<input name="receipt_website" value="<?= Security::e($settings['receipt_website']??'') ?>"></label><label class="span-2">Logo (URL opcional)<input type="url" name="receipt_logo_url" value="<?= Security::e($settings['receipt_logo_url']??($settings['menu_logo_url']??'')) ?>" placeholder="https://..."></label></div>
<div class="section-head" style="margin-top:20px"><div><span class="eyebrow">IMPRESSORA</span><h2>Formato do cupom</h2></div></div><div class="form-grid"><label>Largura do papel<select name="receipt_paper_width"><option value="80"<?= em_selected($settings['receipt_paper_width']??'80','80') ?>>80 mm · padrão</option><option value="58"<?= em_selected($settings['receipt_paper_width']??'','58') ?>>58 mm · compacto</option></select></label><label class="span-2">Mensagem no rodapé<textarea name="receipt_footer" maxlength="300" placeholder="Obrigado pela preferência!"><?= Security::e($settings['receipt_footer']??'Obrigado pela preferência!') ?></textarea></label><label class="checkbox"><input type="checkbox" name="receipt_show_order_qr"<?= em_checked($settings['receipt_show_order_qr']??true) ?>> Imprimir QR do pedido quando aplicável</label><label class="checkbox"><input type="checkbox" name="receipt_show_ticket_qr"<?= em_checked($settings['receipt_show_ticket_qr']??true) ?>> Imprimir QR individual dos ingressos</label></div><button class="primary" style="margin-top:18px">Salvar dados do cupom</button></form>
<section class="card" style="margin-top:14px"><span class="eyebrow">EVENTOS</span><h2>Dados do evento entram automaticamente</h2><p>Em vendas de ingresso, o cupom inclui nome do evento, data/hora, local, endereço, lote/tipo, código e QR individual do ingresso. Em pedidos de restaurante, mostra canal, mesa/comanda, cliente, itens, adicionais, totais, pagamento e QR de retirada quando aplicável.</p></section>
<?php em_footer();
