<?php

declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

$asset=strtolower(trim((string)($_GET['asset']??'manifest')));
$basePath=rtrim((string)parse_url(app_url(''),PHP_URL_PATH),'/').'/';
if($basePath==='//')$basePath='/';

if($asset==='manifest'){
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode([
        'id'=>app_url('delivery.php'),
        'name'=>'DELYVRE — Escolha. Peça. Receba.',
        'short_name'=>'DELYVRE',
        'description'=>'Encontre restaurantes, faça seu pedido e acompanhe a entrega.',
        'lang'=>'pt-BR',
        'start_url'=>app_url('delivery.php?source=pwa'),
        'scope'=>$basePath,
        'display'=>'standalone',
        'display_override'=>['standalone','minimal-ui'],
        'orientation'=>'portrait-primary',
        'background_color'=>'#fffaf7',
        'theme_color'=>'#ff4f3d',
        'categories'=>['food','shopping','lifestyle'],
        'icons'=>[
            ['src'=>app_url('delyvre-pwa.php?asset=icon-192'),'sizes'=>'192x192','type'=>'image/png','purpose'=>'any maskable'],
            ['src'=>app_url('delyvre-pwa.php?asset=icon-512'),'sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
        ],
        'shortcuts'=>[
            ['name'=>'Buscar restaurantes','short_name'=>'Buscar','url'=>app_url('delivery.php#buscar'),'icons'=>[['src'=>app_url('delyvre-pwa.php?asset=icon-192'),'sizes'=>'192x192','type'=>'image/png']]],
        ],
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if($asset==='sw'){
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Service-Worker-Allowed: '.$basePath);
    $home=json_encode(app_url('delivery.php'),JSON_UNESCAPED_SLASHES);
    $css=json_encode(app_url('assets/delivery-marketplace.css'),JSON_UNESCAPED_SLASHES);
    $offline=json_encode('<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#ff4f3d"><title>DELYVRE · Sem internet</title><style>body{margin:0;font-family:system-ui,-apple-system,sans-serif;background:#fffaf7;color:#211b19;display:grid;min-height:100vh;place-items:center;padding:24px;box-sizing:border-box}.c{max-width:420px;text-align:center}.m{width:68px;height:68px;margin:auto;border-radius:22px;background:#ff4f3d;color:#fff;display:grid;place-items:center;font-size:34px;font-weight:900}h1{font-size:1.6rem;margin:18px 0 8px}p{color:#6f6662;line-height:1.5}button{margin-top:10px;border:0;border-radius:15px;background:#211b19;color:#fff;padding:14px 18px;font-weight:800}</style><div class="c"><div class="m">D</div><h1>Você está sem internet</h1><p>Seu carrinho salvo no aparelho continua protegido. Reconecte para atualizar restaurantes, preços e finalizar o pedido.</p><button onclick="location.reload()">Tentar novamente</button></div></html>',JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    echo <<<JS
const CACHE='delyvre-shell-v1';
const HOME={$home};
const CSS={$css};
const OFFLINE={$offline};
self.addEventListener('install',event=>{
  event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll([HOME,CSS])).catch(()=>undefined));
});
self.addEventListener('activate',event=>{
  event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('delyvre-shell-')&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim()));
});
self.addEventListener('fetch',event=>{
  const request=event.request;
  if(request.method!=='GET')return;
  const url=new URL(request.url);
  if(url.origin!==self.location.origin)return;
  if(url.pathname.includes('api-')||url.pathname.includes('webhook')||url.pathname.includes('payment'))return;
  if(request.mode==='navigate'){
    event.respondWith(fetch(request).then(response=>{
      if(response&&response.ok&&url.pathname.endsWith('/delivery.php')){
        const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(HOME,copy));
      }
      return response;
    }).catch(async()=>{
      const cached=await caches.match(request,{ignoreSearch:true})||await caches.match(HOME,{ignoreSearch:true});
      return cached||new Response(OFFLINE,{headers:{'Content-Type':'text/html; charset=utf-8'}});
    }));
    return;
  }
  if(url.pathname.includes('/assets/delivery-marketplace.css')||url.pathname.includes('/delyvre-pwa.php')){
    event.respondWith(caches.match(request).then(cached=>cached||fetch(request).then(response=>{
      if(response&&response.ok){const copy=response.clone();caches.open(CACHE).then(cache=>cache.put(request,copy));}
      return response;
    })));
  }
});
JS;
    exit;
}

$icons=[
    'icon-192'=>'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',
    'icon-512'=>'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',
];
if(isset($icons[$asset])){
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800, immutable');
    echo base64_decode($icons[$asset],true)?:'';
    exit;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Recurso DELYVRE não encontrado.';
