<?php

declare(strict_types=1);

$root=rtrim((string)($argv[1]??dirname(__DIR__)),'/\\');$immutable=$root.'/app/deployment.json';$runtime=$root.'/storage/deployment.json';
if(!is_file($immutable))throw new RuntimeException('Manifesto imutável do build ausente.');$data=json_decode((string)file_get_contents($immutable),true,512,JSON_THROW_ON_ERROR);if(!is_array($data)||!preg_match('/^[a-f0-9]{40}$/i',(string)($data['commit_sha']??'')))throw new RuntimeException('Manifesto do build inválido.');
$data['deployed_at']=gmdate('c');$tmp=$runtime.'.tmp.'.bin2hex(random_bytes(4));if(file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",LOCK_EX)===false)throw new RuntimeException('Falha ao registrar deploy.');if(!rename($tmp,$runtime)){@unlink($tmp);throw new RuntimeException('Falha ao ativar manifesto de deploy.');}
echo 'Deploy registrado: '.$data['commit_sha'].' '.$data['deployed_at'].PHP_EOL;
