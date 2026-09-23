<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;
use Throwable;

final class WhatsAppCommerceAssistantService
{
    private const TONES=['friendly','professional','direct','casual'];
    private const UNKNOWN_BEHAVIORS=['menu','human'];
    private const DEFAULT_HANDOFF=['atendente','humano','falar com atendente','quero falar com atendente','falar com humano','quero falar com humano'];
    private const STOPWORDS=['a','as','o','os','um','uma','uns','umas','de','da','das','do','dos','e','em','no','na','nos','nas','para','por','com','sem','que','qual','quais','como','tem','ter','vocês','voces','você','voce','eu','me','meu','minha','meus','minhas','isso','esse','essa','este','esta','onde','quando','quanto','quantos','quantas','pode','podem'];

    /** @return array<string,mixed> */
    public function defaults():array
    {
        return [
            'enabled'=>1,
            'knowledge_enabled'=>1,
            'assistant_name'=>'Assistente EventMenu',
            'tone'=>'friendly',
            'greeting_message'=>'Olá, {cliente}! 👋 Como posso ajudar?',
            'unknown_behavior'=>'menu',
            'unknown_message'=>'Não encontrei uma informação segura para responder isso. Posso te ajudar pelo menu ou chamar um atendente.',
            'handoff_message'=>'Certo! 👤 Seu atendimento foi encaminhado para a equipe. O atendimento automático ficará pausado enquanto você aguarda.',
            'handoff_keywords'=>'atendente, humano, falar com atendente, falar com humano',
        ];
    }

    /** @return array<string,mixed> */
    public function settings(PDO $pdo,int $tenantId):array
    {
        $defaults=$this->defaults();if($tenantId<1)return$defaults;
        try{$q=$pdo->prepare('SELECT enabled,knowledge_enabled,assistant_name,tone,greeting_message,unknown_behavior,unknown_message,handoff_message,handoff_keywords FROM whatsapp_assistant_settings WHERE tenant_id=? LIMIT 1');$q->execute([$tenantId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row))return$defaults;return array_merge($defaults,$row);}catch(Throwable){return$defaults;}
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function saveSettings(PDO $pdo,int $tenantId,array $input):array
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');
        $assistantName=$this->limit($input['assistant_name']??'Assistente EventMenu',80,'O nome do assistente');
        if($assistantName==='')$assistantName='Assistente EventMenu';
        $tone=strtolower(trim((string)($input['tone']??'friendly')));if(!in_array($tone,self::TONES,true))throw new RuntimeException('Estilo de atendimento inválido.');
        $greeting=$this->limit($input['greeting_message']??'',1500,'A mensagem de boas-vindas');if($greeting==='')$greeting=(string)$this->defaults()['greeting_message'];
        $unknownBehavior=strtolower(trim((string)($input['unknown_behavior']??'menu')));if(!in_array($unknownBehavior,self::UNKNOWN_BEHAVIORS,true))throw new RuntimeException('Comportamento para pergunta desconhecida inválido.');
        $unknown=$this->limit($input['unknown_message']??'',1500,'A mensagem para informação desconhecida');if($unknown==='')$unknown=(string)$this->defaults()['unknown_message'];
        $handoff=$this->limit($input['handoff_message']??'',1200,'A mensagem de transferência');if($handoff==='')$handoff=(string)$this->defaults()['handoff_message'];
        $keywords=implode(', ',$this->normalizeKeywords((string)($input['handoff_keywords']??'')));
        $enabled=!empty($input['enabled'])?1:0;$knowledgeEnabled=!empty($input['knowledge_enabled'])?1:0;
        $driver=strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if($driver==='sqlite'){
            $sql='INSERT INTO whatsapp_assistant_settings (tenant_id,enabled,knowledge_enabled,assistant_name,tone,greeting_message,unknown_behavior,unknown_message,handoff_message,handoff_keywords,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON CONFLICT(tenant_id) DO UPDATE SET enabled=excluded.enabled,knowledge_enabled=excluded.knowledge_enabled,assistant_name=excluded.assistant_name,tone=excluded.tone,greeting_message=excluded.greeting_message,unknown_behavior=excluded.unknown_behavior,unknown_message=excluded.unknown_message,handoff_message=excluded.handoff_message,handoff_keywords=excluded.handoff_keywords,updated_at=CURRENT_TIMESTAMP';
        }else{
            $sql='INSERT INTO whatsapp_assistant_settings (tenant_id,enabled,knowledge_enabled,assistant_name,tone,greeting_message,unknown_behavior,unknown_message,handoff_message,handoff_keywords,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),knowledge_enabled=VALUES(knowledge_enabled),assistant_name=VALUES(assistant_name),tone=VALUES(tone),greeting_message=VALUES(greeting_message),unknown_behavior=VALUES(unknown_behavior),unknown_message=VALUES(unknown_message),handoff_message=VALUES(handoff_message),handoff_keywords=VALUES(handoff_keywords),updated_at=CURRENT_TIMESTAMP';
        }
        $pdo->prepare($sql)->execute([$tenantId,$enabled,$knowledgeEnabled,$assistantName,$tone,$greeting,$unknownBehavior,$unknown,$handoff,$keywords]);
        return$this->settings($pdo,$tenantId);
    }

