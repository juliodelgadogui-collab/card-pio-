<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class PixPaymentService
{
    public function configuration(int $tenantId): array
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT provider,config_encrypted FROM payment_gateways WHERE tenant_id=? AND active=1');$s->execute([$tenantId]);$providers=[];$default='';
        foreach($s->fetchAll() as $row){$cfg=Crypto::decryptJson($row['config_encrypted']);if(!empty($cfg['pix_enabled']))$providers[]=(string)$row['provider'];if(!empty($cfg['pix_default']))$default=(string)$row['provider'];}
        if($default===''&&$providers)$default=$providers[0];
        return ['enabled'=>(bool)$providers,'default_provider'=>$default,'providers'=>$providers,'requires_document'=>true];
    }

    public function create(int $tenantId,int $orderId,string $document='',string $provider=''): array
    {
        $document=preg_replace('/\D+/','',$document)??'';if(!in_array(strlen($document),[11,14],true))throw new RuntimeException('Informe um CPF ou CNPJ válido para gerar o Pix.');
        $pdo=Database::connection();$o=$pdo->prepare('SELECT public_token FROM orders WHERE id=? AND tenant_id=?');$o->execute([$orderId,$tenantId]);$token=(string)$o->fetchColumn();if($token==='')throw new RuntimeException('Pedido não encontrado.');
        $cfg=$this->configuration($tenantId);$provider=strtolower(trim($provider));if($provider==='')$provider=(string)$cfg['default_provider'];if($provider===''||!in_array($provider,$cfg['providers'],true))throw new RuntimeException('Pix não configurado para esta empresa.');
        $result=(new CheckoutService())->create($token,$provider,['payment_method'=>'pix','payer_document'=>$document]);
        return $result+['document_masked'=>$this->mask($document),'payment_method'=>'pix'];
    }

    private function mask(string $v):string{return strlen($v)===11?'***.***.***-'.substr($v,-2):'**.***.***/****-'.substr($v,-2);}
}
