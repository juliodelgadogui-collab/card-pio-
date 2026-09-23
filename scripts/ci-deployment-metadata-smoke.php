<?php

declare(strict_types=1);

$repoRoot=dirname(__DIR__);$sandboxDir=sys_get_temp_dir().'/eventmenu-deploy-meta-'.bin2hex(random_bytes(5));
function meta_fail(string $message): never { fwrite(STDERR,"DEPLOY METADATA CI FAIL: {$message}\n"); exit(1); }
function rrmdir(string $path): void { if(!is_dir($path))return;foreach(scandir($path)?:[]as$item){if($item==='.'||$item==='..')continue;$full=$path.'/'.$item;if(is_dir($full))rrmdir($full);else@unlink($full);}@rmdir($path); }
@mkdir($sandboxDir.'/app',0775,true);@mkdir($sandboxDir.'/storage',0775,true);
$sha=str_repeat('a',40);putenv('EVENTMENU_COMMIT_SHA='.$sha);putenv('EVENTMENU_BRANCH=ci/deploy');putenv('EVENTMENU_VERSION=ci-1.0.0');putenv('EVENTMENU_BUILD_ID=ci-build-1');
try{
    $argv=[$repoRoot.'/scripts/generate-deployment-metadata.php',$sandboxDir];include $repoRoot.'/scripts/generate-deployment-metadata.php';
    $immutable=json_decode((string)file_get_contents($sandboxDir.'/app/deployment.json'),true,512,JSON_THROW_ON_ERROR);
    if(($immutable['commit_sha']??'')!==$sha||($immutable['branch']??'')!=='ci/deploy'||!array_key_exists('deployed_at',$immutable)||$immutable['deployed_at']!==null)meta_fail('manifesto de build incorreto');
    $argv=[$repoRoot.'/scripts/post-deploy.php',$sandboxDir];include $repoRoot.'/scripts/post-deploy.php';
    $runtime=json_decode((string)file_get_contents($sandboxDir.'/storage/deployment.json'),true,512,JSON_THROW_ON_ERROR);
    if(($runtime['commit_sha']??'')!==$sha||empty($runtime['deployed_at']))meta_fail('manifesto de runtime incorreto');
    echo "deployment metadata smoke: OK\n";
}finally{rrmdir($sandboxDir);}
