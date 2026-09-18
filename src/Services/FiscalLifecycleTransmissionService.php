<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class FiscalLifecycleTransmissionService
{
    public function transmitCancellation(int $tenantId,int $eventId,FiscalLifecycleTransmitterInterface $transmitter):array
    {
        $claimed=$this->claimEvent($tenantId,$eventId,'cancel');
        try{
            $result=$transmitter->cancel($claimed['document'],$claimed['event']);
            return $this->applyCancellation($tenantId,$eventId,$result);
        }catch(Throwable $e){$this->eventError($tenantId,$eventId,$e->getMessage());throw$e;}
    }

    public function transmitInutilization(int $tenantId,int $id,FiscalLifecycleTransmitterInterface $transmitter):array
    {
        $request=$this->claimInutilization($tenantId,$id);
        try{
            $result=$transmitter->inutilize($request);
            return $this->applyInutilization($tenantId,$id,$result);
        }catch(Throwable $e){$this->inutilizationError($tenantId,$id,$e->getMessage());throw$e;}
    }

    public function transmitContingency(int $tenantId,int $documentId,FiscalLifecycleTransmitterInterface $transmitter):array
    {
        if($tenantId<1||$documentId<1)throw new RuntimeException('Documento fiscal inválido.');
        $pdo=Database::connection();$q=$pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$documentId,$tenantId]);$document=$q->fetch();
        if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');
        if((string)$document['status']==='authorized')return$this->publicDocument($document);
        if((string)$document['status']!=='contingency'||empty($document['contingency_xml_encrypted']))throw new RuntimeException('Documento não possui contingência local pronta para sincronização.');
        $xml=Crypto::decrypt((string)$document['contingency_xml_encrypted']);
        try{
            $result=$transmitter->transmitContingency($this->publicDocument($document),$xml);
            return $this->applyContingency($tenantId,$documentId,$result);
        }catch(Throwable $e){$this->contingencyError($tenantId,$documentId,$e->getMessage());throw$e;}
    }

    public function queryDocument(int $tenantId,int $documentId,FiscalLifecycleTransmitterInterface $transmitter):array
    {
        if($tenantId<1||$documentId<1)throw new RuntimeException('Documento fiscal inválido.');
        $pdo=Database::connection();$q=$pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$documentId,$tenantId]);$document=$q->fetch();if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');
        $key=preg_replace('/\D+/','',(string)($document['access_key']??''))??'';if(strlen($key)!==44)throw new RuntimeException('Documento ainda não possui chave de acesso consultável.');
        $result=$transmitter->query($this->publicDocument($document));if(empty($result['verified']))throw new RuntimeException('O transmissor não comprovou a consulta oficial da SEFAZ.');
        $status=strtolower(trim((string)($result['status']??'')));
        if($status==='authorized')return$this->reconcileAuthorized($tenantId,$documentId,$result);
        if($status==='cancelled')return$this->reconcileCancelled($tenantId,$documentId,$result);
        return['document'=>$this->publicDocument($document),'query'=>$this->safeResult($result)];
    }

    private function claimEvent(int $tenantId,int $eventId,string $type):array
    {
        if($tenantId<1||$eventId<1)throw new RuntimeException('Evento fiscal inválido.');
        return Database::transaction(function(PDO$pdo)use($tenantId,$eventId,$type):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT fe.*,fd.model,fd.series,fd.document_number,fd.access_key,fd.protocol document_protocol,fd.status document_status,fd.environment,fd.unit_id,fd.fiscal_profile_id FROM fiscal_events fe JOIN fiscal_documents fd ON fd.id=fe.fiscal_document_id AND fd.tenant_id=fe.tenant_id WHERE fe.id=? AND fe.tenant_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$eventId,$tenantId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Evento fiscal não encontrado.');if((string)$row['event_type']!==$type)throw new RuntimeException('Tipo de evento fiscal inválido.');if((string)$row['status']==='authorized')return['event'=>$this->publicEvent($row),'document'=>$this->documentFromEvent($row)];if(!in_array((string)$row['status'],['queued','error'],true))throw new RuntimeException('Evento fiscal já está em processamento ou encerrado.');if((string)$row['document_status']!=='authorized')throw new RuntimeException('O documento deixou de estar autorizado antes do cancelamento.');
            $pdo->prepare('UPDATE fiscal_events SET status="processing",attempts=attempts+1,started_at=COALESCE(started_at,CURRENT_TIMESTAMP),last_attempt_at=CURRENT_TIMESTAMP,rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$eventId,$tenantId]);$row['status']='processing';$row['attempts']=(int)$row['attempts']+1;
            return['event'=>$this->publicEvent($row),'document'=>$this->documentFromEvent($row)];
        });
    }

    private function claimInutilization(int $tenantId,int $id):array
    {
        if($tenantId<1||$id<1)throw new RuntimeException('Inutilização fiscal inválida.');
        return Database::transaction(function(PDO$pdo)use($tenantId,$id):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM fiscal_inutilizations WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$id,$tenantId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Inutilização fiscal não encontrada.');if((string)$row['status']==='authorized')return$this->publicInutilization($row);if(!in_array((string)$row['status'],['queued','error'],true))throw new RuntimeException('Inutilização já está em processamento ou encerrada.');$pdo->prepare('UPDATE fiscal_inutilizations SET status="processing",attempts=attempts+1,started_at=COALESCE(started_at,CURRENT_TIMESTAMP),last_attempt_at=CURRENT_TIMESTAMP,rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$id,$tenantId]);$row['status']='processing';$row['attempts']=(int)$row['attempts']+1;return$this->publicInutilization($row);
        });
    }

    private function applyCancellation(int $tenantId,int $eventId,array$result):array
    {
        $this->verified($result);$status=strtolower(trim((string)($result['status']??'')));
        if($status==='rejected')return$this->rejectEvent($tenantId,$eventId,$result);
        if($status!=='authorized')throw new RuntimeException('Resposta de cancelamento com estado não reconhecido.');
        $protocol=$this->protocol($result);$xml=$this->xml($result);
        return Database::transaction(function(PDO$pdo)use($tenantId,$eventId,$protocol,$xml,$result):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT fe.*,fd.access_key,fd.status document_status FROM fiscal_events fe JOIN fiscal_documents fd ON fd.id=fe.fiscal_document_id AND fd.tenant_id=fe.tenant_id WHERE fe.id=? AND fe.tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$eventId,$tenantId]);$event=$q->fetch();if(!$event)throw new RuntimeException('Evento fiscal não encontrado.');if((string)$event['status']==='authorized')return$this->publicEvent($event);if((string)$event['status']!=='processing')throw new RuntimeException('Evento fiscal não está em processamento.');$key=preg_replace('/\D+/','',(string)$event['access_key'])??'';if($key!==''&&!str_contains($xml,$key))throw new RuntimeException('XML de cancelamento não corresponde à chave do documento.');
            $pdo->prepare('UPDATE fiscal_events SET status="authorized",protocol=?,response_xml_encrypted=?,response_encrypted=?,authorized_at=CURRENT_TIMESTAMP,rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$protocol,Crypto::encrypt($xml),Crypto::encrypt($this->safeResult($result)),$eventId,$tenantId]);
            $pdo->prepare('UPDATE fiscal_documents SET status="cancelled",cancellation_xml_encrypted=?,cancelled_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="authorized"')->execute([Crypto::encrypt($xml),(int)$event['fiscal_document_id'],$tenantId]);
            return$this->loadEvent($pdo,$tenantId,$eventId);
        });
    }

    private function applyInutilization(int $tenantId,int$id,array$result):array
    {
        $this->verified($result);$status=strtolower(trim((string)($result['status']??'')));if($status==='rejected')return$this->rejectInutilization($tenantId,$id,$result);if($status!=='authorized')throw new RuntimeException('Resposta de inutilização com estado não reconhecido.');$protocol=$this->protocol($result);$xml=$this->xml($result);
        return Database::transaction(function(PDO$pdo)use($tenantId,$id,$protocol,$xml,$result):array{$q=$pdo->prepare(Database::portableSql($pdo,'SELECT status FROM fiscal_inutilizations WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$id,$tenantId]);$status=$q->fetchColumn();if($status===false)throw new RuntimeException('Inutilização não encontrada.');if($status==='authorized')return$this->loadInutilization($pdo,$tenantId,$id);if($status!=='processing')throw new RuntimeException('Inutilização não está em processamento.');$pdo->prepare('UPDATE fiscal_inutilizations SET status="authorized",protocol=?,response_xml_encrypted=?,response_encrypted=?,authorized_at=CURRENT_TIMESTAMP,rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$protocol,Crypto::encrypt($xml),Crypto::encrypt($this->safeResult($result)),$id,$tenantId]);return$this->loadInutilization($pdo,$tenantId,$id);});
    }

    private function applyContingency(int $tenantId,int$documentId,array$result):array
    {
        $this->verified($result);$status=strtolower(trim((string)($result['status']??'')));if($status==='rejected'){return Database::transaction(function(PDO$pdo)use($tenantId,$documentId,$result):array{$code=mb_substr(trim((string)($result['rejection_code']??'')),0,32);$message=mb_substr(trim((string)($result['rejection_message']??'')),0,1000);if($code===''||$message==='')throw new RuntimeException('Rejeição da contingência sem código ou motivo.');$pdo->prepare('UPDATE fiscal_documents SET status="rejected",rejection_code=?,rejection_message=?,response_encrypted=?,last_transmission_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="contingency"')->execute([$code,$message,Crypto::encrypt($this->safeResult($result)),$documentId,$tenantId]);return$this->loadDocument($pdo,$tenantId,$documentId);});}if($status!=='authorized')throw new RuntimeException('Resposta de sincronização da contingência com estado não reconhecido.');$accessKey=preg_replace('/\D+/','',(string)($result['access_key']??''))??'';$protocol=$this->protocol($result);$xml=$this->xml($result);if(strlen($accessKey)!==44||!str_contains($xml,$accessKey))throw new RuntimeException('Resposta autorizada da contingência não confere com a chave/XML.');
        return Database::transaction(function(PDO$pdo)use($tenantId,$documentId,$accessKey,$protocol,$xml,$result):array{$q=$pdo->prepare(Database::portableSql($pdo,'SELECT status,access_key FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$documentId,$tenantId]);$document=$q->fetch();if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');if((string)$document['status']==='authorized')return$this->loadDocument($pdo,$tenantId,$documentId);if((string)$document['status']!=='contingency')throw new RuntimeException('Documento não está em contingência.');$localKey=preg_replace('/\D+/','',(string)$document['access_key'])??'';if(!hash_equals($localKey,$accessKey))throw new RuntimeException('A SEFAZ retornou chave diferente da contingência local.');$pdo->prepare('UPDATE fiscal_documents SET status="authorized",protocol=?,xml_encrypted=?,response_encrypted=?,contingency_synced_at=CURRENT_TIMESTAMP,authorized_at=CURRENT_TIMESTAMP,rejection_code=NULL,rejection_message=NULL,last_transmission_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$protocol,Crypto::encrypt($xml),Crypto::encrypt($this->safeResult($result)),$documentId,$tenantId]);return$this->loadDocument($pdo,$tenantId,$documentId);});
    }

    private function reconcileAuthorized(int$tenantId,int$documentId,array$result):array{$key=preg_replace('/\D+/','',(string)($result['access_key']??''))??'';$protocol=$this->protocol($result);$xml=$this->xml($result);if(strlen($key)!==44||!str_contains($xml,$key))throw new RuntimeException('Consulta autorizada retornou XML/chave inválidos.');return Database::transaction(function(PDO$pdo)use($tenantId,$documentId,$key,$protocol,$xml,$result):array{$q=$pdo->prepare(Database::portableSql($pdo,'SELECT status,access_key FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$documentId,$tenantId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Documento fiscal não encontrado.');$known=preg_replace('/\D+/','',(string)($row['access_key']??''))??'';if($known!==''&&!hash_equals($known,$key))throw new RuntimeException('Consulta retornou chave diferente do documento local.');if((string)$row['status']!=='cancelled')$pdo->prepare('UPDATE fiscal_documents SET status="authorized",access_key=?,protocol=?,xml_encrypted=?,response_encrypted=?,authorized_at=COALESCE(authorized_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$key,$protocol,Crypto::encrypt($xml),Crypto::encrypt($this->safeResult($result)),$documentId,$tenantId]);return$this->loadDocument($pdo,$tenantId,$documentId);});}
    private function reconcileCancelled(int$tenantId,int$documentId,array$result):array{$protocol=$this->protocol($result);$xml=$this->xml($result);return Database::transaction(function(PDO$pdo)use($tenantId,$documentId,$protocol,$xml,$result):array{$pdo->prepare('UPDATE fiscal_documents SET status="cancelled",cancellation_xml_encrypted=?,response_encrypted=?,cancelled_at=COALESCE(cancelled_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([Crypto::encrypt($xml),Crypto::encrypt($this->safeResult($result)),$documentId,$tenantId]);return$this->loadDocument($pdo,$tenantId,$documentId);});}

    private function rejectEvent(int$tenantId,int$eventId,array$result):array{$code=$this->rejectCode($result);$message=$this->rejectMessage($result);return Database::transaction(function(PDO$pdo)use($tenantId,$eventId,$code,$message,$result):array{$pdo->prepare('UPDATE fiscal_events SET status="rejected",rejection_code=?,rejection_message=?,response_encrypted=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="processing"')->execute([$code,$message,Crypto::encrypt($this->safeResult($result)),$eventId,$tenantId]);return$this->loadEvent($pdo,$tenantId,$eventId);});}
    private function rejectInutilization(int$tenantId,int$id,array$result):array{$code=$this->rejectCode($result);$message=$this->rejectMessage($result);return Database::transaction(function(PDO$pdo)use($tenantId,$id,$code,$message,$result):array{$pdo->prepare('UPDATE fiscal_inutilizations SET status="rejected",rejection_code=?,rejection_message=?,response_encrypted=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="processing"')->execute([$code,$message,Crypto::encrypt($this->safeResult($result)),$id,$tenantId]);return$this->loadInutilization($pdo,$tenantId,$id);});}
    private function eventError(int$tenantId,int$id,string$message):void{$this->safeErrorUpdate('fiscal_events',$tenantId,$id,$message);}
    private function inutilizationError(int$tenantId,int$id,string$message):void{$this->safeErrorUpdate('fiscal_inutilizations',$tenantId,$id,$message);}
    private function contingencyError(int$tenantId,int$id,string$message):void{try{$safe=$this->friendly($message);Database::connection()->prepare('UPDATE fiscal_documents SET rejection_code="TECHNICAL",rejection_message=?,last_transmission_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="contingency"')->execute([$safe,$id,$tenantId]);}catch(Throwable){}}
    private function safeErrorUpdate(string$table,int$tenantId,int$id,string$message):void{try{$safe=$this->friendly($message);Database::connection()->prepare('UPDATE '.$table.' SET status="error",rejection_code="TECHNICAL",rejection_message=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=? AND status="processing"')->execute([$safe,$id,$tenantId]);}catch(Throwable){}}

    private function verified(array$result):void{if(empty($result['verified']))throw new RuntimeException('O transmissor não comprovou a resposta oficial da SEFAZ.');}
    private function protocol(array$result):string{$v=mb_substr(trim((string)($result['protocol']??'')),0,120);if($v==='')throw new RuntimeException('A SEFAZ não retornou protocolo verificável.');return$v;}
    private function xml(array$result):string{$xml=trim((string)($result['xml']??''));if($xml===''||strlen($xml)>4*1024*1024)throw new RuntimeException('XML fiscal de resposta ausente ou inválido.');$previous=libxml_use_internal_errors(true);try{if(simplexml_load_string($xml,'SimpleXMLElement',LIBXML_NONET|LIBXML_NOCDATA)===false)throw new RuntimeException('XML fiscal de resposta inválido.');}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}return$xml;}
    private function rejectCode(array$result):string{$v=mb_substr(trim((string)($result['rejection_code']??'')),0,32);if($v==='')throw new RuntimeException('Rejeição fiscal sem código verificável.');return$v;}
    private function rejectMessage(array$result):string{$v=mb_substr(trim((string)($result['rejection_message']??'')),0,1000);if($v==='')throw new RuntimeException('Rejeição fiscal sem motivo verificável.');return$v;}
    private function loadEvent(PDO$pdo,int$tenantId,int$id):array{$q=$pdo->prepare('SELECT * FROM fiscal_events WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$id,$tenantId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Evento fiscal não encontrado.');return$this->publicEvent($row);}
    private function loadInutilization(PDO$pdo,int$tenantId,int$id):array{$q=$pdo->prepare('SELECT * FROM fiscal_inutilizations WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$id,$tenantId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Inutilização não encontrada.');return$this->publicInutilization($row);}
    private function loadDocument(PDO$pdo,int$tenantId,int$id):array{$q=$pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$id,$tenantId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Documento fiscal não encontrado.');return$this->publicDocument($row);}
    private function publicEvent(array$row):array{unset($row['request_xml_encrypted'],$row['response_xml_encrypted'],$row['response_encrypted']);return$row;}
    private function publicInutilization(array$row):array{unset($row['request_xml_encrypted'],$row['response_xml_encrypted'],$row['response_encrypted']);return$row;}
    private function publicDocument(array$row):array{unset($row['xml_encrypted'],$row['cancellation_xml_encrypted'],$row['snapshot_encrypted'],$row['response_encrypted'],$row['contingency_xml_encrypted']);return$row;}
    private function documentFromEvent(array$row):array{return['id'=>(int)$row['fiscal_document_id'],'tenant_id'=>(int)$row['tenant_id'],'model'=>(string)$row['model'],'series'=>(int)$row['series'],'document_number'=>(int)$row['document_number'],'access_key'=>(string)$row['access_key'],'protocol'=>(string)$row['document_protocol'],'status'=>(string)$row['document_status'],'environment'=>(string)$row['environment'],'unit_id'=>(int)$row['unit_id'],'fiscal_profile_id'=>(int)$row['fiscal_profile_id']];}
    private function safeResult(array$result):array{$out=[];foreach(array_slice($result,0,60,true)as$key=>$value){$key=mb_substr((string)$key,0,80);if(in_array($key,['xml','signed_xml','certificate','pfx','password'],true))continue;if(is_scalar($value)||$value===null)$out[$key]=is_string($value)?mb_substr($value,0,1000):$value;elseif(is_array($value))$out[$key]=['items'=>min(100,count($value))];}return$out;}
    private function friendly(string$message):string{$lower=strtolower($message);if(str_contains($lower,'sqlstate')||str_contains($lower,'stack trace')||str_contains($lower,'exception'))return'Falha técnica durante o evento fiscal.';return mb_substr(trim($message)!==''?$message:'Falha técnica durante o evento fiscal.',0,1000);}
}
