<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use PDOException;
use RuntimeException;

final class CustomerIdentityService
{
    public function normalizePhone(string $phone):string
    {
        $digits=preg_replace('/\D+/','',trim($phone))??'';
        if(str_starts_with($digits,'55')&&strlen($digits)>11)$digits=substr($digits,2);
        return substr($digits,0,20);
    }

    public function findByPhone(PDO $pdo,int $tenantId,string $phone):?array
    {
        $normalized=$this->normalizePhone($phone);if($normalized==='')return null;
        $s=$pdo->prepare('SELECT id,tenant_id,name,phone,phone_normalized,email,document,points,default_address FROM customers WHERE tenant_id=? AND phone_normalized=? ORDER BY id ASC LIMIT 1');
        $s->execute([$tenantId,$normalized]);$row=$s->fetch();return $row?:null;
    }

    public function save(PDO $pdo,int $tenantId,array $data,?int $customerId=null):array
    {
        $name=mb_substr(trim((string)($data['name']??'')),0,160);$phone=mb_substr(trim((string)($data['phone']??'')),0,30);$normalized=$this->normalizePhone($phone);$address=mb_substr(trim((string)($data['address']??$data['default_address']??'')),0,1000);$email=mb_strtolower(trim((string)($data['email']??'')));$document=mb_substr(trim((string)($data['document']??'')),0,30);
        if($name==='')throw new RuntimeException('Informe o nome do cliente.');if(strlen($normalized)<10)throw new RuntimeException('Informe um telefone válido com DDD.');if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');
        $dupSql='SELECT id,name FROM customers WHERE tenant_id=? AND phone_normalized=?'.($customerId?' AND id<>?':'').' ORDER BY id ASC LIMIT 1';$dup=$pdo->prepare($dupSql);$args=[$tenantId,$normalized];if($customerId)$args[]=$customerId;$dup->execute($args);$duplicate=$dup->fetch();if($duplicate)throw new RuntimeException('Já existe um cliente com este telefone: '.(string)$duplicate['name'].'. Abra o cadastro existente para continuar.');
        try{
            if($customerId){
                $s=$pdo->prepare('UPDATE customers SET name=?,phone=?,phone_normalized=?,email=?,document=?,default_address=? WHERE id=? AND tenant_id=?');$s->execute([$name,$phone,$normalized,$email?:null,$document?:null,$address?:null,$customerId,$tenantId]);
                $check=$pdo->prepare('SELECT id FROM customers WHERE id=? AND tenant_id=?');$check->execute([$customerId,$tenantId]);if(!$check->fetchColumn())throw new RuntimeException('Cliente não encontrado.');
            }else{
                $s=$pdo->prepare('INSERT INTO customers (tenant_id,name,phone,phone_normalized,email,document,default_address) VALUES (?,?,?,?,?,?,?)');$s->execute([$tenantId,$name,$phone,$normalized,$email?:null,$document?:null,$address?:null]);$customerId=(int)$pdo->lastInsertId();
            }
        }catch(PDOException $e){
            if(in_array((string)$e->getCode(),['23000','19'],true)||str_contains(mb_strtolower($e->getMessage()),'unique'))throw new RuntimeException('Este telefone acabou de ser usado em outro cadastro. Abra o cliente existente para continuar.',0,$e);
            throw $e;
        }
        $s=$pdo->prepare('SELECT id,tenant_id,name,phone,phone_normalized,email,document,points,default_address FROM customers WHERE id=? AND tenant_id=?');$s->execute([$customerId,$tenantId]);return $s->fetch()?:throw new RuntimeException('Não foi possível carregar o cliente salvo.');
    }

    public function findOrCreate(PDO $pdo,int $tenantId,string $name,string $phone,string $address=''):array
    {
        $normalized=$this->normalizePhone($phone);if(strlen($normalized)<10)throw new RuntimeException('Informe um telefone válido com DDD.');
        $existing=$this->findByPhone($pdo,$tenantId,$phone);
        if($existing){
            $updates=[];$args=[];$safeName=mb_substr(trim($name),0,160);$safeAddress=mb_substr(trim($address),0,1000);
            if($safeName!==''&&$safeName!==(string)$existing['name']){$updates[]='name=?';$args[]=$safeName;}
            if($safeAddress!==''&&trim((string)($existing['default_address']??''))===''){$updates[]='default_address=?';$args[]=$safeAddress;}
            if($updates){$args[]=(int)$existing['id'];$args[]=$tenantId;$pdo->prepare('UPDATE customers SET '.implode(',',$updates).' WHERE id=? AND tenant_id=?')->execute($args);$existing=$this->findByPhone($pdo,$tenantId,$phone)??$existing;}
            return $existing;
        }
        try{return $this->save($pdo,$tenantId,['name'=>$name,'phone'=>$phone,'address'=>$address]);}
        catch(RuntimeException $e){$raced=$this->findByPhone($pdo,$tenantId,$phone);if($raced)return $raced;throw $e;}
    }

    public function backfillNormalized(PDO $pdo,int $tenantId):int
    {
        $s=$pdo->prepare('SELECT id,phone FROM customers WHERE tenant_id=? AND phone IS NOT NULL AND (phone_normalized IS NULL OR phone_normalized="")');$s->execute([$tenantId]);$count=0;foreach($s->fetchAll()as$row){$normalized=$this->normalizePhone((string)$row['phone']);if($normalized==='')continue;try{$u=$pdo->prepare('UPDATE customers SET phone_normalized=? WHERE id=? AND tenant_id=?');$u->execute([$normalized,(int)$row['id'],$tenantId]);$count+=$u->rowCount();}catch(PDOException){} }return$count;
    }
}
