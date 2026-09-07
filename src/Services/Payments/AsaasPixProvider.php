<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use RuntimeException;

final class AsaasPixProvider implements PixProviderInterface
{
    use ProviderHttp;
    public function code(): string{return'asaas';}
    public function createCharge(array $context,array $gateway): array
    {
        $config=$gateway['config'];$token=trim((string)($config['api_key']??$config['access_token']??''));if($token==='')throw new RuntimeException('API Key Asaas não configurada.');$base=rtrim((string)($config['api_base']??'https://api.asaas.com/v3'),'/');$headers=['access_token: '.$token];$document=(string)$context['tax_id'];$customerRef='eventmenu-customer-'.$context['tenant_id'].'-'.$document;$found=$this->requestJson('Asaas','GET',$base.'/customers?'.http_build_query(['cpfCnpj'=>$document,'limit'=>1]),$headers);$customerId='';if(!empty($found['data'][0]['id']))$customerId=(string)$found['data'][0]['id'];if($customerId===''){$customer=['name'=>(string)$context['customer_name'],'cpfCnpj'=>$document,'externalReference'=>$customerRef,'notificationDisabled'=>true];$email=trim((string)($context['customer_email']??''));if(filter_var($email,FILTER_VALIDATE_EMAIL))$customer['email']=$email;$phone=preg_replace('/\D+/','',(string)($context['customer_phone']??''))??'';if(strlen($phone)>=10)$customer['mobilePhone']=$phone;$createdCustomer=$this->requestJson('Asaas','POST',$base.'/customers',$headers,$customer);$customerId=trim((string)($createdCustomer['id']??''));if($customerId==='')throw new RuntimeException('Asaas não retornou o cliente da cobrança.');}$payment=$this->requestJson('Asaas','POST',$base.'/payments',$headers,['customer'=>$customerId,'billingType'=>'PIX','value'=>round(((int)$context['amount_cents'])/100,2),'dueDate'=>date('Y-m-d'),'description'=>'Pedido EventMenu #'.$context['order_id'],'externalReference'=>$context['reference']]);$paymentId=trim((string)($payment['id']??''));if($paymentId==='')throw new RuntimeException('Asaas não retornou o identificador da cobrança.');$qr=$this->requestJson('Asaas','GET',$base.'/payments/'.rawurlencode($paymentId).'/pixQrCode',$headers);$copy=trim((string)($qr['payload']??''));if($copy==='')throw new RuntimeException('Asaas não retornou o PIX copia e cola.');return['external_id'=>$paymentId,'copy_paste'=>$copy,'image_url'=>'','image_base64'=>(string)($qr['encodedImage']??''),'expires_at'=>(string)($qr['expirationDate']??''),'raw'=>['payment'=>$payment,'pix_qr'=>$qr]];
    }
    public function verifyCharge(array $payment,array $gateway): array
    {
        $config=$gateway['config'];$token=trim((string)($config['api_key']??$config['access_token']??''));$id=trim((string)($payment['provider_payment_id']??''));if($token===''||$id==='')throw new RuntimeException('Cobrança Asaas não está pronta para consulta.');$base=rtrim((string)($config['api_base']??'https://api.asaas.com/v3'),'/');$data=$this->requestJson('Asaas','GET',$base.'/payments/'.rawurlencode($id),['access_token: '.$token]);$status=strtoupper((string)($data['status']??''));$paid=$status==='RECEIVED';$amount=(int)round(((float)($data['value']??0))*100);return['paid'=>$paid,'provider_payment_id'=>(string)($data['id']??$id),'amount_cents'=>$amount,'currency'=>'BRL','account_reference'=>(string)$gateway['account_reference'],'raw_status'=>$status,'raw'=>$data];
    }
}
