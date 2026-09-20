<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use RuntimeException;

final class DeliveryCustomerDocumentService
{
    public function protectCpf(string $value):array
    {
        $cpf=$this->normalizeCpf($value);
        $key=(string)\env('APP_KEY','');
        if($key==='')throw new RuntimeException('APP_KEY não configurada.');
        return [
            'cpf_encrypted'=>Crypto::encrypt($cpf),
            'cpf_hash'=>hash_hmac('sha256',$cpf,$key),
            'cpf_last2'=>substr($cpf,-2),
        ];
    }

    public function cpfFromAccount(array $account):string
    {
        $encrypted=trim((string)($account['cpf_encrypted']??''));
        if($encrypted==='')return '';
        $cpf=Crypto::decrypt($encrypted);
        return $this->normalizeCpf($cpf);
    }

    public function summary(array $account):array
    {
        $configured=trim((string)($account['cpf_encrypted']??''))!=='';
        $last2=preg_replace('/\D+/','',(string)($account['cpf_last2']??''))??'';
        return [
            'cpf_configured'=>$configured,
            'cpf_masked'=>$configured&&strlen($last2)===2?'***.***.***-'.$last2:'',
        ];
    }

    public function normalizeCpf(string $value):string
    {
        $cpf=preg_replace('/\D+/','',$value)??'';
        if(strlen($cpf)!==11||preg_match('/^(\d)\1{10}$/',$cpf))throw new RuntimeException('Informe um CPF válido.');
        for($t=9;$t<11;$t++){
            $sum=0;
            for($i=0;$i<$t;$i++)$sum+=(int)$cpf[$i]*(($t+1)-$i);
            $digit=(10*($sum%11))%11;
            if($digit===10)$digit=0;
            if((int)$cpf[$t]!==$digit)throw new RuntimeException('Informe um CPF válido.');
        }
        return $cpf;
    }
}
