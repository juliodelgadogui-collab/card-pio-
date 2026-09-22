<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppCustomerRegistrationService
{
    private const STATES=['REGISTER_NAME','REGISTER_CPF','REGISTER_ADDRESS','REGISTER_CONFIRM','REGISTER_EDIT'];

    public function __construct(
        private ?CustomerIdentityService $identity=null,
        private ?CustomerAddressService $addresses=null,
    ){
        $this->identity??=new CustomerIdentityService();
        $this->addresses??=new CustomerAddressService();
    }

    public function shouldHandle(PDO $pdo,int $tenantId,array $conversation,string $phone):bool
    {
        $state=(string)($conversation['state']??'IDLE');
        if(in_array($state,self::STATES,true))return true;
        if(!in_array($state,['IDLE','WELCOME'],true))return false;
        $customer=$this->identity->findByPhone($pdo,$tenantId,$phone);
        if($customer)$this->bindConversation($pdo,$tenantId,(int)$conversation['id'],(int)$customer['id']);
        return !$this->profileComplete($customer);
    }

    /** @return array<string,mixed> */
    public function handle(PDO $pdo,int $tenantId,array $conversation,string $text):array
    {
        $conversationId=(int)($conversation['id']??0);$phone=(string)($conversation['phone']??'');$state=(string)($conversation['state']??'IDLE');
        if($conversationId<1||$tenantId<1||$phone==='')throw new RuntimeException('Conversa inválida para cadastro.');
        $context=$this->context($conversation['context_json']??null);$normalized=$this->normalize($text);

        if(!in_array($state,self::STATES,true))return $this->begin($pdo,$tenantId,$conversationId,$phone);

        if($state==='REGISTER_NAME'){
            $name=mb_substr(trim($text),0,160);
            if(mb_strlen($name)<2||ctype_digit($name))return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_NAME',$context,"Para fazer seu cadastro, informe seu *nome completo*.\n\nExemplo: Maria da Silva",'registration_name_invalid');
            $customer=$this->identity->findOrCreate($pdo,$tenantId,$name,$phone);$customerId=(int)$customer['id'];$this->bindConversation($pdo,$tenantId,$conversationId,$customerId);
            if(!empty($context['registration_edit']))return $this->confirmation($pdo,$tenantId,$conversationId,$phone,$customerId);
            return $this->nextMissing($pdo,$tenantId,$conversationId,$phone,$customerId);
        }

        if($state==='REGISTER_CPF'){
            $cpf=$this->identity->normalizeCpf($text);
            if(!$this->identity->isValidCpf($cpf))return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_CPF',$context,"CPF inválido. Envie um *CPF válido* com 11 números.\n\nVocê pode enviar com ou sem pontuação.",'registration_cpf_invalid');
            $customerId=$this->customerId($pdo,$tenantId,$conversationId,$phone);if($customerId<1)return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_NAME',[],"Antes de continuar, informe seu *nome completo*.",'registration_name');
            $pdo->prepare('UPDATE customers SET document=? WHERE id=? AND tenant_id=?')->execute([$cpf,$customerId,$tenantId]);
            if(!empty($context['registration_edit']))return $this->confirmation($pdo,$tenantId,$conversationId,$phone,$customerId);
            return $this->nextMissing($pdo,$tenantId,$conversationId,$phone,$customerId);
        }

        if($state==='REGISTER_ADDRESS'){
            $customerId=$this->customerId($pdo,$tenantId,$conversationId,$phone);if($customerId<1)return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_NAME',[],"Antes de continuar, informe seu *nome completo*.",'registration_name');
            try{$this->addresses->saveText($pdo,$tenantId,$customerId,$text,'Principal',true);}catch(RuntimeException $e){return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_ADDRESS',$context,"Não consegui salvar esse endereço.\n\n".$e->getMessage()."\n\nExemplo: Rua das Flores, 120, Centro, Bom Jesus do Itabapoana - RJ",'registration_address_invalid');}
            return $this->confirmation($pdo,$tenantId,$conversationId,$phone,$customerId);
        }

        if($state==='REGISTER_CONFIRM'){
            $customerId=$this->customerId($pdo,$tenantId,$conversationId,$phone);if($customerId<1)return $this->begin($pdo,$tenantId,$conversationId,$phone);
            if($normalized==='2'||in_array($normalized,['corrigir','editar','alterar'],true))return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_EDIT',['customer_id'=>$customerId],"✏️ *O que deseja corrigir?*\n\n1 - Nome\n2 - CPF\n3 - Endereço\n0 - Voltar para confirmação",'registration_edit');
            if($normalized!=='1'&&!in_array($normalized,['confirmar','confirmo','ok'],true))return $this->confirmation($pdo,$tenantId,$conversationId,$phone,$customerId,'Digite *1* para confirmar ou *2* para corrigir seus dados.');
            $customer=$this->customer($pdo,$tenantId,$customerId);if(!$this->profileComplete($customer))return $this->nextMissing($pdo,$tenantId,$conversationId,$phone,$customerId);
            $this->audit('registration_completed',$conversationId,$customerId);
            return $this->stateResult($pdo,$tenantId,$conversationId,'WELCOME',[],"✅ *Cadastro concluído!*\n\nSeu telefone do WhatsApp ficou vinculado ao cadastro e o CPF poderá ser reutilizado para gerar o PIX.\n\nComo posso ajudar?\n\n1 - Fazer um pedido\n2 - Repetir último pedido\n3 - Acompanhar pedido\n4 - Falar com atendente",'registration_completed',['registration_complete'=>true,'customer_id'=>$customerId]);
        }

        if($state==='REGISTER_EDIT'){
            $customerId=$this->customerId($pdo,$tenantId,$conversationId,$phone);if($customerId<1)return $this->begin($pdo,$tenantId,$conversationId,$phone);
            if($normalized==='0'||$normalized==='voltar')return $this->confirmation($pdo,$tenantId,$conversationId,$phone,$customerId);
            $editContext=['customer_id'=>$customerId,'registration_edit'=>true];
            if($normalized==='1')return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_NAME',$editContext,"Informe o *nome completo* correto:",'registration_edit_name');
            if($normalized==='2')return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_CPF',$editContext,"Informe o *CPF correto*.\n\nEnvie com ou sem pontuação.",'registration_edit_cpf');
            if($normalized==='3')return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_ADDRESS',$editContext,"Informe o *endereço completo* correto.\n\nExemplo: Rua das Flores, 120, Centro, Bom Jesus do Itabapoana - RJ",'registration_edit_address');
            return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_EDIT',$editContext,"Escolha o dado que deseja corrigir:\n\n1 - Nome\n2 - CPF\n3 - Endereço\n0 - Voltar",'registration_edit_invalid');
        }

        return $this->begin($pdo,$tenantId,$conversationId,$phone);
    }

    /** @return array<string,mixed> */
    private function begin(PDO $pdo,int $tenantId,int $conversationId,string $phone):array
    {
        $customer=$this->identity->findByPhone($pdo,$tenantId,$phone);
        if($customer){$customerId=(int)$customer['id'];$this->bindConversation($pdo,$tenantId,$conversationId,$customerId);return $this->nextMissing($pdo,$tenantId,$conversationId,$phone,$customerId);}
        return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_NAME',[],"👋 Antes do primeiro pedido, preciso fazer um cadastro rápido.\n\nSeu número de WhatsApp já foi identificado automaticamente.\n\nQual é o seu *nome completo*?",'registration_start');
    }

    /** @return array<string,mixed> */
    private function nextMissing(PDO $pdo,int $tenantId,int $conversationId,string $phone,int $customerId):array
    {
        $customer=$this->customer($pdo,$tenantId,$customerId);$this->bindConversation($pdo,$tenantId,$conversationId,$customerId);
        if(trim((string)($customer['name']??''))==='')return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_NAME',['customer_id'=>$customerId],"Qual é o seu *nome completo*?",'registration_name');
        if(!$this->identity->isValidCpf((string)($customer['document']??'')))return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_CPF',['customer_id'=>$customerId],"Obrigado, ".trim((string)$customer['name']).". ✅\n\nAgora informe seu *CPF*.\nEle será reutilizado quando for necessário gerar pagamento via PIX.\n\nEnvie com ou sem pontuação.",'registration_cpf');
        if(trim((string)($customer['default_address']??''))==='')return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_ADDRESS',['customer_id'=>$customerId],"📍 Agora informe seu *endereço completo*.\n\nExemplo: Rua das Flores, 120, Centro, Bom Jesus do Itabapoana - RJ",'registration_address');
        return $this->confirmation($pdo,$tenantId,$conversationId,$phone,$customerId);
    }

    /** @return array<string,mixed> */
    private function confirmation(PDO $pdo,int $tenantId,int $conversationId,string $phone,int $customerId,string $prefix=''):array
    {
        $customer=$this->customer($pdo,$tenantId,$customerId);if(!$this->profileComplete($customer))return $this->nextMissing($pdo,$tenantId,$conversationId,$phone,$customerId);
        $lines=[];if($prefix!==''){$lines[]='⚠️ '.$prefix;$lines[]='';}$lines[]='✅ *CONFIRME SEU CADASTRO*';$lines[]='';$lines[]='Nome: '.trim((string)$customer['name']);$lines[]='WhatsApp: '.$this->formatPhone($phone);$lines[]='CPF: '.$this->identity->maskCpf((string)$customer['document']);$lines[]='Endereço: '.trim((string)$customer['default_address']);$lines[]='';$lines[]='1 - Confirmar cadastro';$lines[]='2 - Corrigir meus dados';
        return $this->stateResult($pdo,$tenantId,$conversationId,'REGISTER_CONFIRM',['customer_id'=>$customerId],implode("\n",$lines),'registration_confirm');
    }

    /** @return array<string,mixed> */
    private function stateResult(PDO $pdo,int $tenantId,int $conversationId,string $state,array $context,string $reply,string $kind,array $extra=[]):array
    {
        $json=$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;
        $pdo->prepare('UPDATE whatsapp_conversations SET state=?,context_json=?,last_activity_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$state,$json,$conversationId,$tenantId]);
        return ['handled'=>true,'state'=>$state,'reply'=>$reply,'kind'=>$kind]+$extra;
    }

    private function customerId(PDO $pdo,int $tenantId,int $conversationId,string $phone):int
    {
        $q=$pdo->prepare('SELECT customer_id FROM whatsapp_conversations WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$conversationId,$tenantId]);$id=(int)($q->fetchColumn()?:0);if($id>0)return$id;
        $customer=$this->identity->findByPhone($pdo,$tenantId,$phone);if(!$customer)return 0;$id=(int)$customer['id'];$this->bindConversation($pdo,$tenantId,$conversationId,$id);return$id;
    }

    /** @return array<string,mixed> */
    private function customer(PDO $pdo,int $tenantId,int $customerId):array
    {
        $q=$pdo->prepare('SELECT id,tenant_id,name,phone,phone_normalized,email,document,default_address FROM customers WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$customerId,$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Cadastro do cliente não encontrado.');return$row;
    }

    private function profileComplete(?array $customer):bool
    {
        return is_array($customer)&&trim((string)($customer['name']??''))!==''&&$this->identity->isValidCpf((string)($customer['document']??''))&&trim((string)($customer['default_address']??''))!=='';
    }

    private function bindConversation(PDO $pdo,int $tenantId,int $conversationId,int $customerId):void
    {
        if($customerId<1)return;$pdo->prepare('UPDATE whatsapp_conversations SET customer_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND (customer_id IS NULL OR customer_id<>?)')->execute([$customerId,$conversationId,$tenantId,$customerId]);
    }

    /** @return array<string,mixed> */
    private function context(mixed $raw):array
    {
        if(!is_string($raw)||trim($raw)==='')return[];$data=json_decode($raw,true);return is_array($data)?$data:[];
    }

    private function normalize(string $text):string
    {
        $value=mb_strtolower(trim($text));$value=preg_replace('/\s+/u',' ',$value)??$value;return$value;
    }

    private function formatPhone(string $phone):string
    {
        $digits=preg_replace('/\D+/','',$phone)??'';if(str_starts_with($digits,'55')&&strlen($digits)>=12){$ddd=substr($digits,2,2);$number=substr($digits,4);return '+55 ('.$ddd.') '.(strlen($number)===9?substr($number,0,5).'-'.substr($number,5):$number);}return $digits!==''?'+'.$digits:'—';
    }

    private function audit(string $event,int $conversationId,int $customerId):void
    {
        try{Auth::audit('whatsapp.'.$event,'customer',(string)$customerId,['conversation_id'=>$conversationId]);}catch(Throwable){}
    }
}
