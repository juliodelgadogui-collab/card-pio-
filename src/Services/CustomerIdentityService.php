<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class CustomerIdentityService
{
    public function normalizePhone(string $phone): string
    {
        $digits=preg_replace('/\D+/', '', trim($phone))??'';
        if(str_starts_with($digits,'55')&&strlen($digits)>11)$digits=substr($digits,2);
        return substr($digits,0,20);
    }

    public function findByPhone(PDO $pdo,int $tenantId,string $phone):?array
    {
        $normalized=$this->normalizePhone($phone);if($normalized==='')return null;
        $s=$pdo->prepare('SELECT id,tenant_id,name,phone,email,document,points,default_address FROM customers WHERE tenant_id=? AND phone_normalized=? ORDER BY id DESC LIMIT 1');
        $s->execute([$tenantId,$normalized]);$row=$s->fetch();return$row?:null;
    }

    public function save(PDO $pdo,int $tenantId,array $data,?int $customerId=null):array
    {
        $name=mb_substr(trim((string)($data['name']??'')),0,160);$phone=mb_substr(trim((string)($data['phone']??'')),0,30);$normalized=$this->normalizePhone($phone);$address=mb_substr(trim((string)($data['address']??'')),0,1000);$email=mb_strtolower(trim((string)($data['email']??'')));
        if($name==='')throw new RuntimeException('Informe o nome do cliente.');if($normalized==='')throw new RuntimeException('Informe um telefone válido.');if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('E-mail inválido.');
        $dup=$pdo->prepare('SELECT id FROM customers WHERE tenant_id=? AND phone_normalized=?'.($customerId?' AND id<>?':'').' LIMIT 1');$args=[$tenantId,$normalized];if($customerId)$args[]=$customerId;$dup->execute($args);$duplicate=$dup->fetchColumn();if($duplicate!==false)throw new RuntimeException('Este telefone já pertence a outro cliente.');
        if($customerId){$s=$pdo->prepare('UPDATE customers SET name=?,phone=?,phone_normalized=?,email=?,default_address=? WHERE id=? AND tenant_id=?');$s->execute([$name,$phone,$normalized,$email?:null,$address?:null,$customerId,$tenantId]);if($s->rowCount()===0){$check=$pdo->prepare('SELECT id FROM customers WHERE id=? AND tenant_id=?');$check->execute([$customerId,$tenantId]);if(!$check->fetchColumn())throw new RuntimeException('Cliente não encontrado.');}}
        else{$s=$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized,email,default_address) VALUES (?,?,?,?,?,?)');$s->execute([$tenantId,$name,$phone,$normalized,$email?:null,$address?:null]);$customerId=(int)$pdo->lastInsertId();}
        $s=$pdo->prepare('SELECT id,tenant_id,name,phone,email,document,points,default_address FROM customers WHERE id=? AND tenant_id=?');$s->execute([$customerId,$tenantId]);return$s->fetch()?:throw new RuntimeException('Não foi possível carregar o cliente salvo.');
    }
}
