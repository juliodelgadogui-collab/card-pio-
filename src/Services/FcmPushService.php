<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use RuntimeException;

final class FcmPushService
{
    private static ?array $cachedAccessToken=null;

    public function configured():bool
    {
        try{$this->credentials();return true;}catch(\Throwable){return false;}
    }

    public function sendNotification(int $notificationId):array
    {
        if($notificationId<1)throw new RuntimeException('Notificação push inválida.');
        $pdo=Database::connection();$s=$pdo->prepare('SELECT n.*,u.status user_status FROM app_notifications n JOIN users u ON u.id=n.user_id AND u.tenant_id=n.tenant_id WHERE n.id=? LIMIT 1');$s->execute([$notificationId]);$notification=$s->fetch();if(!$notification)return['sent'=>0,'devices'=>0,'missing'=>true];if((string)$notification['user_status']!=='active')return['sent'=>0,'devices'=>0,'inactive_user'=>true];
        if(!empty($notification['expires_at'])&&strtotime((string)$notification['expires_at'])<=time())return['sent'=>0,'devices'=>0,'expired'=>true];
        if(!empty($notification['read_at']))return['sent'=>0,'devices'=>0,'already_read'=>true];
        $d=$pdo->prepare('SELECT id,push_token FROM push_devices WHERE tenant_id=? AND user_id=? AND active=1 ORDER BY id');$d->execute([(int)$notification['tenant_id'],(int)$notification['user_id']]);$devices=$d->fetchAll();if(!$devices)return['sent'=>0,'devices'=>0,'configured'=>$this->configured()];
        if(!$this->configured())return['sent'=>0,'devices'=>count($devices),'configured'=>false];

        $access=$this->accessToken();$creds=$this->credentials();$sent=0;$transient=[];
        foreach($devices as$device){
            $result=$this->sendToToken((string)$device['push_token'],$notification,$creds['project_id'],$access);
            if($result['ok']){$sent++;continue;}
            if($result['invalid']){$u=$pdo->prepare('UPDATE push_devices SET active=0,updated_at=CURRENT_TIMESTAMP WHERE id=?');$u->execute([$device['id']]);continue;}
            $transient[]=$result['error'];
        }
        if($transient)throw new RuntimeException('FCM temporariamente indisponível: '.mb_substr(implode(' | ',array_unique($transient)),0,1000));
        return['sent'=>$sent,'devices'=>count($devices),'configured'=>true];
    }

    private function sendToToken(string $token,array $notification,string $projectId,string $accessToken):array
    {
        $type=(string)$notification['type'];$mode=(string)($notification['mode']??'');$ttlSeconds=3600;
        $data=[
            'notification_id'=>(string)$notification['id'],
            'notification_type'=>$type,
            'type'=>$type,
            'priority'=>(string)$notification['priority'],
            'tenant_id'=>(string)$notification['tenant_id'],
            'user_id'=>(string)$notification['user_id'],
            'title'=>(string)$notification['title'],
            'message'=>(string)$notification['message'],
        ];
        if($mode!==''){$data['notification_mode']=$mode;$data['mode']=$mode;}
        foreach(['entity_type','entity_id']as$key)if(!empty($notification[$key]))$data[$key]=(string)$notification[$key];
        if(!empty($notification['expires_at'])){$expiresAt=(string)$notification['expires_at'];$expiresEpoch=strtotime($expiresAt);$data['expires_at']=$expiresAt;if($expiresEpoch!==false){$data['expires_at_epoch']=(string)$expiresEpoch;$ttlSeconds=max(60,min(3600,$expiresEpoch-time()));}}
        $body=['message'=>[
            'token'=>$token,
            'data'=>$data,
            'android'=>[
                'priority'=>in_array((string)$notification['priority'],['critical','warning'],true)?'high':'normal',
                'ttl'=>$ttlSeconds.'s',
            ],
        ]];
        $url='https://fcm.googleapis.com/v1/projects/'.rawurlencode($projectId).'/messages:send';$response=$this->request($url,['Authorization: Bearer '.$accessToken,'Content-Type: application/json'],json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$http=$response['http'];
        if($http>=200&&$http<300)return['ok'=>true,'invalid'=>false,'error'=>''];
        $decoded=json_decode($response['body'],true);$status=(string)($decoded['error']['status']??'');$message=(string)($decoded['error']['message']??('HTTP '.$http));$invalid=$http===404||($http===400&&preg_match('/registration token|not a valid fcm|unregistered/i',$message));
        if($invalid)return['ok'=>false,'invalid'=>true,'error'=>$message];
        if($http===401){self::$cachedAccessToken=null;throw new RuntimeException('Credencial FCM recusada. Revise a conta de serviço.');}
        return['ok'=>false,'invalid'=>false,'error'=>$status!==''?$status.': '.$message:$message];
    }

    private function accessToken():string
    {
        if(self::$cachedAccessToken&&self::$cachedAccessToken['expires_at']>time()+60)return(string)self::$cachedAccessToken['token'];
        $c=$this->credentials();$now=time();$header=$this->base64Url(json_encode(['alg'=>'RS256','typ'=>'JWT'],JSON_THROW_ON_ERROR));$claims=$this->base64Url(json_encode(['iss'=>$c['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3600],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));$unsigned=$header.'.'.$claims;$signature='';if(!openssl_sign($unsigned,$signature,$c['private_key'],OPENSSL_ALGO_SHA256))throw new RuntimeException('Não foi possível assinar a autenticação FCM.');$jwt=$unsigned.'.'.$this->base64Url($signature);
        $payload=http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]);$response=$this->request('https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],$payload);$data=json_decode($response['body'],true);if($response['http']<200||$response['http']>=300||empty($data['access_token']))throw new RuntimeException('Não foi possível autenticar no Firebase Cloud Messaging.');$ttl=max(300,(int)($data['expires_in']??3600));self::$cachedAccessToken=['token'=>(string)$data['access_token'],'expires_at'=>$now+$ttl];return(string)$data['access_token'];
    }

    private function credentials():array
    {
        $json='';$path=trim((string)env('FCM_SERVICE_ACCOUNT_PATH',''));if($path!==''){$root=dirname(__DIR__,2);if(!str_starts_with($path,'/')&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$path))$path=$root.'/'.ltrim($path,'/\\');if(is_file($path))$json=(string)file_get_contents($path);}
        if($json===''){$base64=trim((string)env('FCM_SERVICE_ACCOUNT_BASE64',''));if($base64!=='')$json=(string)(base64_decode($base64,true)?:'');}
        $data=$json!==''?json_decode($json,true):null;if(!is_array($data))$data=[];
        $project=trim((string)($data['project_id']??env('FCM_PROJECT_ID','')));$email=trim((string)($data['client_email']??env('FCM_CLIENT_EMAIL','')));$key=(string)($data['private_key']??'');if($key===''){$raw=trim((string)env('FCM_PRIVATE_KEY_BASE64',''));if($raw!=='')$key=(string)(base64_decode($raw,true)?:'');}
        if($project===''||$email===''||$key==='')throw new RuntimeException('FCM ainda não configurado.');if(!str_contains($key,'PRIVATE KEY'))throw new RuntimeException('Chave privada FCM inválida.');return['project_id'=>$project,'client_email'=>$email,'private_key'=>$key];
    }

    private function request(string $url,array $headers,string $body):array
    {
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('cURL indisponível.');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$response=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);if($response===false)throw new RuntimeException('Falha de rede ao acessar serviço push: '.$error);return['http'=>$http,'body'=>(string)$response];
    }

    private function base64Url(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
}
