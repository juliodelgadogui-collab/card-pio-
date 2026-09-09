<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class FiscalLifecycleService
{
    public function requestCancellation(int $documentId,string $reason):array
    {
        Auth::requirePermission('fiscal.issue');
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||$documentId<1)throw new RuntimeException('Documento fiscal inválido.');
        $reason=$this->reason($reason,15,500,'Informe um motivo de cancelamento com pelo menos 15 caracteres.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$documentId,$reason):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$documentId,$tenantId]);$document=$q->fetch();
            if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');
            if((string)$document['status']==='cancelled')throw new RuntimeException('Documento fiscal já está cancelado.');
            if((string)$document['status']!=='authorized')throw new RuntimeException('Somente documento autorizado pode solicitar cancelamento.');
            if(strlen(preg_replace('/\D+/','',(string)($document['access_key']??''))??'')!==44)throw new RuntimeException('Documento autorizado sem chave de acesso válida.');

            $existing=$pdo->prepare('SELECT * FROM fiscal_events WHERE tenant_id=? AND fiscal_document_id=? AND event_type="cancel" ORDER BY id DESC LIMIT 1');
            $existing->execute([$tenantId,$documentId]);$event=$existing->fetch();
            if($event&&in_array((string)$event['status'],['queued','processing','authorized'],true))return $this->publicEvent($event);

            $sequence=1;
            if($event)$sequence=max(1,(int)$event['sequence_no']+1);
            $idempotency='fiscal:cancel:'.$documentId.':seq:'.$sequence;
            $ins=$pdo->prepare('INSERT INTO fiscal_events (tenant_id,fiscal_document_id,event_type,sequence_no,status,reason,idempotency_key,created_by) VALUES (?,?,"cancel",?,"queued",?,?,?)');
            $ins->execute([$tenantId,$documentId,$sequence,$reason,$idempotency,$userId]);$id=(int)$pdo->lastInsertId();
            Auth::audit('fiscal.cancel_requested','fiscal_document',(string)$documentId,['event_id'=>$id,'reason'=>$reason,'sequence'=>$sequence]);
            return $this->loadEvent($pdo,$tenantId,$id);
        });
    }

    public function requestInutilization(int $unitId,string $document,int $year,int $series,int $numberStart,int $numberEnd,string $reason):array
    {
        Auth::requirePermission('fiscal.manage');
        $tenantId=Auth::tenantId();$userId=Auth::id();
        if(!$tenantId||$unitId<1)throw new RuntimeException('Unidade fiscal inválida.');
        $kind=strtolower(trim($document));if(!in_array($kind,['nfce','nfe'],true))throw new RuntimeException('Documento fiscal inválido.');
        $model=$kind==='nfce'?'65':'55';
        $currentYear=(int)gmdate('Y');if($year<2000||$year>$currentYear+1)throw new RuntimeException('Ano fiscal inválido.');
        if($series<1||$series>999)throw new RuntimeException('Série fiscal inválida.');
        if($numberStart<1||$numberEnd<$numberStart||$numberEnd-$numberStart>9999)throw new RuntimeException('Faixa de numeração inválida ou muito extensa.');
        $reason=$this->reason($reason,15,255,'Informe uma justificativa com pelo menos 15 caracteres.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$userId,$unitId,$model,$year,$series,$numberStart,$numberEnd,$reason):array{
            $p=$pdo->prepare(Database::portableSql($pdo,'SELECT * FROM fiscal_profiles WHERE tenant_id=? AND unit_id=? AND enabled=1 LIMIT 1 FOR UPDATE'));
            $p->execute([$tenantId,$unitId]);$profile=$p->fetch();if(!$profile)throw new RuntimeException('Perfil fiscal ativo não encontrado para esta unidade.');
            $environment=(string)$profile['environment'];

            $used=$pdo->prepare('SELECT document_number,status FROM fiscal_documents WHERE tenant_id=? AND unit_id=? AND model=? AND series=? AND document_number BETWEEN ? AND ? LIMIT 1');
            $used->execute([$tenantId,$unitId,$model,$series,$numberStart,$numberEnd]);
            if($used->fetch())throw new RuntimeException('A faixa informada contém número já reservado ou utilizado pelo EventMenu.');

            $overlap=$pdo->prepare('SELECT id,status FROM fiscal_inutilizations WHERE tenant_id=? AND unit_id=? AND model=? AND series=? AND fiscal_year=? AND status IN ("queued","processing","authorized") AND NOT (number_end<? OR number_start>?) LIMIT 1');
            $overlap->execute([$tenantId,$unitId,$model,$series,$year,$numberStart,$numberEnd]);
            if($overlap->fetch())throw new RuntimeException('A faixa informada conflita com outra inutilização existente.');

            $idempotency='fiscal:inut:'.$unitId.':'.$model.':'.$year.':'.$series.':'.$numberStart.'-'.$numberEnd;
            $existing=$pdo->prepare('SELECT * FROM fiscal_inutilizations WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$idempotency]);if($row=$existing->fetch())return $this->publicInutilization($row);
            $ins=$pdo->prepare('INSERT INTO fiscal_inutilizations (tenant_id,unit_id,fiscal_profile_id,model,environment,fiscal_year,series,number_start,number_end,justification,status,idempotency_key,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,"queued",?,?)');
            $ins->execute([$tenantId,$unitId,(int)$profile['id'],$model,$environment,$year,$series,$numberStart,$numberEnd,$reason,$idempotency,$userId]);$id=(int)$pdo->lastInsertId();
            Auth::audit('fiscal.inutilization_requested','fiscal_inutilization',(string)$id,['unit_id'=>$unitId,'model'=>$model,'year'=>$year,'series'=>$series,'start'=>$numberStart,'end'=>$numberEnd]);
            return $this->loadInutilization($pdo,$tenantId,$id);
        });
    }

    public function prepareContingency(int $documentId,string $reason,string $deviceId):array
    {
        Auth::requirePermission('fiscal.issue');
        $tenantId=Auth::tenantId();if(!$tenantId||$documentId<1)throw new RuntimeException('Documento fiscal inválido.');
        $reason=$this->reason($reason,15,500,'Informe o motivo da contingência com pelo menos 15 caracteres.');
        $deviceId=mb_substr(trim($deviceId),0,190);if($deviceId==='')throw new RuntimeException('Dispositivo da contingência não identificado.');

        return Database::transaction(function(PDO $pdo)use($tenantId,$documentId,$reason,$deviceId):array{
            $q=$pdo->prepare(Database::portableSql($pdo,'SELECT fd.*,fp.contingency_enabled FROM fiscal_documents fd JOIN fiscal_profiles fp ON fp.id=fd.fiscal_profile_id AND fp.tenant_id=fd.tenant_id WHERE fd.id=? AND fd.tenant_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$documentId,$tenantId]);$document=$q->fetch();if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');
            if((string)$document['model']!=='65')throw new RuntimeException('A contingência local deste módulo é exclusiva para NFC-e.');
            if(!(int)$document['contingency_enabled'])throw new RuntimeException('Contingência local não está habilitada para esta unidade.');
            if(!in_array((string)$document['status'],['queued','error'],true))throw new RuntimeException('Documento não está disponível para iniciar contingência.');
            $pdo->prepare('UPDATE fiscal_documents SET contingency_mode="offline_requested",contingency_reason=?,contingency_started_at=COALESCE(contingency_started_at,CURRENT_TIMESTAMP),contingency_device_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')
                ->execute([$reason,$deviceId,$documentId,$tenantId]);
            Auth::audit('fiscal.contingency_prepared','fiscal_document',(string)$documentId,['device_id'=>$deviceId,'reason'=>$reason]);
            return $this->loadDocument($pdo,$tenantId,$documentId);
        });
    }

    public function registerContingencyXml(int $documentId,string $deviceId,string $signedXml,FiscalContingencyVerifierInterface $verifier):array
    {
        Auth::requirePermission('fiscal.issue');
        $tenantId=Auth::tenantId();if(!$tenantId||$documentId<1)throw new RuntimeException('Documento fiscal inválido.');
        $deviceId=mb_substr(trim($deviceId),0,190);if($deviceId==='')throw new RuntimeException('Dispositivo da contingência não identificado.');
        $signedXml=trim($signedXml);if($signedXml===''||strlen($signedXml)>4*1024*1024)throw new RuntimeException('XML de contingência ausente ou inválido.');

        $pdo=Database::connection();$q=$pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');$q->execute([$documentId,$tenantId]);$document=$q->fetch();if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');
        if((string)($document['contingency_mode']??'')!=='offline_requested')throw new RuntimeException('A contingência não foi preparada para este documento.');
        if(!hash_equals((string)($document['contingency_device_id']??''),$deviceId))throw new RuntimeException('A contingência foi vinculada a outro dispositivo.');
        if(!in_array((string)$document['status'],['queued','error'],true))throw new RuntimeException('Documento não aceita XML de contingência no estado atual.');
        $snapshot=$this->snapshot($document);
        $verified=$verifier->verify($this->publicDocument($document),$snapshot,$signedXml);
        if(empty($verified['verified']))throw new RuntimeException((string)($verified['error']??'A assinatura da contingência não foi validada pelo servidor.'));
        $accessKey=preg_replace('/\D+/','',(string)($verified['access_key']??''))??'';if(strlen($accessKey)!==44)throw new RuntimeException('O verificador não retornou chave de acesso válida.');
        if(!str_contains($signedXml,$accessKey))throw new RuntimeException('A chave verificada não confere com o XML da contingência.');

        return Database::transaction(function(PDO $tx)use($tenantId,$documentId,$deviceId,$signedXml,$accessKey,$verified):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$documentId,$tenantId]);$document=$q->fetch();if(!$document)throw new RuntimeException('Documento fiscal não encontrado.');
            if(!in_array((string)$document['status'],['queued','error'],true))throw new RuntimeException('Documento foi alterado durante a validação da contingência.');
            $dupe=$tx->prepare('SELECT id FROM fiscal_documents WHERE access_key=? AND id<>? LIMIT 1');$dupe->execute([$accessKey,$documentId]);if($dupe->fetchColumn())throw new RuntimeException('Chave de acesso já utilizada por outro documento.');
            $tx->prepare('UPDATE fiscal_documents SET status="contingency",access_key=?,contingency_mode="offline",contingency_xml_encrypted=?,contingency_emitted_at=CURRENT_TIMESTAMP,response_encrypted=?,rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')
                ->execute([$accessKey,Crypto::encrypt($signedXml),Crypto::encrypt($this->safeArray($verified['metadata']??[])),$documentId,$tenantId]);
            Auth::audit('fiscal.contingency_registered','fiscal_document',(string)$documentId,['device_id'=>$deviceId,'access_key'=>$accessKey]);
            return $this->loadDocument($tx,$tenantId,$documentId);
        });
    }

    public function events(int $limit=100):array
    {
        Auth::requirePermission('fiscal.issue');$tenantId=Auth::tenantId();if(!$tenantId)return[];$limit=max(1,min(200,$limit));
        $s=Database::connection()->prepare('SELECT fe.id,fe.fiscal_document_id,fe.event_type,fe.sequence_no,fe.status,fe.reason,fe.protocol,fe.rejection_code,fe.rejection_message,fe.attempts,fe.started_at,fe.authorized_at,fe.last_attempt_at,fe.created_at,fd.model,fd.series,fd.document_number,fd.access_key FROM fiscal_events fe JOIN fiscal_documents fd ON fd.id=fe.fiscal_document_id AND fd.tenant_id=fe.tenant_id WHERE fe.tenant_id=? ORDER BY fe.id DESC LIMIT '.$limit);$s->execute([$tenantId]);return$s->fetchAll();
    }

    public function inutilizations(int $limit=100):array
    {
        Auth::requirePermission('fiscal.manage');$tenantId=Auth::tenantId();if(!$tenantId)return[];$limit=max(1,min(200,$limit));
        $s=Database::connection()->prepare('SELECT id,unit_id,fiscal_profile_id,model,environment,fiscal_year,series,number_start,number_end,justification,status,protocol,rejection_code,rejection_message,attempts,started_at,authorized_at,last_attempt_at,created_at FROM fiscal_inutilizations WHERE tenant_id=? ORDER BY id DESC LIMIT '.$limit);$s->execute([$tenantId]);return$s->fetchAll();
    }

    public function retryEvent(int $eventId):array
    {
        Auth::requirePermission('fiscal.issue');$tenantId=Auth::tenantId();if(!$tenantId||$eventId<1)throw new RuntimeException('Evento fiscal inválido.');
        return Database::transaction(function(PDO $pdo)use($tenantId,$eventId):array{$q=$pdo->prepare(Database::portableSql($pdo,'SELECT status FROM fiscal_events WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$eventId,$tenantId]);$status=$q->fetchColumn();if($status===false)throw new RuntimeException('Evento fiscal não encontrado.');if($status!=='error')throw new RuntimeException('Somente erro técnico pode voltar para a fila.');$pdo->prepare('UPDATE fiscal_events SET status="queued",rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$eventId,$tenantId]);return$this->loadEvent($pdo,$tenantId,$eventId);});
    }

    public function retryInutilization(int $id):array
    {
        Auth::requirePermission('fiscal.manage');$tenantId=Auth::tenantId();if(!$tenantId||$id<1)throw new RuntimeException('Inutilização inválida.');
        return Database::transaction(function(PDO $pdo)use($tenantId,$id):array{$q=$pdo->prepare(Database::portableSql($pdo,'SELECT status FROM fiscal_inutilizations WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));$q->execute([$id,$tenantId]);$status=$q->fetchColumn();if($status===false)throw new RuntimeException('Inutilização não encontrada.');if($status!=='error')throw new RuntimeException('Somente erro técnico pode voltar para a fila.');$pdo->prepare('UPDATE fiscal_inutilizations SET status="queued",rejection_code=NULL,rejection_message=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$id,$tenantId]);return$this->loadInutilization($pdo,$tenantId,$id);});
    }

    private function snapshot(array $document):array
    {
        if(empty($document['snapshot_encrypted'])||empty($document['snapshot_hash']))throw new RuntimeException('Snapshot fiscal não encontrado.');
        $snapshot=Crypto::decryptJson((string)$document['snapshot_encrypted']);$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);if(!hash_equals((string)$document['snapshot_hash'],hash('sha256',$json)))throw new RuntimeException('Integridade do snapshot fiscal não confere.');return$snapshot;
    }

    private function loadEvent(PDO $pdo,int $tenantId,int $id):array{$s=$pdo->prepare('SELECT * FROM fiscal_events WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$id,$tenantId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Evento fiscal não encontrado.');return$this->publicEvent($row);}
    private function loadInutilization(PDO $pdo,int $tenantId,int $id):array{$s=$pdo->prepare('SELECT * FROM fiscal_inutilizations WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$id,$tenantId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Inutilização não encontrada.');return$this->publicInutilization($row);}
    private function loadDocument(PDO $pdo,int $tenantId,int $id):array{$s=$pdo->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$id,$tenantId]);$row=$s->fetch();if(!$row)throw new RuntimeException('Documento fiscal não encontrado.');return$this->publicDocument($row);}
    private function publicEvent(array$row):array{unset($row['request_xml_encrypted'],$row['response_xml_encrypted'],$row['response_encrypted']);return$row;}
    private function publicInutilization(array$row):array{unset($row['request_xml_encrypted'],$row['response_xml_encrypted'],$row['response_encrypted']);return$row;}
    private function publicDocument(array$row):array{unset($row['xml_encrypted'],$row['cancellation_xml_encrypted'],$row['snapshot_encrypted'],$row['response_encrypted'],$row['contingency_xml_encrypted']);return$row;}
    private function reason(string$value,int$min,int$max,string$error):string{$value=preg_replace('/\s+/u',' ',trim($value))??'';if(mb_strlen($value)<$min)throw new RuntimeException($error);return mb_substr($value,0,$max);}
    private function safeArray(mixed$value):array{if(!is_array($value))return[];$out=[];foreach(array_slice($value,0,50,true)as$key=>$item){$key=mb_substr((string)$key,0,80);if(is_scalar($item)||$item===null)$out[$key]=is_string($item)?mb_substr($item,0,500):$item;}return$out;}
}
