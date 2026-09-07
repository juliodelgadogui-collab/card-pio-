<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use RuntimeException;

final class MercadoPagoPixProvider implements PixProviderInterface
{
    use ProviderHttp;
    public function code(): string{return'mercadopago';}
    public function createCharge(array $context,array $gateway): array
    {
        $config=$gateway['config'];$token=trim((string)($config['access_token']??$config['token']??''));if($token==='')throw new RuntimeException('Access Token Mercado Pago não configurado.');$email=trim((string)($context['customer_email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL))$email='pix+'.(int)$context['tenant_id'].'.'.(int)$context['order_id'].'@eventmenu.invalid';$document=(string)$context['tax_id'];$body=['transaction_amount'=>round(((int)$context['amount_cents'])/100,2),'description'=>'Pedido EventMenu #'.$context['order_id'],'payment_method_id'=>'pix','external_reference'=>$context['reference'],'notification_url'=>$context['webhook_url'],'payer'=>['email'=>$email,'first_name'=>mb_substr((string)$context['customer_name'],0,60),'identification'=>['type'=>strlen($document)===14?'CNPJ':'CPF','number'=>$document]]];$data=$this->requestJson('Mercado Pago','POST','https://api.mercadopago.com/v1/payments',['Authorization: Bearer '.$token,'X-Idempotency-Key: '.$context['idempotency_key']],$body);$tx=$data['point_of_interaction']['transaction_data']??[];$copy=trim((string)($tx['qr_code']??''));if($copy==='')throw new RuntimeException('Mercado Pago não retornou o PIX copia e cola.');$expires=(string)($data['date_of_expiration']??'');return['external_id'=>(string)($data['id']??''),'copy_paste'=>$copy,'image_url'=>'','image_base64'=>(string)($tx['qr_code_base64']??''),'expires_at'=>$expires,'raw'=>$data];
    }
}
