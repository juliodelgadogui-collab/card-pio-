<?php

declare(strict_types=1);

$target=rtrim((string)($argv[1]??''),'/\\');if($target===''||!is_dir($target))throw new RuntimeException('Informe o diretório do pacote.');
$sha=trim((string)(getenv('GITHUB_SHA')?:getenv('EVENTMENU_COMMIT_SHA')?:''));if(!preg_match('/^[a-f0-9]{40}$/i',$sha))throw new RuntimeException('SHA de build ausente ou inválido.');
$branch=trim((string)(getenv('GITHUB_REF_NAME')?:getenv('EVENTMENU_BRANCH')?:'unknown'));
$version=trim((string)(getenv('EVENTMENU_VERSION')?:'wip-'.$branch.'-'.substr($sha,0,12)));
$meta=['commit_sha'=>strtolower($sha),'branch'=>$branch,'version'=>$version,'environment'=>(string)(getenv('APP_ENV')?:'production'),'build_id'=>(string)(getenv('GITHUB_RUN_ID')?:getenv('EVENTMENU_BUILD_ID')?:''),'built_at'=>gmdate('c'),'deployed_at'=>null];
$json=json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
foreach([$target.'/app/deployment.json',$target.'/storage/deployment.json']as$file){$dir=dirname($file);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Falha ao criar '.$dir);if(file_put_contents($file,$json)===false)throw new RuntimeException('Falha ao gravar '.$file);}
echo 'Deployment metadata: '.substr($sha,0,12).' '.$branch.PHP_EOL;
