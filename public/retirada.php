<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Security;
use EventMenu\Services\FulfillmentService;

$token=(new FulfillmentService())->extractToken((string)($_GET['t']??''));
try{$sale=(new FulfillmentService())->byToken($token);}catch(Throwable $e){http_response_code(404);exit('Comprovante de retirada não encontrado.');}
$auto=!empty($_GET['auto'])&&$sale['payment_status']==='paid';
function rq(mixed $v):string{$n=(float)$v;return rtrim(rtrim(number_format($n,3,',','.'),'0'),',');}
$qrSvg=null;
if($sale['payment_status']==='paid'&&class_exists('Endroid\\QrCode\\QrCode')){
    try{
        $url=rtrim((string)env('APP_URL',''),'/').'/retirada.php?t='.rawurlencode($token);
        $qr=new \Endroid\QrCode\QrCode(data:$url,size:300,margin:8);
        $writer=new \Endroid\QrCode\Writer\SvgWriter();$qrSvg=$writer->write($qr)->getString();
    }catch(Throwable){$qrSvg=null;}
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Retirada #<?= (int)$sale['id'] ?> — <?= Security::e($sale['tenant_name']) ?></title><style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#eee;color:#111;margin:0;padding:20px}.receipt{width:80mm;max-width:100%;margin:auto;background:#fff;padding:7mm;border-radius:8px}.center{text-align:center}.brand{font-size:20px;font-weight:800}.muted{color:#555;font-size:12px}.line{border-top:1px dashed #777;margin:12px 0}.item{padding:8px 0;border-bottom:1px dashed #aaa}.item:last-child{border-bottom:0}.numbers{display:grid;grid-template-columns:repeat(3,1fr);gap:5px;margin-top:5px;text-align:center}.numbers div{border:1px solid #ccc;padding:5px;border-radius:5px}.numbers strong{display:block;font-size:18px}.qr svg{width:48mm;height:auto;background:white}.status{padding:8px;border:2px solid #111;border-radius:6px;font-weight:700}.code{font-family:monospace;font-size:11px;word-break:break-all}.actions{text-align:center;margin:15px}.actions button,.actions a{display:inline-block;padding:12px 20px;font-weight:700;margin:3px}@media print{body{background:#fff;padding:0}.receipt{width:80mm;border-radius:0;padding:4mm}.actions{display:none}@page{size:80mm auto;margin:0}}
</style></head><body><div class="actions"><button onclick="window.print()">Imprimir comprovante</button><a href="/?route=fulfillment&token=<?= rawurlencode($token) ?>">Abrir retirada</a></div><main class="receipt"><div class="center"><div class="brand"><?= Security::e($sale['tenant_name']) ?></div><div class="muted">EventMenu Premium</div><h2>RETIRADA #<?= (int)$sale['id'] ?></h2><div class="status"><?= Security::e(strtoupper((string)$sale['payment_status'])) ?> · <?= Security::e(strtoupper((string)$sale['fulfillment_status'])) ?></div><p><?= Security::e($sale['customer_name']??'Consumidor') ?></p></div><div class="line"></div><?php foreach($sale['items'] as$item):?><div class="item"><strong><?= Security::e($item['name_snapshot']) ?></strong><div class="numbers"><div><span class="muted">Comprou</span><strong><?= Security::e(rq($item['quantity'])) ?></strong></div><div><span class="muted">Retirou</span><strong><?= Security::e(rq($item['fulfilled_quantity'])) ?></strong></div><div><span class="muted">Saldo</span><strong><?= Security::e(rq($item['remaining_quantity'])) ?></strong></div></div></div><?php endforeach;?><div class="line"></div><div class="center"><?php if($sale['payment_status']==='paid'):?><?php if($qrSvg):?><div class="qr"><?= $qrSvg ?></div><?php endif;?><p><strong>APRESENTE ESTE QR PARA RETIRAR</strong></p><p class="muted">A cada retirada o saldo é atualizado no servidor. Reimprimir este papel não cria saldo adicional.</p><p class="code"><?= Security::e($token) ?></p><?php else:?><p class="status">AGUARDANDO PAGAMENTO</p><p class="muted">Este comprovante não libera retirada enquanto a venda não estiver totalmente paga.</p><?php endif;?></div><div class="line"></div><p class="muted center">Venda registrada em <?= Security::e((string)$sale['created_at']) ?> UTC<br>Estoque reservado/baixado na venda. A retirada não baixa estoque novamente.</p></main><?php if($auto):?><script>addEventListener('load',()=>setTimeout(()=>window.print(),250));</script><?php endif;?></body></html>
