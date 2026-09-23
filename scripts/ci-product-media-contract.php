<?php

declare(strict_types=1);

$root=dirname(__DIR__);
function media_contract_fail(string$message):never{fwrite(STDERR,"PRODUCT MEDIA CI FAIL: {$message}\n");exit(1);}
function media_contract_assert(bool$ok,string$message):void{if(!$ok)media_contract_fail($message);}
function media_contract_file(string$path):string{$value=file_get_contents($path);if($value===false)media_contract_fail('Não foi possível ler '.$path);return$value;}

$service=media_contract_file($root.'/src/Services/ProductMediaService.php');
media_contract_assert(str_contains($service,"WHERE id=? AND tenant_id=?"),'Produto precisa ser resolvido com tenant_id.');
media_contract_assert(str_contains($service,"'image/jpeg'=>'jpg'")&&str_contains($service,"'image/png'=>'png'")&&str_contains($service,"'image/webp'=>'webp'"),'Tipos seguros de imagem não estão restritos a JPG/PNG/WebP.');
media_contract_assert(str_contains($service,'5*1024*1024'),'Limite de 5 MB da foto não está protegido.');
media_contract_assert(str_contains($service,"app_absolute_url('media.php?t='") ,'Foto local precisa ser gravada com URL absoluta para o Android.');
media_contract_assert(str_contains($service,"'product-'.\$productId.'-'") ,'Nome da mídia precisa carregar o product_id.');

$media=media_contract_file($root.'/public/media.php');
media_contract_assert(str_contains($media,"product-[1-9][0-9]*-[a-f0-9]{24}"),'media.php não aceita o padrão seguro de foto de produto.');
media_contract_assert(str_contains($media,"storage/media/"),'media.php precisa servir somente a pasta de mídia interna.');

$config=media_contract_file($root.'/app/routes/product-config.php');
media_contract_assert(str_contains($config,'new ProductMediaService()'),'Tela do produto não usa o serviço de mídia.');
media_contract_assert(str_contains($config,'enctype="multipart/form-data"'),'Formulário da foto precisa usar multipart/form-data.');
media_contract_assert(str_contains($config,'name="product_photo"'),'Campo de upload da foto não está presente.');
media_contract_assert(str_contains($config,"photo-upload")&&str_contains($config,"photo-remove"),'Ações de upload/remoção da foto não estão ligadas à tela.');

$go=media_contract_file($root.'/mobile/eventmenu-go/app/src/main/java/br/com/eventmenu/go/data/EventMenuRepository.kt');
media_contract_assert(str_contains($go,'imageUrl=p.optString("image_url")'),'EventMenu GO precisa continuar consumindo image_url do catálogo.');

echo "CI product media contract OK\n";