    /** @return array<int,array<string,mixed>> */
    public function knowledge(PDO $pdo,int $tenantId,bool $onlyEnabled=false):array
    {
        if($tenantId<1)return[];try{$sql='SELECT id,title,answer,keywords,enabled,sort_order,created_at,updated_at FROM whatsapp_assistant_knowledge WHERE tenant_id=?'.($onlyEnabled?' AND enabled=1':'').' ORDER BY sort_order ASC,id DESC';$q=$pdo->prepare($sql);$q->execute([$tenantId]);return$q->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return[];}
    }

    /** @param array<string,mixed> $input */
    public function saveKnowledge(PDO $pdo,int $tenantId,array $input):int
    {
        if($tenantId<1)throw new RuntimeException('Empresa inválida.');$id=(int)($input['id']??0);
        $title=$this->limit($input['title']??'',180,'A pergunta/título');if($title==='')throw new RuntimeException('Informe uma pergunta ou título para a Base da IA.');
        $answer=$this->limit($input['answer']??'',3000,'A resposta');if($answer==='')throw new RuntimeException('Informe a resposta da Base da IA.');
        $keywords=implode(', ',$this->normalizeKeywords((string)($input['keywords']??''),40));$enabled=!empty($input['enabled'])?1:0;$sort=max(0,min(9999,(int)($input['sort_order']??0)));
        if($id>0){$q=$pdo->prepare('UPDATE whatsapp_assistant_knowledge SET title=?,answer=?,keywords=?,enabled=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');$q->execute([$title,$answer,$keywords,$enabled,$sort,$id,$tenantId]);if($q->rowCount()<1){$check=$pdo->prepare('SELECT id FROM whatsapp_assistant_knowledge WHERE id=? AND tenant_id=?');$check->execute([$id,$tenantId]);if(!$check->fetchColumn())throw new RuntimeException('Item da Base da IA não encontrado.');}return$id;}
        $pdo->prepare('INSERT INTO whatsapp_assistant_knowledge (tenant_id,title,answer,keywords,enabled,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)')->execute([$tenantId,$title,$answer,$keywords,$enabled,$sort]);return(int)$pdo->lastInsertId();
    }

    public function deleteKnowledge(PDO $pdo,int $tenantId,int $id):void
    {if($tenantId<1||$id<1)throw new RuntimeException('Item inválido.');$q=$pdo->prepare('DELETE FROM whatsapp_assistant_knowledge WHERE id=? AND tenant_id=?');$q->execute([$id,$tenantId]);}

    /** @return array{id:int,title:string,answer:string,score:int}|null */
    public function answer(PDO $pdo,int $tenantId,string $question):?array
    {
        $settings=$this->settings($pdo,$tenantId);if((int)($settings['enabled']??0)!==1||(int)($settings['knowledge_enabled']??0)!==1)return null;
        $query=$this->normalize($question);if(mb_strlen($query)<3)return null;$queryTokens=$this->tokens($query);$best=null;$bestScore=0;
        foreach($this->knowledge($pdo,$tenantId,true)as$row){$title=$this->normalize((string)($row['title']??''));$keywords=$this->normalizeKeywords((string)($row['keywords']??''),40);$score=0;$strong=false;
            if($query===$title&&$title!==''){$score=120;$strong=true;}
            elseif(mb_strlen($title)>=4&&(str_contains($query,$title)||str_contains($title,$query))){$score+=70;$strong=true;}
            foreach($keywords as$keyword){$needle=$this->normalize($keyword);if(mb_strlen($needle)>=3&&str_contains($query,$needle)){$score+=50;$strong=true;}}
            $referenceTokens=$this->tokens($title.' '.implode(' ',$keywords));$overlap=count(array_intersect($queryTokens,$referenceTokens));$score+=$overlap*14;if(!$strong&&$overlap<2)continue;if($score>$bestScore){$bestScore=$score;$best=['id'=>(int)$row['id'],'title'=>(string)$row['title'],'answer'=>(string)$row['answer'],'score'=>$score];}
        }
        return$bestScore>=42?$best:null;
    }

