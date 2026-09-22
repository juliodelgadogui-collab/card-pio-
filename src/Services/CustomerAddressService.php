<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class CustomerAddressService
{
    /** @return array<int,array<string,mixed>> */
    public function list(PDO $pdo,int $tenantId,int $customerId,int $limit=8):array
    {
        if($tenantId<1||$customerId<1)return[];
        $limit=max(1,min(20,$limit));
        $this->syncLegacyDefault($pdo,$tenantId,$customerId);
        $q=$pdo->prepare('SELECT id,label,address_text,is_default,source,created_at,updated_at FROM customer_addresses WHERE tenant_id=? AND customer_id=? ORDER BY is_default DESC,id DESC LIMIT '.$limit);
        $q->execute([$tenantId,$customerId]);
        return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /** @return array<string,mixed> */
    public function get(PDO $pdo,int $tenantId,int $customerId,int $addressId):array
    {
        $q=$pdo->prepare('SELECT id,label,address_text,is_default,source,created_at,updated_at FROM customer_addresses WHERE id=? AND tenant_id=? AND customer_id=? LIMIT 1');
        $q->execute([$addressId,$tenantId,$customerId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Endereço salvo não encontrado.');
        return $row;
    }

    /** @return array<string,mixed> */
    public function saveText(PDO $pdo,int $tenantId,int $customerId,string $address,string $label='WhatsApp',bool $makeDefault=true):array
    {
        $address=$this->sanitize($address);$label=mb_substr(trim($label),0,80)?:'WhatsApp';
        if($tenantId<1||$customerId<1)throw new RuntimeException('Cliente inválido para salvar endereço.');
        if(mb_strlen($address)<8)throw new RuntimeException('Informe um endereço mais completo para entrega.');
        if($makeDefault)$pdo->prepare('UPDATE customer_addresses SET is_default=0,updated_at=CURRENT_TIMESTAMP WHERE tenant_id=? AND customer_id=?')->execute([$tenantId,$customerId]);
        $existing=$pdo->prepare('SELECT id FROM customer_addresses WHERE tenant_id=? AND customer_id=? AND address_text=? LIMIT 1');$existing->execute([$tenantId,$customerId,$address]);$id=(int)($existing->fetchColumn()?:0);
        if($id>0)$pdo->prepare('UPDATE customer_addresses SET label=?,is_default=?,source="whatsapp",updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND customer_id=?')->execute([$label,$makeDefault?1:0,$id,$tenantId,$customerId]);
        else{$pdo->prepare('INSERT INTO customer_addresses (tenant_id,customer_id,label,address_text,is_default,source) VALUES (?,?,?,?,?,"whatsapp")')->execute([$tenantId,$customerId,$label,$address,$makeDefault?1:0]);$id=(int)$pdo->lastInsertId();}
        if($makeDefault)$pdo->prepare('UPDATE customers SET default_address=? WHERE id=? AND tenant_id=?')->execute([$address,$customerId,$tenantId]);
        return $this->get($pdo,$tenantId,$customerId,$id);
    }

    private function syncLegacyDefault(PDO $pdo,int $tenantId,int $customerId):void
    {
        $count=$pdo->prepare('SELECT COUNT(*) FROM customer_addresses WHERE tenant_id=? AND customer_id=?');$count->execute([$tenantId,$customerId]);if((int)$count->fetchColumn()>0)return;
        $q=$pdo->prepare('SELECT default_address FROM customers WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$customerId,$tenantId]);$address=$this->sanitize((string)($q->fetchColumn()?:''));if($address==='')return;
        $pdo->prepare('INSERT INTO customer_addresses (tenant_id,customer_id,label,address_text,is_default,source) VALUES (?,?,"Principal",?,1,"legacy")')->execute([$tenantId,$customerId,$address]);
    }

    private function sanitize(string $value):string
    {
        $value=trim(preg_replace('/\s+/u',' ',str_replace(["\r","\n"],' ',$value))??$value);
        return mb_substr($value,0,1000);
    }
}
