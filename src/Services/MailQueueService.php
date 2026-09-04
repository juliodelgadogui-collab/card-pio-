<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class MailQueueService
{
    private const MAX_ATTEMPTS=6;

    public function queue(string $to,string $subject,string $body,string $idempotencyKey):string
    {
        $to=trim($to);
        if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');
        $root=$this->queueRoot();
        $hash=hash('sha256',$idempotencyKey);
        $file=$root.'/'.$hash.'.json';
        $sent=$this->storageRoot().'/mail_sent/'.$hash.'.json';
        if(is_file($sent))return $sent;
        if(is_file($file))return $file;
        $payload=[
            'id'=>$hash,
            'to'=>$to,
            'subject'=>mb_substr(trim($subject),0,240),
            'body'=>$body,
            'created_at'=>gmdate('c'),
            'attempts'=>0,
            'next_attempt_at'=>null,
            'status'=>'pending',
            'last_error'=>null,
        ];
        $this->writeJsonAtomic($file,$payload);
        return $file;
    }

    /** @return array{processed:int,sent:int,failed:int,deferred:int} */
    public function process(int $limit=25):array
    {
        $limit=max(1,min(200,$limit));
        $files=glob($this->queueRoot().'/*.json')?:[];
        sort($files,SORT_STRING);
        $stats=['processed'=>0,'sent'=>0,'failed'=>0,'deferred'=>0];
        foreach(array_slice($files,0,$limit) as$file){
            $stats['processed']++;
            $result=$this->processFile($file);
            $stats[$result]++;
        }
        return $stats;
    }

    private function processFile(string $file):string
    {
        $processing=$file.'.processing.'.getmypid().'.'.bin2hex(random_bytes(3));
        if(!@rename($file,$processing))return 'deferred';
        try{
            $payload=json_decode((string)file_get_contents($processing),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new RuntimeException('Arquivo de fila inválido.');
            $next=(string)($payload['next_attempt_at']??'');
            if($next!==''&&strtotime($next)!==false&&strtotime($next)>time()){
                @rename($processing,$file);
                return 'deferred';
            }
            $to=(string)($payload['to']??'');
            if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Destinatário inválido.');
            $this->deliver($to,(string)($payload['subject']??''),(string)($payload['body']??''));
            $payload['status']='sent';
            $payload['sent_at']=gmdate('c');
            $payload['last_error']=null;
            $dest=$this->dir('mail_sent').'/'.basename($file);
            $this->writeJsonAtomic($dest,$payload);
            @unlink($processing);
            return 'sent';
        }catch(\Throwable $e){
            $payload=isset($payload)&&is_array($payload)?$payload:[];
            $attempts=(int)($payload['attempts']??0)+1;
            $payload['attempts']=$attempts;
            $payload['last_error']=mb_substr($this->safeError($e->getMessage()),0,500);
            $payload['last_attempt_at']=gmdate('c');
            if($attempts>=self::MAX_ATTEMPTS){
                $payload['status']='failed';
                $dest=$this->dir('mail_failed').'/'.basename($file);
                $this->writeJsonAtomic($dest,$payload);
                @unlink($processing);
                return 'failed';
            }
            $delay=min(3600,60*(2**max(0,$attempts-1)));
            $payload['status']='pending';
            $payload['next_attempt_at']=gmdate('c',time()+$delay);
            $this->writeJsonAtomic($file,$payload);
            @unlink($processing);
            return 'deferred';
        }
    }

    private function deliver(string $to,string $subject,string $body):void
    {
        $driver=strtolower(trim((string)env('MAIL_DRIVER','log')));
        if($driver==='log'){$this->logDelivery($to,$subject,$body);return;}
        if($driver==='mail'){$this->phpMail($to,$subject,$body);return;}
        if($driver==='smtp'){$this->smtp($to,$subject,$body);return;}
        throw new RuntimeException('MAIL_DRIVER inválido. Use log, mail ou smtp.');
    }

    private function logDelivery(string $to,string $subject,string $body):void
    {
        $file=$this->dir('mail_preview').'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.txt';
        $content="TO: {$to}\nSUBJECT: {$subject}\nDATE: ".gmdate('c')."\n\n{$body}\n";
        if(file_put_contents($file,$content,LOCK_EX)===false)throw new RuntimeException('Não foi possível gravar o e-mail de teste.');
    }

    private function phpMail(string $to,string $subject,string $body):void
    {
        $from=$this->fromAddress();$fromName=$this->fromName();
        $headers=[
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'From: '.$this->formatAddress($from,$fromName),
            'Reply-To: '.$from,
            'X-Mailer: EventMenu Premium',
        ];
        if(!@mail($to,$this->encodedHeader($subject),$body,implode("\r\n",$headers)))throw new RuntimeException('A função mail() não confirmou o envio.');
    }

    private function smtp(string $to,string $subject,string $body):void
    {
        $host=trim((string)env('MAIL_HOST',''));
        $port=(int)env('MAIL_PORT','587');
        $encryption=strtolower(trim((string)env('MAIL_ENCRYPTION','tls')));
        $username=(string)env('MAIL_USERNAME','');$password=(string)env('MAIL_PASSWORD','');
        $timeout=max(3,min(60,(int)env('MAIL_TIMEOUT','15')));
        if($host===''||$port<1||$port>65535)throw new RuntimeException('Servidor SMTP não configurado.');
        if(!in_array($encryption,['tls','ssl','none',''],true))throw new RuntimeException('MAIL_ENCRYPTION inválido.');
        $scheme=$encryption==='ssl'?'ssl':'tcp';
        $errno=0;$errstr='';
        $socket=@stream_socket_client($scheme.'://'.$host.':'.$port,$errno,$errstr,$timeout,STREAM_CLIENT_CONNECT);
        if(!is_resource($socket))throw new RuntimeException('Não foi possível conectar ao servidor SMTP.');
        stream_set_timeout($socket,$timeout);
        try{
            $this->smtpExpect($socket,[220]);
            $hostname=(string)($_SERVER['SERVER_NAME']??'eventmenu.local');
            $this->smtpCommand($socket,'EHLO '.$hostname,[250]);
            if($encryption==='tls'){
                $this->smtpCommand($socket,'STARTTLS',[220]);
                if(!@stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('Falha ao ativar TLS no SMTP.');
                $this->smtpCommand($socket,'EHLO '.$hostname,[250]);
            }
            if($username!==''){
                $this->smtpCommand($socket,'AUTH LOGIN',[334]);
                $this->smtpCommand($socket,base64_encode($username),[334],false);
                $this->smtpCommand($socket,base64_encode($password),[235],false);
            }
            $from=$this->fromAddress();
            $this->smtpCommand($socket,'MAIL FROM:<'.$from.'>',[250]);
            $this->smtpCommand($socket,'RCPT TO:<'.$to.'>',[250,251]);
            $this->smtpCommand($socket,'DATA',[354]);
            $message=$this->smtpMessage($to,$subject,$body);
            fwrite($socket,$message."\r\n.\r\n");
            $this->smtpExpect($socket,[250]);
            $this->smtpCommand($socket,'QUIT',[221]);
        }finally{fclose($socket);}
    }

    /** @param resource $socket */
    private function smtpCommand($socket,string $command,array $expected,bool $redact=true):string
    {
        if(fwrite($socket,$command."\r\n")===false)throw new RuntimeException('Falha de comunicação SMTP.');
        return $this->smtpExpect($socket,$expected,$redact?$command:'[credencial]');
    }

    /** @param resource $socket */
    private function smtpExpect($socket,array $expected,string $context='SMTP'):string
    {
        $response='';$code=0;
        do{
            $line=fgets($socket,8192);
            if($line===false)throw new RuntimeException('Servidor SMTP encerrou a conexão.');
            $response.=$line;
            if(preg_match('/^(\d{3})([ -])/',$line,$m)){$code=(int)$m[1];$continued=$m[2]==='-';}else{$continued=false;}
        }while($continued);
        if(!in_array($code,$expected,true))throw new RuntimeException('SMTP rejeitou a operação (código '.$code.').');
        return $response;
    }

    private function smtpMessage(string $to,string $subject,string $body):string
    {
        $headers=[
            'Date: '.date(DATE_RFC2822),
            'From: '.$this->formatAddress($this->fromAddress(),$this->fromName()),
            'To: <'.$to.'>',
            'Subject: '.$this->encodedHeader($subject),
            'Message-ID: <'.bin2hex(random_bytes(16)).'@eventmenu.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: EventMenu Premium',
        ];
        $body=str_replace(["\r\n","\r"],"\n",$body);
        $body=preg_replace('/^\./m','..',$body)??$body;
        return implode("\r\n",$headers)."\r\n\r\n".str_replace("\n","\r\n",$body);
    }

    private function fromAddress():string
    {
        $from=trim((string)env('MAIL_FROM_ADDRESS',''));
        if(!filter_var($from,FILTER_VALIDATE_EMAIL))throw new RuntimeException('MAIL_FROM_ADDRESS não configurado.');
        return $from;
    }
    private function fromName():string{return trim((string)env('MAIL_FROM_NAME',(string)env('APP_NAME','EventMenu Premium')))?:'EventMenu Premium';}
    private function formatAddress(string $email,string $name):string{return $name!==''?$this->encodedHeader($name).' <'.$email.'>':'<'.$email.'>';}
    private function encodedHeader(string $value):string{return '=?UTF-8?B?'.base64_encode(str_replace(["\r","\n"],' ',$value)).'?=';}
    private function safeError(string $message):string
    {
        foreach([(string)env('MAIL_PASSWORD',''),(string)env('MAIL_USERNAME','')] as$secret)if($secret!=='')$message=str_replace($secret,'[redacted]',$message);
        return $message;
    }
    private function storageRoot():string{return dirname(__DIR__,2).'/storage';}
    private function queueRoot():string{return $this->dir('mail_queue');}
    private function dir(string $name):string{$root=$this->storageRoot().'/'.$name;if(!is_dir($root)&&!mkdir($root,0770,true)&&!is_dir($root))throw new RuntimeException('Não foi possível criar storage/'.$name.'.');return$root;}
    private function writeJsonAtomic(string $file,array $payload):void
    {
        $tmp=$file.'.tmp.'.bin2hex(random_bytes(4));$json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Falha ao gravar arquivo da fila.');
        if(!@rename($tmp,$file)){@unlink($tmp);throw new RuntimeException('Falha ao finalizar arquivo da fila.');}
    }
}
