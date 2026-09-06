<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class TabSplitPixService
{
    public function create(int $groupId,string $taxId):array
    {
        Auth::requirePermission('payments.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$taxId=$this->validTaxId($taxId);
        $pdo=Database::connection();$g=$pdo->prepare('SELECT pg.*,t.slug tenant_slug FROM payment_groups pg JOIN tenants t ON t.id=pg.tenant_id WHERE pg.id=? AND pg.tenant_id=? LIMIT 1');$g->execute([$groupId,$tenantId]);$group=$g->fetch();if(!$group||$group['provider']!=='pagbank'||$group['method']!=='pix')throw new RuntimeException('Grupo PIX inválido.');
        if($group['status']==='paid')return $this->status($groupId);if(!in_array($group['status'],['created','pending'],true))throw new RuntimeException('Grupo não aceita PIX neste estado.');
        $raw=json_decode((string)($group['raw_payload']??''),true);if($group['status']==='pending'&&is_array($raw)&&!empty($raw['_eventmenu_pix_text']))return $this->response($group,$raw,true);

        $gateway=$this->gateway($tenantId);$config=$gateway['config'];$token=trim((string)($config['token']??''));if($token==='')throw new RuntimeException('Token PagBank não configurado.');
        $key='tab-split-pix:'.$tenantId.':'.$groupId;$expires=(new \DateTimeImmutable('+15 minutes'))->format(DATE_ATOM);
        $webhook=\app_absolute_url('webhook-group.php?provider=pagbank&tenant='.rawurlencode((string)$group['tenant_slug']));
        $body=[
            'reference_id'=>'eventmenu-group:'.$tenantId.':'.$groupId,
            'customer'=>['name'=>'Cliente EventMenu','tax_id'=>$taxId],
            'items'=>[['reference_id'=>'payment-group-'.$groupId,'name'=>'Divisão de comanda EventMenu #'.$group['tab_id'],'quantity'=>1,'unit_amount'=>(int)$group['amount_cents']]],
            'charges'=>[['reference_id'=>'payment-group-'.$groupId,'description'=>'Comanda EventMenu #'.$group['tab_id'],'amount'=>['value'=>(int)$group['amount_cents'],'currency'=>'BRL'],'payment_method'=>['type'=>'PIX','pix'=>['expiration_date'=>$expires]]]],
            'notification_urls'=>[$webhook],
        ];
        try{
            $api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');$data=$this->httpJson('POST',$api.'/orders',['Authorization: Bearer '.$token,'x-idempotency-key: '.$key],$body);$charge=$data['charges'][0]??null;if(!is_array($charge))throw new RuntimeException('PagBank não retornou a cobrança PIX.');$qr=$charge['qr_code']??null;if(!is_array($qr)||empty($qr['text']))throw new RuntimeException('PagBank não retornou o PIX copia e cola.');
            $data['_eventmenu_pix_text']=(string)$qr['text'];$data['_eventmenu_expires_at']=$expires;$data['_eventmenu_group_id']=$groupId;
            (new TabSplitPaymentService())->markPending($groupId,(string)($data['id']??''),$data);Auth::audit('tab.payment_group_pix_created','payment_group',(string)$groupId,['amount_cents'=>(int)$group['amount_cents']]);$group['provider_payment_id']=(string)($data['id']??'');$group['status']='pending';return $this->response($group,$data,false);
        }catch(\Throwable $e){$this->failBeforeProviderConfirmation($groupId,$tenantId,['error'=>$e->getMessage()]);throw $e;}
    }

    public function status(int $groupId):array
    {
        Auth::requirePermission('payments.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');$service=new TabSplitPaymentService();$group=$service->status($groupId);if(in_array($group['status'],['paid','attention','failed','cancelled','refunded'],true))return ['group'=>$group,'paid'=>$group['status']==='paid'];
        $raw=json_decode((string)($group['raw_payload']??''),true);$providerOrder=(string)($group['provider_payment_id']??'');if($providerOrder==='')return ['group'=>$group,'paid'=>false];
        $gateway=$this->gateway($tenantId);$config=$gateway['config'];$token=trim((string)($config['token']??''));$api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');$order=$this->httpJson('GET',$api.'/orders/'.rawurlencode($providerOrder),['Authorization: Bearer '.$token]);$paid=null;$terminal=false;
        foreach(($order['charges']??[]) as $charge){$status=(string)($charge['status']??'');if($status==='PAID'){$paid=$charge;break;}if(in_array($status,['DECLINED','CANCELED'],true))$terminal=true;}
        if($paid){$group=$service->settlePagBank($groupId,(string)($paid['id']??$providerOrder),(int)($paid['amount']['value']??0),(string)$gateway['account_reference'],(string)($paid['status']??''),$order);return ['group'=>$group,'paid'=>$group['status']==='paid'];}
        $expires=(string)($raw['_eventmenu_expires_at']??'');if($terminal||($expires!==''&&strtotime($expires)<time())){$this->failBeforeProviderConfirmation($groupId,$tenantId,$order);$group=$service->status($groupId);}
        return ['group'=>$group,'paid'=>false];
    }

    public function processWebhook(string $tenantSlug,string $rawBody,array $headers):array
    {
        $pdo=Database::connection();$g=$pdo->prepare('SELECT pg.id gateway_id,pg.account_reference,pg.config_encrypted,pg.webhook_secret_encrypted,t.id tenant_id FROM payment_gateways pg JOIN tenants t ON t.id=pg.tenant_id WHERE t.slug=? AND t.status="active" AND pg.provider="pagbank" AND pg.active=1 LIMIT 1');$g->execute([$tenantSlug]);$gateway=$g->fetch();if(!$gateway)throw new RuntimeException('Gateway PagBank não localizado.');
        $secret=Crypto::decrypt($gateway['webhook_secret_encrypted']);if($secret==='')throw new RuntimeException('Segredo do webhook não configurado.');$received=$this->header($headers,'x-authenticity-token');$expected=hash('sha256',$secret.'-'.$rawBody);if($received===''||!hash_equals(strtolower($expected),strtolower(trim($received))))throw new RuntimeException('Assinatura PagBank inválida.');
        $payload=json_decode($rawBody,true,512,JSON_THROW_ON_ERROR);$external=(string)($payload['id']??'');if($external==='')throw new RuntimeException('Pedido PagBank ausente.');$config=Crypto::decryptJson($gateway['config_encrypted']);$token=trim((string)($config['token']??''));$api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');$order=$this->httpJson('GET',$api.'/orders/'.rawurlencode($external),['Authorization: Bearer '.$token]);
        if(!preg_match('/^eventmenu-group:(\d+):(\d+)$/',(string)($order['reference_id']??''),$m))throw new RuntimeException('Referência do grupo inválida.');$tenantId=(int)$m[1];$groupId=(int)$m[2];if($tenantId!==(int)$gateway['tenant_id'])throw new RuntimeException('Empresa divergente no grupo PIX.');
        $eventId='group:'.$external.':'.hash('sha256',$rawBody);try{$pdo->prepare('INSERT INTO webhook_events (tenant_id,provider,external_event_id,signature_valid,payload_hash,status) VALUES (?,"pagbank",?,1,?,"received")')->execute([$tenantId,$eventId,hash('sha256',$rawBody)]);}catch(\PDOException $e){if((string)$e->getCode()==='23000'||str_contains(strtolower($e->getMessage()),'unique'))return ['ok'=>true,'duplicate'=>true];throw $e;}
        $paid=null;foreach(($order['charges']??[]) as $charge)if(($charge['status']??'')==='PAID'){$paid=$charge;break;}
        if(!$paid){$pdo->prepare('UPDATE webhook_events SET status="ignored",processed_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND provider="pagbank" AND external_event_id=?')->execute([$tenantId,$eventId]);return ['ok'=>true,'processed'=>false];}
        try{(new TabSplitPaymentService())->settlePagBank($groupId,(string)($paid['id']??$external),(int)($paid['amount']['value']??0),(string)$gateway['account_reference'],(string)($paid['status']??''),$order);$pdo->prepare('UPDATE webhook_events SET status="processed",processed_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND provider="pagbank" AND external_event_id=?')->execute([$tenantId,$eventId]);return ['ok'=>true,'processed'=>true];}catch(\Throwable $e){$pdo->prepare('UPDATE webhook_events SET status="failed" WHERE tenant_id=? AND provider="pagbank" AND external_event_id=?')->execute([$tenantId,$eventId]);throw $e;}
    }

    private function failBeforeProviderConfirmation(int $groupId,int $tenantId,array $raw):void
    {
        Database::transaction(function(\PDO $pdo)use($groupId,$tenantId,$raw):void{$s=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM payment_groups WHERE id=? AND tenant_id=? FOR UPDATE'));$s->execute([$groupId,$tenantId]);$group=$s->fetch();if(!$group||!in_array($group['status'],['created','pending'],true))return;$paid=$pdo->prepare('SELECT COUNT(*) FROM payments WHERE tenant_id=? AND payment_group_id=? AND status IN ("paid","duplicate_paid")');$paid->execute([$tenantId,$groupId]);if((int)$paid->fetchColumn()>0)return;$pdo->prepare('UPDATE payment_groups SET status="failed",raw_payload=? WHERE id=?')->execute([json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$groupId]);$pdo->prepare('UPDATE payments SET status="cancelled" WHERE tenant_id=? AND payment_group_id=? AND status="authorized"')->execute([$tenantId,$groupId]);$a=$pdo->prepare('SELECT order_id FROM payment_group_allocations WHERE tenant_id=? AND payment_group_id=?');$a->execute([$tenantId,$groupId]);foreach($a->fetchAll() as $row){$p=$pdo->prepare('SELECT COALESCE(SUM(amount_cents),0) FROM payments WHERE tenant_id=? AND order_id=? AND status="paid"');$p->execute([$tenantId,$row['order_id']]);$pdo->prepare('UPDATE orders SET payment_status=? WHERE id=? AND tenant_id=? AND payment_status<>"paid"')->execute([(int)$p->fetchColumn()>0?'pending':'unpaid',$row['order_id'],$tenantId]);}});
    }

    private function gateway(int $tenantId):array
    {
        $s=Database::connection()->prepare('SELECT * FROM payment_gateways WHERE tenant_id=? AND provider="pagbank" AND active=1 LIMIT 1');$s->execute([$tenantId]);$gateway=$s->fetch();if(!$gateway)throw new RuntimeException('PagBank não está ativo.');$gateway['config']=Crypto::decryptJson($gateway['config_encrypted']);return $gateway;
    }
    private function response(array $group,array $raw,bool $reused):array{return ['group_id'=>(int)$group['id'],'tab_id'=>(int)$group['tab_id'],'amount_cents'=>(int)$group['amount_cents'],'copy_paste'=>(string)$raw['_eventmenu_pix_text'],'expires_at'=>(string)($raw['_eventmenu_expires_at']??''),'status'=>(string)$group['status'],'reused'=>$reused];}
    private function validTaxId(string $value):string{$v=preg_replace('/\D+/','',$value)??'';if(!in_array(strlen($v),[11,14],true)||preg_match('/^(\d)\1+$/',$v))throw new RuntimeException('CPF/CNPJ inválido.');if(strlen($v)===11){for($t=9;$t<11;$t++){$sum=0;for($i=0;$i<$t;$i++)$sum+=(int)$v[$i]*(($t+1)-$i);$d=(10*($sum%11))%11;if($d===10)$d=0;if((int)$v[$t]!==$d)throw new RuntimeException('CPF inválido.');}return $v;}$calc=function(string $base,array $weights):int{$sum=0;foreach($weights as $i=>$w)$sum+=(int)$base[$i]*$w;$r=$sum%11;return $r<2?0:11-$r;};$d1=$calc(substr($v,0,12),[5,4,3,2,9,8,7,6,5,4,3,2]);$d2=$calc(substr($v,0,12).$d1,[6,5,4,3,2,9,8,7,6,5,4,3,2]);if((int)$v[12]!==$d1||(int)$v[13]!==$d2)throw new RuntimeException('CNPJ inválido.');return $v;}
    private function header(array $headers,string $name):string{$name=strtolower($name);foreach($headers as $k=>$v)if(strtolower((string)$k)===$name)return is_array($v)?(string)reset($v):(string)$v;return '';}
    private function httpJson(string $method,string $url,array $headers,array $body=[]):array{$ch=curl_init($url);if($ch===false)throw new RuntimeException('Falha HTTP PagBank.');$headers[]='Accept: application/json';$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25];if($method!=='GET'){$headers[]='Content-Type: application/json';$options[CURLOPT_HTTPHEADER]=$headers;$options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}curl_setopt_array($ch,$options);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);if($response===false||$status<200||$status>=300)throw new RuntimeException('PagBank recusou a operação (HTTP '.$status.')'.($err?' '.$err:''));$data=json_decode((string)$response,true,512,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Resposta PagBank inválida.');return $data;}
}
