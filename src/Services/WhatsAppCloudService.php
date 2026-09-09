<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class WhatsAppCloudService
{
    /**
     * @param list<string> $bodyParameters
     * @return array{message_id:?string,to:string}
     */
    public function sendTemplate(int $tenantId,string $toPhone,string $template,string $language,array $bodyParameters):array
    {
        $settings=(new WhatsAppSettingsService())->effective($tenantId);
        if(!$settings)throw new RuntimeException('WhatsApp Cloud API não configurado para esta empresa.');
        if(!function_exists('curl_init'))throw new RuntimeException('Extensão cURL do PHP é necessária para enviar WhatsApp.');

        $to=$this->normalizePhone($toPhone);
        $phoneId=preg_replace('/\D+/','',(string)$settings['phone_number_id'])?:'';
        if($phoneId==='')throw new RuntimeException('Phone Number ID do WhatsApp não configurado.');
        $graph=(string)$settings['graph_version'];
        if(!preg_match('/^v\d{1,2}\.\d$/',$graph))throw new RuntimeException('Versão da Graph API inválida.');

        $params=[];
        foreach($bodyParameters as $value)$params[]=['type'=>'text','text'=>mb_substr((string)$value,0,1024)];
        $payload=[
            'messaging_product'=>'whatsapp','recipient_type'=>'individual','to'=>$to,'type'=>'template',
            'template'=>['name'=>$template,'language'=>['code'=>$language],'components'=>[['type'=>'body','parameters'=>$params]]],
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $url='https://graph.facebook.com/'.$graph.'/'.$phoneId.'/messages';
        $ch=curl_init($url);
        if($ch===false)throw new RuntimeException('Não foi possível iniciar o envio para o WhatsApp.');
        curl_setopt_array($ch,[
            CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,
            CURLOPT_HTTPHEADER=>['Authorization: Bearer '.(string)$settings['access_token'],'Content-Type: application/json'],
            CURLOPT_POSTFIELDS=>$json,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        ]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($raw===false||$error!=='')throw new RuntimeException('Falha de conexão com o WhatsApp Cloud API.');
        $decoded=json_decode((string)$raw,true);
        if($status<200||$status>=300){
            $message=is_array($decoded)?(string)($decoded['error']['message']??''):'';
            throw new RuntimeException('WhatsApp recusou a mensagem (HTTP '.$status.'). '.mb_substr($message,0,220));
        }
        $messageId=is_array($decoded)?($decoded['messages'][0]['id']??null):null;
        return['message_id'=>is_string($messageId)?$messageId:null,'to'=>$to];
    }

    public function normalizePhone(string $phone):string
    {
        $digits=preg_replace('/\D+/','',$phone)?:'';
        if(strlen($digits)===10||strlen($digits)===11)$digits='55'.$digits;
        if(strlen($digits)<10||strlen($digits)>15)throw new RuntimeException('Número de WhatsApp inválido.');
        return $digits;
    }
}
