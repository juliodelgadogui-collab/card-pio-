<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use RuntimeException;

final class OpenPixPixProvider implements PixProviderInterface
{
    use ProviderHttp;
    public function code(): string{return'openpix';}
    public function createCharge(array $context,array $gateway): array
    {
        $config=$gateway['config'];$appId=trim((string)($config['app_id']??$config['token']??''));if($appId==='')throw new RuntimeException('AppID OpenPix não configurado.');$correlation='eventmenu-'.$context['tenant_id'].'-'.$context['payment_id'];$customer=['name'=>(string)$context['customer_name'],'taxID'=>(string)$context['tax_id']];$email=trim((string)($context['customer_email']??''));if(filter_var($email,FILTER_VALIDATE_EMAIL))$customer['email']=$email;$phone=preg_replace('/\D+/','',(string)($context['customer_phone']??''))??'';if(strlen($phone)>=10)$customer['phone']=$phone;$body=['correlationID'=>$correlation,'value'=>(int)$context['amount_cents'],'comment'=>'Pedido EventMenu #'.$context['order_id'],'expiresIn'=>900,'customer'=>$customer];$data=$this->requestJson('OpenPix','POST','https://api.openpix.com.br/api/v1/charge?return_existing=true',['Authorization: '.$appId],$body);$charge=is_array($data['charge']??null)?$data['charge']:[];$copy=trim((string)($charge['brCode']??$data['brCode']??''));if($copy==='')throw new RuntimeException('OpenPix não retornou o PIX copia e cola.');return['external_id'=>(string)($charge['correlationID']??$correlation),'copy_paste'=>$copy,'image_url'=>(string)($charge['qrCodeImage']??''),'image_base64'=>'','expires_at'=>(string)($charge['expiresDate']??''),'raw'=>$data];
    }
}
