<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use RuntimeException;

final class FiscalDanfeService
{
    public function data(int $documentId):array
    {
        Auth::requirePermission('fiscal.issue');$tenantId=Auth::tenantId();if(!$tenantId||$documentId<1)throw new RuntimeException('Documento fiscal inválido.');
        $q=Database::connection()->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$documentId,$tenantId]);$document=$q->fetch();if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');
        $status=(string)$document['status'];if(!in_array($status,['authorized','contingency','cancelled'],true))throw new RuntimeException('Documento ainda não possui representação fiscal imprimível.');
        $snapshot=$this->snapshot($document);$model=(string)$document['model'];$kind=$model==='65'?'nfce':'nfe';
        $xml=$this->bestXml($document);$qr=$model==='65'?$this->qrCode($xml):null;
        $issuer=is_array($snapshot['issuer']??null)?$snapshot['issuer']:[];$recipient=is_array($snapshot['recipient']??null)?$snapshot['recipient']:[];$order=is_array($snapshot['order']??null)?$snapshot['order']:[];$items=is_array($snapshot['items']??null)?$snapshot['items']:[];
        $printItems=[];foreach($items as$item){if(!is_array($item))continue;$quantity=(float)($item['quantity']??0);$unit=(int)($item['unit_price_cents']??0);$total=(int)($item['total_cents']??round($quantity*$unit));$printItems[]=['name'=>(string)($item['name_snapshot']??'Item'),'sku'=>(string)($item['sku']??''),'quantity'=>$quantity,'unit'=>(string)($item['commercial_unit']??'UN'),'unit_price_cents'=>$unit,'total_cents'=>$total,'ncm'=>(string)($item['ncm']??''),'cfop'=>(string)($item['cfop']??'')];}
        return[
            'document'=>[
                'id'=>(int)$document['id'],'kind'=>$kind,'model'=>$model,'status'=>$status,'environment'=>(string)$document['environment'],'series'=>(int)$document['series'],'number'=>(int)$document['document_number'],'access_key'=>(string)($document['access_key']??''),'protocol'=>(string)($document['protocol']??''),'authorized_at'=>$document['authorized_at']??null,'cancelled_at'=>$document['cancelled_at']??null,'contingency_emitted_at'=>$document['contingency_emitted_at']??null,'contingency_synced_at'=>$document['contingency_synced_at']??null,
            ],
            'issuer'=>[
                'legal_name'=>(string)($issuer['legal_name']??''),'trade_name'=>(string)($issuer['trade_name']??''),'cnpj'=>(string)($issuer['cnpj']??''),'state_registration'=>(string)($issuer['state_registration']??''),'street'=>(string)($issuer['street']??''),'number'=>(string)($issuer['address_number']??''),'complement'=>(string)($issuer['address_complement']??''),'district'=>(string)($issuer['district']??''),'city'=>(string)($issuer['city_name']??''),'state'=>(string)($issuer['state_code']??''),'postal_code'=>(string)($issuer['postal_code']??''),'phone'=>(string)($issuer['phone']??''),
            ],
            'recipient'=>$recipient,
            'order'=>['id'=>(int)($order['id']??0),'channel'=>(string)($order['channel']??''),'subtotal_cents'=>(int)($order['subtotal_cents']??0),'discount_cents'=>(int)($order['discount_cents']??0),'delivery_fee_cents'=>(int)($order['delivery_fee_cents']??0),'total_cents'=>(int)($order['total_cents']??0),'created_at'=>$order['created_at']??null],
            'items'=>$printItems,
            'qr_code'=>$qr,
            'print_mode'=>$model==='65'?'nfce_receipt':'nfe_summary',
            'warning'=>$status==='contingency'?'EMITIDA EM CONTINGÊNCIA — aguardando sincronização com a SEFAZ':($status==='cancelled'?'DOCUMENTO CANCELADO':null),
        ];
    }

    private function snapshot(array$document):array
    {
        if(empty($document['snapshot_encrypted'])||empty($document['snapshot_hash']))throw new RuntimeException('Snapshot fiscal não encontrado.');$snapshot=Crypto::decryptJson((string)$document['snapshot_encrypted']);$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);if(!hash_equals((string)$document['snapshot_hash'],hash('sha256',$json)))throw new RuntimeException('Integridade do snapshot fiscal não confere.');return$snapshot;
    }

    private function bestXml(array$document):string
    {
        $encrypted=$document['xml_encrypted']??null;if((string)$document['status']==='contingency'&&!empty($document['contingency_xml_encrypted']))$encrypted=$document['contingency_xml_encrypted'];if(empty($encrypted))return'';try{return Crypto::decrypt((string)$encrypted);}catch(\Throwable){return'';}
    }

    private function qrCode(string$xml):?string
    {
        if($xml==='')return null;$previous=libxml_use_internal_errors(true);try{$root=simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA);if($root===false)return null;$nodes=$root->xpath('//*[local-name()="qrCode"]');if(!$nodes)return null;$value=trim((string)$nodes[0]);if($value===''||strlen($value)>4096)return null;return html_entity_decode($value,ENT_QUOTES|ENT_XML1,'UTF-8');}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
}
