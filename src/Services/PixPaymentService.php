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
        $pdo=Database::connection();$s=$pdo->prepare('SELECT provider,config_encrypted FROM payment_gateways WHERE tenant_id=? AND active=1');$s->execute([$tenantId]);$providers=[];$default='';$hasDefaultDocument=false;
        foreach($s->fetchAll() as $row){$cfg=Crypto::decryptJson($row['config_encrypted']);if(!empty($cfg['pix_enabled']))$providers[]=(string)$row['provider'];if(!empty($cfg['pix_default']))$default=(string)$row['provider'];$doc=$this->digits((string)($cfg['pix_default_document']??''));if(in_array(strlen($doc),[11,14],true))$hasDefaultDocument=true;}
        if($default===''&&$providers)$default=$providers[0];
        return ['enabled'=>(bool)$providers,'default_provider'=>$default,'providers'=>$providers,'requires_document'=>!$hasDefaultDocument,'has_default_document'=>$hasDefaultDocument];
    }

    public function create(int $tenantId,int $orderId,string $document='',string $provider=''): array
    {
        $pdo=Database::connection();$o=$pdo->prepare('SELECT public_token FROM orders WHERE id=? AND tenant_id=?');$o->execute([$orderId,$tenantId]);$token=(string)$o->fetchColumn();if($token==='')throw new RuntimeException('Pedido não encontrado.');
        $cfg=$this->configuration($tenantId);$provider=strtolower(trim($provider));if($provider==='')$provider=(string)$cfg['default_provider'];if($provider===''||!in_array($provider,$cfg['providers'],true))throw new RuntimeException('Pix não configurado para esta empresa.');
        $g=$pdo->prepare('SELECT config_encrypted FROM payment_gateways WHERE tenant_id=? AND provider=? AND active=1 LIMIT 1');$g->execute([$tenantId,$provider]);$encrypted=$g->fetchColumn();if($encrypted===false)throw new RuntimeException('Provedor Pix indisponível.');$providerCfg=Crypto::decryptJson((string)$encrypted);
        $provided=$this->digits($document);$defaultDocument=$this->digits((string)($providerCfg['pix_default_document']??''));$effective=$provided!==''?$provided:$defaultDocument;
        if(!in_array(strlen($effective),[11,14],true))throw new RuntimeException('O Pix precisa de CPF/CNPJ. Informe no pagamento ou configure um documento padrão da empresa.');
        $result=(new CheckoutService())->create($token,$provider,['payment_method'=>'pix','payer_document'=>$effective]);
        return $result+['document_masked'=>$this->mask($effective),'document_source'=>$provided!==''?'customer':'configured_default','payment_method'=>'pix'];
    }

    private function digits(string $value):string{return preg_replace('/\D+/','',$value)??'';}
    private function mask(string $v):string{return strlen($v)===11?'***.***.***-'.substr($v,-2):'**.***.***/****-'.substr($v,-2);}
}
