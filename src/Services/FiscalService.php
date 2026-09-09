<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Crypto;
use EventMenu\Core\Database;
use PDO;
use RuntimeException;

final class FiscalService
{
    public function profileForUnit(int $unitId):array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId||$unitId<1)throw new RuntimeException('Unidade inválida.');
        $s=Database::connection()->prepare('SELECT id,tenant_id,unit_id,enabled,environment,default_document,legal_name,trade_name,cnpj,state_registration,state_code,city_code,tax_regime,nfce_series,nfce_next_number,nfe_series,nfe_next_number,csc_id,certificate_mode,contingency_enabled,created_at,updated_at,CASE WHEN csc_token_encrypted IS NULL OR csc_token_encrypted="" THEN 0 ELSE 1 END has_csc FROM fiscal_profiles WHERE tenant_id=? AND unit_id=? LIMIT 1');
        $s->execute([$tenantId,$unitId]);
        $profile=$s->fetch();
        if(!$profile)return [];
        $profile['certificate']=(new FiscalCertificateService())->metadata((int)$profile['id']);
        return $profile;
    }

    public function saveProfile(array $data):array
    {
        Auth::requirePermission('fiscal.manage');
        $tenantId=Auth::tenantId();
        if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $unitId=(int)($data['unit_id']??0);
        if($unitId<1)throw new RuntimeException('Escolha uma unidade.');
        $pdo=Database::connection();
        $u=$pdo->prepare('SELECT id FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');
        $u->execute([$unitId,$tenantId]);
        if(!$u->fetchColumn())throw new RuntimeException('Unidade fiscal inválida ou inativa.');

        $enabled=!empty($data['enabled'])?1:0;
        $environment=(string)($data['environment']??'homologation');
        if(!in_array($environment,['homologation','production'],true))throw new RuntimeException('Ambiente fiscal inválido.');
        $defaultDocument=(string)($data['default_document']??'nfce');
        if(!in_array($defaultDocument,['nfce','nfe'],true))throw new RuntimeException('Documento fiscal padrão inválido.');
        $certificateMode=(string)($data['certificate_mode']??'server');
        if(!in_array($certificateMode,['server','desktop','hybrid','a3_local'],true))throw new RuntimeException('Modo de certificado inválido.');

        $cnpj=preg_replace('/\D+/','',(string)($data['cnpj']??''))??'';
        if($enabled&&strlen($cnpj)!==14)throw new RuntimeException('Informe o CNPJ com 14 dígitos.');
        $stateCode=strtoupper(trim((string)($data['state_code']??'')));
        if($enabled&&!preg_match('/^[A-Z]{2}$/',$stateCode))throw new RuntimeException('Informe a UF do emitente.');
        $ie=mb_substr(trim((string)($data['state_registration']??'')),0,32);
        if($enabled&&$ie==='')throw new RuntimeException('Informe a inscrição estadual.');

        $legalName=mb_substr(trim((string)($data['legal_name']??'')),0,190);
        $tradeName=mb_substr(trim((string)($data['trade_name']??'')),0,190);
        $cityCode=mb_substr(trim((string)($data['city_code']??'')),0,16);
        $taxRegime=mb_substr(trim((string)($data['tax_regime']??'')),0,16);
        $nfceSeries=max(1,(int)($data['nfce_series']??1));
        $nfeSeries=max(1,(int)($data['nfe_series']??1));
        $cscId=mb_substr(trim((string)($data['csc_id']??'')),0,32);
        $newCsc=trim((string)($data['csc_token']??''));
        $contingency=!empty($data['contingency_enabled'])?1:0;

        return Database::transaction(function(PDO $tx)use($tenantId,$unitId,$enabled,$environment,$defaultDocument,$legalName,$tradeName,$cnpj,$ie,$stateCode,$cityCode,$taxRegime,$nfceSeries,$nfeSeries,$cscId,$newCsc,$certificateMode,$contingency):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM fiscal_profiles WHERE tenant_id=? AND unit_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$tenantId,$unitId]);
            $existing=$q->fetch();
            $cscEncrypted=$existing['csc_token_encrypted']??null;
            if($newCsc!=='')$cscEncrypted=Crypto::encrypt($newCsc);
            if($existing){
                $s=$tx->prepare('UPDATE fiscal_profiles SET enabled=?,environment=?,default_document=?,legal_name=?,trade_name=?,cnpj=?,state_registration=?,state_code=?,city_code=?,tax_regime=?,nfce_series=?,nfe_series=?,csc_id=?,csc_token_encrypted=?,certificate_mode=?,contingency_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');
                $s->execute([$enabled,$environment,$defaultDocument,$legalName?:null,$tradeName?:null,$cnpj,$ie,$stateCode,$cityCode?:null,$taxRegime?:null,$nfceSeries,$nfeSeries,$cscId?:null,$cscEncrypted,$certificateMode,$contingency,(int)$existing['id'],$tenantId]);
                $id=(int)$existing['id'];
            }else{
                $s=$tx->prepare('INSERT INTO fiscal_profiles (tenant_id,unit_id,enabled,environment,default_document,legal_name,trade_name,cnpj,state_registration,state_code,city_code,tax_regime,nfce_series,nfe_series,csc_id,csc_token_encrypted,certificate_mode,contingency_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $s->execute([$tenantId,$unitId,$enabled,$environment,$defaultDocument,$legalName?:null,$tradeName?:null,$cnpj,$ie,$stateCode,$cityCode?:null,$taxRegime?:null,$nfceSeries,$nfeSeries,$cscId?:null,$cscEncrypted,$certificateMode,$contingency]);
                $id=(int)$tx->lastInsertId();
            }
            Auth::audit('fiscal.profile_saved','fiscal_profile',(string)$id,['unit_id'=>$unitId,'enabled'=>(bool)$enabled,'environment'=>$environment,'certificate_mode'=>$certificateMode]);
            return $this->profileForUnit($unitId);
        });
    }

    public function queueForOrder(int $orderId,string $document=''):array
    {
        Auth::requirePermission('fiscal.issue');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$orderId<1)throw new RuntimeException('Pedido inválido.');
        return Database::transaction(function(PDO $tx)use($tenantId,$orderId,$document):array{
            $o=$tx->prepare(Database::portableSql($tx,'SELECT id,unit_id,status,payment_status,total_cents FROM orders WHERE id=? AND tenant_id=? LIMIT 1 FOR UPDATE'));
            $o->execute([$orderId,$tenantId]);
            $order=$o->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if(in_array((string)$order['status'],['cancelled'],true))throw new RuntimeException('Pedido cancelado não pode gerar documento fiscal.');
            if((string)$order['payment_status']!=='paid')throw new RuntimeException('O pedido precisa estar pago antes da emissão fiscal.');
            $unitId=(int)($order['unit_id']??0);
            if($unitId<1)throw new RuntimeException('Pedido sem unidade fiscal definida.');

            $p=$tx->prepare(Database::portableSql($tx,'SELECT * FROM fiscal_profiles WHERE tenant_id=? AND unit_id=? AND enabled=1 LIMIT 1 FOR UPDATE'));
            $p->execute([$tenantId,$unitId]);
            $profile=$p->fetch();
            if(!$profile)throw new RuntimeException('Perfil fiscal ativo não configurado para esta unidade.');
            $kind=$document!==''?strtolower($document):(string)$profile['default_document'];
            if(!in_array($kind,['nfce','nfe'],true))throw new RuntimeException('Documento fiscal inválido.');
            $model=$kind==='nfce'?'65':'55';
            $idempotency='fiscal:order:'.$orderId.':model:'.$model;
            $existing=$tx->prepare('SELECT * FROM fiscal_documents WHERE tenant_id=? AND idempotency_key=? LIMIT 1');
            $existing->execute([$tenantId,$idempotency]);
            if($row=$existing->fetch())return $row;

            $seriesField=$kind==='nfce'?'nfce_series':'nfe_series';
            $nextField=$kind==='nfce'?'nfce_next_number':'nfe_next_number';
            $series=max(1,(int)$profile[$seriesField]);
            $number=max(1,(int)$profile[$nextField]);
            $tx->prepare('UPDATE fiscal_profiles SET '.$nextField.'=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$number+1,$profile['id'],$tenantId]);
            $insert=$tx->prepare('INSERT INTO fiscal_documents (tenant_id,unit_id,fiscal_profile_id,order_id,model,environment,series,document_number,status,idempotency_key) VALUES (?,?,?,?,?,?,?,?,"queued",?)');
            $insert->execute([$tenantId,$unitId,$profile['id'],$orderId,$model,$profile['environment'],$series,$number,$idempotency]);
            $id=(int)$tx->lastInsertId();
            Auth::audit('fiscal.document_queued','fiscal_document',(string)$id,['order_id'=>$orderId,'model'=>$model,'series'=>$series,'number'=>$number,'unit_id'=>$unitId]);
            $q=$tx->prepare('SELECT * FROM fiscal_documents WHERE id=? AND tenant_id=?');
            $q->execute([$id,$tenantId]);
            return $q->fetch()?:throw new RuntimeException('Falha ao preparar documento fiscal.');
        });
    }

    public function documents(int $limit=100):array
    {
        Auth::requirePermission('fiscal.issue');
        $tenantId=Auth::tenantId();
        if(!$tenantId)return [];
        $limit=max(1,min(200,$limit));
        $s=Database::connection()->prepare('SELECT fd.id,fd.unit_id,fd.order_id,fd.model,fd.environment,fd.series,fd.document_number,fd.status,fd.access_key,fd.protocol,fd.rejection_code,fd.rejection_message,fd.issued_at,fd.authorized_at,fd.cancelled_at,fd.created_at,ou.name unit_name FROM fiscal_documents fd LEFT JOIN operating_units ou ON ou.id=fd.unit_id AND ou.tenant_id=fd.tenant_id WHERE fd.tenant_id=? ORDER BY fd.id DESC LIMIT '.$limit);
        $s->execute([$tenantId]);
        return $s->fetchAll();
    }
}
