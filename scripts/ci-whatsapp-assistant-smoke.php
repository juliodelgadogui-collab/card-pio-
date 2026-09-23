<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\WhatsAppCommerceAssistantService;

function wa_ai_fail(string $message): never
{
    fwrite(STDERR, "WHATSAPP ASSISTANT CI FAIL: {$message}\n");
    exit(1);
}

function wa_ai_assert(bool $condition,string $message):void
{
    if(!$condition)wa_ai_fail($message);
}

$pdo=Database::connection();
if(Database::driver($pdo)!=='sqlite')wa_ai_fail('Este smoke test exige SQLite.');

foreach(['whatsapp_assistant_settings','whatsapp_assistant_knowledge']as$table){
    $q=$pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);wa_ai_assert((string)$q->fetchColumn()===$table,"Tabela {$table} não foi criada pela migração 077.");
}

$slug='wa-ai-ci-'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Lanchonete IA CI',$slug]);
$tenantId=(int)$pdo->lastInsertId();wa_ai_assert($tenantId>0,'Tenant de teste não foi criado.');

$service=new WhatsAppCommerceAssistantService();
$defaults=$service->settings($pdo,$tenantId);
wa_ai_assert((int)($defaults['enabled']??0)===1,'Assistente deveria nascer ativo por padrão.');
wa_ai_assert($service->unknownBehavior($defaults)==='menu','Fallback padrão deveria voltar ao menu.');

$saved=$service->saveSettings($pdo,$tenantId,[
    'enabled'=>1,
    'knowledge_enabled'=>1,
    'assistant_name'=>'Bia',
    'tone'=>'friendly',
    'greeting_message'=>'Oi, {cliente}! Eu sou a {assistente} da {empresa}.',
    'unknown_behavior'=>'human',
    'unknown_message'=>'Ainda não tenho essa informação.',
    'handoff_message'=>'Vou chamar alguém da equipe para você.',
    'handoff_keywords'=>'gerente, pessoa, falar com alguém',
]);
wa_ai_assert(($saved['assistant_name']??'')==='Bia','Nome do assistente não foi persistido.');
wa_ai_assert($service->shouldTransfer('GERENTE',$saved),'Palavra personalizada não transferiu para humano.');
wa_ai_assert($service->shouldTransfer('quero falar com atendente',$saved),'Comando seguro padrão de atendente foi perdido.');

$welcome=$service->welcome($saved,'Maria','Lanchonete IA CI');
wa_ai_assert(str_contains($welcome,'Oi, Maria!'),'Variável {cliente} não foi renderizada.');
wa_ai_assert(str_contains($welcome,'Bia'),'Variável {assistente} não foi renderizada.');
wa_ai_assert(str_contains($welcome,'Lanchonete IA CI'),'Variável {empresa} não foi renderizada.');
wa_ai_assert(str_contains($welcome,'1 - Fazer um pedido'),'Menu comercial deixou de ser anexado à saudação.');

$parkingId=$service->saveKnowledge($pdo,$tenantId,[
    'title'=>'Vocês têm estacionamento?',
    'answer'=>'Sim. Temos estacionamento gratuito ao lado da entrada.',
    'keywords'=>'estacionamento, vaga, carro',
    'enabled'=>1,
    'sort_order'=>10,
]);
wa_ai_assert($parkingId>0,'Item da Base da IA não foi criado.');
$deliveryId=$service->saveKnowledge($pdo,$tenantId,[
    'title'=>'Faz entrega no bairro Centro?',
    'answer'=>'Sim. A taxa de entrega é calculada pelo EventMenu no fechamento do pedido.',
    'keywords'=>'bairro centro, entrega centro',
    'enabled'=>1,
    'sort_order'=>20,
]);
wa_ai_assert($deliveryId>0,'Segundo item da Base da IA não foi criado.');

$answer=$service->answer($pdo,$tenantId,'Tem vaga para carro no estacionamento?');
wa_ai_assert(is_array($answer),'Pergunta com correspondência forte não consultou a Base da IA.');
wa_ai_assert((int)($answer['id']??0)===$parkingId,'Base da IA escolheu resposta incorreta.');
wa_ai_assert(str_contains((string)($answer['answer']??''),'estacionamento gratuito'),'Resposta cadastrada foi alterada ou perdida.');

$delivery=$service->answer($pdo,$tenantId,'Vocês fazem entrega no bairro Centro?');
wa_ai_assert(is_array($delivery)&&(int)($delivery['id']??0)===$deliveryId,'Palavra/frase-chave de entrega não encontrou a resposta correta.');
wa_ai_assert($service->answer($pdo,$tenantId,'Vocês vendem computadores?')===null,'Base da IA inventou correspondência sem confiança.');
wa_ai_assert($service->unknownBehavior($saved)==='human','Configuração de desconhecido não preservou transferência humana.');

$service->saveKnowledge($pdo,$tenantId,['id'=>$parkingId,'title'=>'Tem estacionamento?','answer'=>'Sim. O estacionamento é gratuito.','keywords'=>'estacionamento, vaga','enabled'=>1,'sort_order'=>5]);
$updated=$service->answer($pdo,$tenantId,'Tem estacionamento?');
wa_ai_assert(is_array($updated)&&($updated['answer']??'')==='Sim. O estacionamento é gratuito.','Edição da Base da IA não entrou em vigor.');

$otherSlug='wa-ai-other-'.bin2hex(random_bytes(4));
$pdo->prepare('INSERT INTO tenants (name,slug,plan,status) VALUES (?,?,"premium","active")')->execute(['Outra Empresa',$otherSlug]);
$otherTenant=(int)$pdo->lastInsertId();
wa_ai_assert($service->knowledge($pdo,$otherTenant)===[],'Base da IA vazou conhecimento entre empresas.');
wa_ai_assert($service->answer($pdo,$otherTenant,'Tem estacionamento?')===null,'Resposta da Base da IA vazou entre tenants.');

$service->deleteKnowledge($pdo,$tenantId,$parkingId);
$items=$service->knowledge($pdo,$tenantId);wa_ai_assert(count($items)===1&&(int)$items[0]['id']===$deliveryId,'Exclusão da Base da IA não respeitou o tenant/item.');

$route=(string)file_get_contents(dirname(__DIR__).'/app/routes/whatsapp.php');
$commerce=(string)file_get_contents(dirname(__DIR__).'/src/Services/WhatsAppCommerceService.php');
wa_ai_assert(str_contains($route,'Base da IA')&&str_contains($route,'save_assistant')&&str_contains($route,'save_knowledge'),'Tela do WhatsApp Commerce não expõe configuração/base do assistente.');
wa_ai_assert(str_contains($commerce,'WhatsAppCommerceAssistantService')&&str_contains($commerce,"'knowledge_'"),'Fluxo inbound não está integrado ao assistente/base.');

echo "WhatsApp Commerce Assistant smoke: OK\n";
