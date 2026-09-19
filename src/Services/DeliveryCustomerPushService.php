<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use RuntimeException;

final class DeliveryCustomerPushService
{
    private static ?array $cachedAccessToken=null;

    public function sendOrderStatus(int $orderId,string $status):array
    {
        if($orderId<1)return['sent'=>0,'devices'=>0];
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT l.account_id,o.tenant_id,o.id,t.name store_name FROM delivery_customer_order_links l JOIN orders o ON o.id=l.order_id JOIN tenants t ON t.id=o.tenant_id WHERE l.order_id=? LIMIT 1');$q->execute([$orderId]);$order=$q->fetch();
        if(!$order)return['sent'=>0,'devices'=>0,'customer_order'=>false];
        $d=$pdo->prepare('SELECT id,push_token FROM delivery_customer_push_devices WHERE account_id=? AND active=1 ORDER BY id');$d->execute([(int)$order['account_id']]);$devices=$d->fetchAll();if(!$devices)return['sent'=>0,'devices'=>0];
        $creds=$this->credentials();$access=$this->accessToken();[$title,$message]=$this->copy((string)$status,(string)$order['store_name'],$orderId);$sent=0;$errors=[];
        foreach($devices as$device){$result=$this->send((string)$device['push_token'],$creds['project_id'],$access,['type'=>'customer.order.status','order_id'=>(string)$orderId,'tenant_id'=>(string)$order['tenant_id'],'status'=>$status,'title'=>$title,'message'=>$message]);if($result['ok']){$sent++;continue;}if($result['invalid']){$pdo->prepare('UPDATE delivery_customer_push_devices SET active=0,last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$device['id']]);continue;}$errors[]=$result['error'];}
        if($errors)throw new RuntimeException('FCM do cliente temporariamente indisponível: '.mb_substr(implode(' | ',array_unique($errors)),0,800));
        return['sent'=>$sent,'devices'=>count($devices)];
    }

    private function copy(string$status,string$store,int$orderId):array{return match(strtolower($status)){
        'confirmed'=>['Pedido confirmado','O '.$store.' confirmou o pedido #'.$orderId.'.'],
        'preparing'=>['Seu pedido está sendo preparado','A cozinha começou a preparar o pedido #'.$orderId.'.'],
        'ready'=>['Pedido pronto','O pedido #'.$orderId.' está pronto e aguardando a entrega.'],
        'out_for_delivery'=>['Saiu para entrega','O pedido #'.$orderId.' saiu para entrega. Você já pode acompanhar a rota.'],
        'completed'=>['Pedido entregue','O pedido #'.$orderId.' foi entregue. Bom apetite!'],
        'cancelled'=>['Pedido cancelado','O pedido #'.$orderId.' foi cancelado. Abra o app para ver os detalhes.'],
        default=>['Atualização do pedido #'.$orderId,'O status do seu pedido foi atualizado.'],
    };}

    private function send(string$token,string$projectId,string$access,array$data):array
    {
        $body=['message'=>['token'=>$token,'notification'=>['title'=>$data['title'],'body'=>$data['message']],'data'=>array_map('strval',$data),'android'=>['priority'=>'high','ttl'=>'3600s']]];
        $response=$this->request('https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send',['Authorization: Bearer '.$access,'Content-Type: application/json'],json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$http=$response['http'];if($http>=200&&$http<300)return['ok'=>true,'invalid'=>false,'error'=>''];$decoded=json_decode($response['body'],true);$msg=(string)($decoded['error']['message']??('HTTP '.$http));$invalid=$http===404||($http===400&&preg_match('/registration token|not a valid fcm|unregistered/i',$msg));if($http===401)self::$cachedAccessToken=null;return['ok'=>false,'invalid'=>(bool)$invalid,'error'=>$msg];
    }

    private function accessToken():string
    {
        if(self::$cachedAccessToken&&self::$cachedAccessToken['expires_at']>time()+60)return(string)self::$cachedAccessToken['token'];$c=$this->credentials();$now=time();$header=$this->b64(json_encode(['alg'=>'RS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));$claims=$this->b64(json_encode(['iss'=>$c['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$unsigned=$header.'.'.$claims;$signature='';if(!openssl_sign($unsigned,$signature,$c['private_key'],OPENSSL_ALGO_SHA256))throw new RuntimeException('Não foi possível autenticar o FCM.');$jwt=$unsigned.'.'.$this->b64($signature);$payload=http_build_query(['grant_type'=>'urn:ietf:params:oauth-grant-type:jwt-bearer','assertion'=>$jwt]);$payload=str_replace('oauth-grant-type','oauth2.googleapis.com%2Foauth2%2Fv4%2Ftoken',$payload);$payload=http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]);$response=$this->request('https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],$payload);$data=json_decode($response['body'],true);if($response['http']<200||$response['http']>=300||empty($data['access_token']))throw new RuntimeException('Não foi possível autenticar no Firebase Cloud Messaging.');$ttl=max(300,(int)($data['expires_in']??3600));self::$cachedAccessToken=['token'=>(string)$data['access_token'],'expires_at'=>$now+$ttl];return(string)$data['access_token'];
    }

    private function credentials():array
    {
        $json='';$path=trim((string)env('FCM_SERVICE_ACCOUNT_PATH',''));if($path!==''){$root=dirname(__DIR__,2);if(!str_starts_with($path,'/')&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$path))$path=$root.'/'.ltrim($path,'/\\');if(is_file($path))$json=(string)file_get_contents($path);}if($json===''){$base64=trim((string)env('FCM_SERVICE_ACCOUNT_BASE64',''));if($base64!=='')$json=(string)(base64_decode($base64,true)?:'');}$data=$json!==''?json_decode($json,true):null;if(!is_array($data))$data=[];$project=trim((string)($data['project_id']??env('FCM_PROJECT_ID','')));$email=trim((string)($data['client_email']??env('FCM_CLIENT_EMAIL','')));$key=(string)($data['private_key']??'');if($key===''){$raw=trim((string)env('FCM_PRIVATE_KEY_BASE64',''));if($raw!=='')$key=(string)(base64_decode($raw,true)?:'');}if($project===''||$email===''||$key===''||!str_contains($key,'PRIVATE KEY'))throw new RuntimeException('FCM ainda não configurado.');return['project_id'=>$project,'client_email'=>$email,'private_key'=>$key];
    }
    private function request(string$url,array$headers,string$body):array{$ch=curl_init($url);if($ch===false)throw new RuntimeException('cURL indisponível.');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$response=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($response===false)throw new RuntimeException('Falha de rede ao acessar FCM: '.$error);return['http'=>$http,'body'=>(string)$response];}
    private function b64(string$v):string{return rtrim(strtr(base64_encode($v),'+/','-_'),'=');}
}