    /** @param array<string,mixed>|null $settings */
    public function shouldTransfer(string $message,?array $settings=null):bool
    {$normalized=$this->normalizeCommand($message);if($normalized==='')return false;$settings=$settings??$this->defaults();$commands=self::DEFAULT_HANDOFF;foreach($this->normalizeKeywords((string)($settings['handoff_keywords']??''),30)as$keyword)$commands[]=$this->normalizeCommand($keyword);return in_array($normalized,array_values(array_unique(array_filter($commands))),true);}

    /** @param array<string,mixed> $settings */
    public function welcome(array $settings,string $customerName,string $tenantName=''):string
    {$intro=(int)($settings['enabled']??0)===1?(string)($settings['greeting_message']??''):(string)$this->defaults()['greeting_message'];$intro=$this->render($intro,$customerName,$tenantName,(string)($settings['assistant_name']??'Assistente EventMenu'));if($intro==='')$intro=$customerName!==''?'Olá, '.$customerName.'! 👋':'Olá! 👋';return$intro."\n\nO que deseja fazer?\n\n1 - Fazer um pedido\n2 - Repetir último pedido (em breve)\n3 - Acompanhar pedido\n4 - Falar com atendente\n\nVocê também pode digitar *MENU*, *MEU PEDIDO*, *CANCELAR* ou *ATENDENTE* a qualquer momento.";}

    /** @param array<string,mixed> $settings */
    public function unknown(array $settings):string
    {$message=trim((string)($settings['unknown_message']??''));if($message==='')$message=(string)$this->defaults()['unknown_message'];return$message."\n\nDigite *MENU* para voltar ao início ou *ATENDENTE* para falar com uma pessoa.";}

    /** @param array<string,mixed> $settings */
    public function handoff(array $settings):string
    {$message=trim((string)($settings['handoff_message']??''));return$message!==''?$message:(string)$this->defaults()['handoff_message'];}

    public function isGreeting(string $message):bool
    {return in_array($this->normalizeCommand($message),['oi','ola','olá','bom dia','boa tarde','boa noite','e ai','e aí'],true);}

    /** @param array<string,mixed> $settings */
    public function unknownBehavior(array $settings):string
    {$value=strtolower(trim((string)($settings['unknown_behavior']??'menu')));return in_array($value,self::UNKNOWN_BEHAVIORS,true)?$value:'menu';}

    private function render(string $template,string $customerName,string $tenantName,string $assistantName):string
    {return trim(strtr($template,['{cliente}'=>$customerName!==''?$customerName:'cliente','{empresa}'=>$tenantName,'{assistente}'=>$assistantName]));}

    private function limit(mixed $value,int $max,string $label):string
    {$value=trim((string)$value);if(mb_strlen($value)>$max)throw new RuntimeException($label.' pode ter no máximo '.$max.' caracteres.');return$value;}

    /** @return array<int,string> */
    private function normalizeKeywords(string $value,int $max=30):array
    {$parts=preg_split('/[,;\n\r]+/u',$value)?:[];$out=[];foreach($parts as$part){$part=trim((string)$part);if($part===''||mb_strlen($part)<2)continue;$part=mb_substr($part,0,100);$key=$this->normalize($part);if($key===''||isset($out[$key]))continue;$out[$key]=$part;if(count($out)>=$max)break;}return array_values($out);}

    private function normalizeCommand(string $value):string
    {$value=mb_strtolower(trim($value));$value=preg_replace('/\s+/u',' ',$value)??$value;return trim($value," \t\n\r\0\x0B.!?,;:");}

    private function normalize(string $value):string
    {$value=$this->normalizeCommand($value);$value=strtr($value,['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c']);$value=preg_replace('/[^a-z0-9 ]+/u',' ',$value)??$value;return trim(preg_replace('/\s+/u',' ',$value)??$value);}

    /** @return array<int,string> */
    private function tokens(string $value):array
    {$parts=preg_split('/\s+/u',$this->normalize($value))?:[];$out=[];foreach($parts as$part){if(mb_strlen($part)<3||in_array($part,self::STOPWORDS,true))continue;$out[$part]=true;}return array_keys($out);}
}
