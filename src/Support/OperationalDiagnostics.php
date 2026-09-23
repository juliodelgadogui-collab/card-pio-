<?php

declare(strict_types=1);

namespace EventMenu\Support;

use EventMenu\Core\Auth;
use Throwable;

final class OperationalDiagnostics
{
    /**
     * Registra o erro técnico sem expor secrets e devolve uma referência curta
     * que pode ser informada ao suporte sem mostrar stack/SQL/HTTP ao operador.
     */
    public static function capture(Throwable $error,string $operation,array $context=[]):string
    {
        try{$reference=strtoupper(bin2hex(random_bytes(4)));}catch(Throwable){$reference=strtoupper(substr(hash('sha256',microtime(true).mt_rand()),0,8));}
        $payload=[
            'event'=>'eventmenu.operational_error',
            'reference'=>$reference,
            'time'=>gmdate('c'),
            'operation'=>self::limit($operation,100),
            'tenant_id'=>Auth::tenantId(),
            'user_id'=>Auth::id(),
            'exception'=>get_class($error),
            'code'=>(string)$error->getCode(),
            'message'=>self::redact(self::limit($error->getMessage(),1800)),
            'context'=>self::sanitizeContext($context),
        ];
        error_log((string)json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return $reference;
    }

    /** @return array{message:string,reference:string} */
    public static function userFacing(Throwable $error,string $fallback,string $operation,array $context=[]):array
    {
        $reference=self::capture($error,$operation,$context);
        return ['message'=>self::friendly($error,$fallback),'reference'=>$reference];
    }

    public static function friendly(Throwable $error,string $fallback='Não foi possível concluir a operação. Tente novamente.'):string
    {
        $message=trim($error->getMessage());
        if($message==='')return $fallback;
        $lower=mb_strtolower($message);

        if(str_contains($lower,'database is locked')||str_contains($lower,'database table is locked'))return 'O sistema está processando outra operação. Aguarde alguns segundos e tente novamente.';
        if(str_contains($lower,'timeout')||str_contains($lower,'timed out')||str_contains($lower,'tempo limite'))return 'O servidor demorou para responder. Tente novamente.';
        if(str_contains($lower,'connection refused')||str_contains($lower,'could not resolve')||str_contains($lower,'failed to connect'))return 'Não foi possível conectar ao serviço agora. Tente novamente.';
        if(str_contains($lower,'acesso negado')||str_contains($lower,'unauthorized tenant')||str_contains($lower,'forbidden'))return 'Você não tem permissão para acessar esta informação.';

        $technical=[
            'sqlstate','pdoexception','stack trace','exception','sqlite','mysql','database','constraint failed',
            'http 4','http 5','endpoint','payload','json inválido','tenant','tenant_id','unit_id','customer_id','order_id',
            'payment_id','provider','webhook','bearer','token','binding','heartbeat','polling','backoff','idempotency',
            'namespace','logcat','debug','undefined','nullpointer','null pointer','sdk','driver',' at '
        ];
        foreach($technical as$marker)if(str_contains($lower,$marker))return $fallback;
        return self::limit($message,500);
    }

    private static function sanitizeContext(array $context):array
    {
        $safe=[];
        foreach($context as$key=>$value){
            $name=mb_strtolower((string)$key);
            if(preg_match('/pass|secret|token|authorization|card|cvv|password|credential|access[_-]?key/i',$name)){$safe[$key]='[redacted]';continue;}
            if(is_scalar($value)||$value===null)$safe[$key]=self::redact(self::limit((string)$value,500));
            elseif(is_array($value))$safe[$key]='[array '.count($value).']';
            else $safe[$key]='['.get_debug_type($value).']';
        }
        return $safe;
    }

    private static function redact(string $value):string
    {
        $value=preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i','Bearer [redacted]',$value)??$value;
        $value=preg_replace('/((?:access[_-]?token|refresh[_-]?token|secret|password|client[_-]?secret|api[_-]?key)\s*[:=]\s*)[^\s,;]+/i','$1[redacted]',$value)??$value;
        $value=preg_replace('/\b\d{13,19}\b/','[redacted-number]',$value)??$value;
        return $value;
    }

    private static function limit(string $value,int $max):string
    {
        return mb_substr(trim($value),0,$max);
    }
}
