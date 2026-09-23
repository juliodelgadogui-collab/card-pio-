<?php

declare(strict_types=1);

$root=dirname(__DIR__);$fail=[];$files=[];foreach(['app','public','src']as$dir){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS));foreach($it as$f){if($f->isFile()&&$f->getExtension()==='php')$files[]=$f->getPathname();}}
$all='';foreach($files as$file)$all.=file_get_contents($file)."\n";
if(str_contains($all,'Database::pdo('))$fail[]='Database::pdo obsoleto encontrado';
$admin=file_get_contents($root.'/app/admin_helpers.php');if(!str_contains($admin,'function em_flash_render'))$fail[]='em_flash_render ausente';
$wa=file_get_contents($root.'/public/api-whatsapp-desktop.php');if(preg_match('/^use\s+(?:\\\\)?(?:RuntimeException|Throwable);/m',$wa))$fail[]='imports globais inválidos no WhatsApp Desktop';
foreach([$root.'/src/Services/ReceiptService.php',$root.'/src/Services/ReceiptPresentationService.php']as$file){$src=file_get_contents($file);if(str_contains($src,'(string)$settings[\'receipt_paper_width\']'))$fail[]='leitura insegura de receipt_paper_width em '.basename($file);}
$loyalty=file_get_contents($root.'/src/Services/LoyaltyPointsService.php');if(!str_contains($loyalty,'function config(int $tenantId, ?PDO $pdo = null)'))$fail[]='assinatura LoyaltyPointsService::config inesperada';
foreach($files as$file){$name=strtolower(basename($file));if(preg_match('/(?:^test-|\-test\.php$|^debug-|phpinfo)/',$name))$fail[]='script de teste/debug público ou versionado: '.$file;}
if($fail){fwrite(STDERR,"Falhas:\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "error_log regression smoke: OK\n";
