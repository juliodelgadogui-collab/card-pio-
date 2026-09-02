<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class MailQueueService
{
    public function queue(string $to,string $subject,string $body,string $idempotencyKey):string
    {
        $to=trim($to);if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');$root=dirname(__DIR__,2).'/storage/mail_queue';if(!is_dir($root)&&!mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Não foi possível criar a fila de e-mail.');$hash=hash('sha256',$idempotencyKey);$file=$root.'/'.$hash.'.json';if(is_file($file))return$file;$payload=['to'=>$to,'subject'=>$subject,'body'=>$body,'created_at'=>gmdate('c'),'attempts'=>0,'status'=>'pending'];$tmp=$file.'.tmp.'.bin2hex(random_bytes(4));if(file_put_contents($tmp,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Falha ao gravar e-mail na fila.');rename($tmp,$file);return$file;
    }
}
