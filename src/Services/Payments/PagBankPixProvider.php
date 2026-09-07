<?php

declare(strict_types=1);

namespace EventMenu\Services\Payments;

use RuntimeException;

final class PagBankPixProvider implements PixProviderInterface
{
    use ProviderHttp;
    public function code(): string{return'pagbank';}
    public function createCharge(array $context,array $gateway): array
    {
        $config=$gateway['config'];$token=trim((string)($config['token']??''));if($token==='')throw new RuntimeException('Token PagBank não configurado.');$expires=(new \DateTimeImmutable('+15 minutes'))->format(DATE_ATOM);$body=['reference_id'=>$context['reference'],'customer'=>$context['pagbank_customer'],'items'=>[['reference_id'=>'order-'.$context['order_id'].'-part-'.$context['payment_id'],'name'=>'Pedido EventMenu #'.$context['order_id'],'quantity'=>1,'unit_amount'=>$context['amount_cents']]],'charges'=>[['reference_id'=>'go-pix-'.$context['order_id'].'-'.$context['payment_id'],'description'=>'Pedido EventMenu #'.$context['order_id'],'amount'=>['value'=>$context['amount_cents'],'currency'=>'BRL'],'payment_method'=>['type'=>'PIX','pix'=>['expiration_date'=>$expires]]]],'notification_urls'=>[$context['webhook_url']]];$api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');$data=$this->requestJson('PagBank','POST',$api.'/orders',['Authorization: Bearer '.$token,'x-idempotency-key: '.$context['idempotency_key']],$body);$charge=$data['charges'][0]??null;$qr=is_array($charge)?($charge['qr_code']??null):null;if(!is_array($qr)||empty($qr['text']))throw new RuntimeException('PagBank não retornou o PIX copia e cola.');$image='';foreach(($charge['links']??[])as$link)if(($link['rel']??'')==='QRCODE.PNG'){$image=(string)($link['href']??'');break;}return['external_id'=>(string)($data['id']??''),'copy_paste'=>(string)$qr['text'],'image_url'=>$image,'image_base64'=>'','expires_at'=>$expires,'raw'=>$data];
    }
    public function verifyCharge(array $payment,array $gateway): array
    {
        $config=$gateway['config'];$token=trim((string)($config['token']??''));$external=trim((string)($payment['provider_payment_id']??''));if($token===''||$external==='')throw new RuntimeException('Cobrança PagBank não está pronta para consulta.');$api=rtrim((string)($config['api_base']??'https://api.pagseguro.com'),'/');$order=$this->requestJson('PagBank','GET',$api.'/orders/'.rawurlencode($external),['Authorization: Bearer '.$token]);$paid=null;foreach(($order['charges']??[])as$charge)if(strtoupper((string)($charge['status']??''))==='PAID'){$paid=$charge;break;}$amount=$paid?(int)($paid['amount']['value']??0):0;$currency=$paid?strtoupper((string)($paid['amount']['currency']??'BRL')):'BRL';return['paid'=>$paid!==null,'provider_payment_id'=>(string)($paid['id']??$external),'amount_cents'=>$amount,'currency'=>$currency,'account_reference'=>(string)$gateway['account_reference'],'raw_status'=>(string)($paid['status']??($order['charges'][0]['status']??'')),'raw'=>$order];
    }
}
