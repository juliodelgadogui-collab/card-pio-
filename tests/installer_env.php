<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

function installer_assert(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"Installer env test failed: {$message}\n");exit(1);}}

$key='EM_TEST_'.strtoupper(bin2hex(random_bytes(4)));
$key2=$key.'_PASS';
$value='https://example.com/path?a=1&b=2';
$password='S3nh@! "aspas" = # ; \\ teste';
$tmp=sys_get_temp_dir().'/eventmenu-env-'.bin2hex(random_bytes(5));
$content=$key.'='.json_encode($value,JSON_UNESCAPED_SLASHES)."\n".$key2.'='.json_encode($password,JSON_UNESCAPED_SLASHES)."\n";
installer_assert(file_put_contents($tmp,$content)!==false,'não conseguiu criar arquivo temporário');
load_env($tmp);
@unlink($tmp);
installer_assert((string)getenv($key)===$value,'URL com caracteres especiais foi alterada');
installer_assert((string)getenv($key2)===$password,'senha com caracteres especiais foi alterada');
echo "Installer env test OK\n";
