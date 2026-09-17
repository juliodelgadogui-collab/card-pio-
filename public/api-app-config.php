<?php

declare(strict_types=1);

require __DIR__.'/../app/bootstrap.php';

use EventMenu\Services\ApiRateLimitExceededException;
use EventMenu\Services\ApiRateLimitService;
use EventMenu\Services\ClientPolicyService;
use EventMenu\Services\PlatformFailoverService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if($_SERVER['REQUEST_METHOD']!=='GET'){
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Método não permitido.'],JSON_UNESCAPED_UNICODE);
    exit;
}

try{
    $rate=new ApiRateLimitService();
    $rate->assertAllowed('app.config',$rate->requestSubject(),120,60);
    $maintenance=filter_var(env('APP_MAINTENANCE','false'),FILTER_VALIDATE_BOOL);

    $servedBy='primary';
    $routing=null;
    $policySigning=null;
    $androidPolicy=null;
    try{
        $failover=new PlatformFailoverService();
        $servedBy=$failover->nodeRole();
        if($servedBy==='primary'){
            // Este endpoint é consultado no endereço principal compilado no APK.
            // Por HTTPS, ele fornece a primeira raiz pública de confiança antes
            // do login, além de uma rota de contingência já verificada.
            $routing=$failover->routingConfig();
            $policy=new ClientPolicyService();
            $policySigning=$policy->publicKeyBundle();
            $androidPolicy=$policy->signedEnvelope('android');
        }
    }catch(Throwable){
        // Compatibilidade durante atualização gradual do servidor: o bloco
        // legado abaixo continua disponível mesmo antes das migrations 104/105.
    }

    echo json_encode([
        'ok'=>true,
        'server_time'=>gmdate('c'),
        'served_by'=>$servedBy,
        'routing'=>$routing,
        'policy_signing'=>$policySigning,
        'policy_android'=>$androidPolicy,
        'app'=>[
            'minimum_version'=>(string)env('APP_MIN_VERSION','0.2.0'),
            'recommended_version'=>(string)env('APP_RECOMMENDED_VERSION',''),
            'maintenance'=>$maintenance,
            'maintenance_message'=>$maintenance?(string)env('APP_MAINTENANCE_MESSAGE','Sistema em manutenção. Tente novamente em alguns minutos.'):'',
            'features'=>[
                'push_notifications'=>true,
                'event_order_qr'=>true,
                'event_bar_orders'=>true,
                'tenant_branding'=>true,
            ],
        ],
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(ApiRateLimitExceededException$e){
    http_response_code(429);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}catch(Throwable){
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Erro interno.'],JSON_UNESCAPED_UNICODE);
}
