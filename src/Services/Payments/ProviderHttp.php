<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use RuntimeException;

trait ProviderHttp
{
    private function requestJson(string $provider,string $method,string $url,array $headers=[],?array $body=null,?string $basicAuth=null): array
    {
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha ao iniciar comunicação com '.$provider.'.');$headers[]='Accept: application/json';$opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25];if($body!==null){$headers[]='Content-Type: application/json';$opts[CURLOPT_HTTPHEADER]=$headers;$opts[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}if($basicAuth!==null)$opts[CURLOPT_USERPWD]=$basicAuth;curl_setopt_array($ch,$opts);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);$decoded=is_string($raw)&&$raw!==''?json_decode($raw,true):[];if($raw===false||$status<200||$status>=300){$detail=is_array($decoded)?(string)($decoded['message']??$decoded['error']??$decoded['detail']??''):'';throw new RuntimeException($provider.' recusou a operação (HTTP '.$status.').'.($detail!==''?' '.$detail:'').($err!==''?' '.$err:''));}if(!is_array($decoded))throw new RuntimeException('Resposta inválida de '.$provider.'.');return$decoded;
    }
}
