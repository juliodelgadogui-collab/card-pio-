<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use RuntimeException;

final class PagarMePixProvider implements PixProviderInterface
{
    use ProviderHttp;
    public function code(): string{return'pagarme';}
    public function createCharge(array $context,array $gateway): array
    {
        $config=$gateway['config'];$key=trim((string)($config['secret_key']??$config['api_key']??''));if($key==='')throw new RuntimeException('Chave secreta Pagar.me não configurada.');$phone=preg_replace('/\D+/','',(string)($context['customer_phone']??''))??'';$local=substr($phone,-11);if(strlen($local)<10)throw new RuntimeException('Pagar.me exige telefone válido do cliente para PIX.');$email=trim((string)($context['customer_email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Pagar.me exige e-mail válido do cliente para PIX.');$document=(string)$context['tax_id'];$body=['code'=>$context['reference'],'items'=>[['amount'=>(int)$context['amount_cents'],'description'=>'Pedido EventMenu #'.$context['order_id'],'quantity'=>1,'code'=>'eventmenu-order-'.$context['order_id']]],'customer'=>['name'=>(string)$context['customer_name'],'email'=>$email,'type'=>strlen($document)===14?'company':'individual','document'=>$document,'phones'=>['mobile_phone'=>['country_code'=>'55','area_code'=>substr($local,0,2),'number'=>substr($local,2)]]],'payments'=>[['payment_method'=>'pix','pix'=>['expires_in'=>900]]]];$data=$this->requestJson('Pagar.me','POST','https://api.pagar.me/core/v5/orders',[],$body,$key.':');$charge=$data['charges'][0]??null;$tx=is_array($charge)?($charge['last_transaction']??null):null;if(!is_array($tx)||empty($tx['qr_code']))throw new RuntimeException('Pagar.me não retornou o PIX copia e cola.');return['external_id'=>(string)($charge['id']??$data['id']??''),'copy_paste'=>(string)$tx['qr_code'],'image_url'=>(string)($tx['qr_code_url']??''),'image_base64'=>'','expires_at'=>(string)($tx['expires_at']??''),'raw'=>$data];
    }
}
