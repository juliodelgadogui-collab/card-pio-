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
        $s=Database::connection()->prepare('SELECT id,tenant_id,unit_id,enabled,environment,default_document,legal_name,trade_name,cnpj,state_registration,state_code,city_code,tax_regime,street,address_number,address_complement,district,city_name,postal_code,phone,email,municipal_registration,cnae,country_code,country_name,nfce_series,nfce_next_number,nfe_series,nfe_next_number,csc_id,certificate_mode,contingency_enabled,created_at,updated_at,CASE WHEN csc_token_encrypted IS NULL OR csc_token_encrypted="" THEN 0 ELSE 1 END has_csc FROM fiscal_profiles WHERE tenant_id=? AND unit_id=? LIMIT 1');
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
        $ie=$this->text($data['state_registration']??'',32);
        if($enabled&&$ie==='')throw new RuntimeException('Informe a inscrição estadual.');

        $legalName=$this->text($data['legal_name']??'',190);
        $tradeName=$this->text($data['trade_name']??'',190);
        $cityCode=$this->digits($data['city_code']??'',16);
        $taxRegime=$this->text($data['tax_regime']??'',16);
        $street=$this->text($data['street']??'',190);
        $addressNumber=$this->text($data['address_number']??'',32);
        $addressComplement=$this->text($data['address_complement']??'',120);
        $district=$this->text($data['district']??'',120);
        $cityName=$this->text($data['city_name']??'',160);
        $postalCode=$this->digits($data['postal_code']??'',8);
        $phone=$this->text($data['phone']??'',30);
        $email=$this->text($data['email']??'',190);
        $municipalRegistration=$this->text($data['municipal_registration']??'',32);
        $cnae=$this->digits($data['cnae']??'',12);
        $countryCode=$this->digits($data['country_code']??'1058',8)?:'1058';
        $countryName=$this->text($data['country_name']??'Brasil',80)?:'Brasil';
        $nfceSeries=max(1,(int)($data['nfce_series']??1));
        $nfeSeries=max(1,(int)($data['nfe_series']??1));
        $cscId=$this->text($data['csc_id']??'',32);
        $newCsc=trim((string)($data['csc_token']??''));
        $contingency=!empty($data['contingency_enabled'])?1:0;

        if($enabled){
            if($legalName==='')throw new RuntimeException('Informe a razão social do emitente.');
            if($cityCode===''||$street===''||$addressNumber===''||$district===''||$cityName===''||strlen($postalCode)!==8)throw new RuntimeException('Complete o endereço fiscal do emitente.');
            if($taxRegime==='')throw new RuntimeException('Informe o regime tributário do emitente.');
        }

        return Database::transaction(function(PDO $tx)use($tenantId,$unitId,$enabled,$environment,$defaultDocument,$legalName,$tradeName,$cnpj,$ie,$stateCode,$cityCode,$taxRegime,$street,$addressNumber,$addressComplement,$district,$cityName,$postalCode,$phone,$email,$municipalRegistration,$cnae,$countryCode,$countryName,$nfceSeries,$nfeSeries,$cscId,$newCsc,$certificateMode,$contingency):array{
            $q=$tx->prepare(Database::portableSql($tx,'SELECT * FROM fiscal_profiles WHERE tenant_id=? AND unit_id=? LIMIT 1 FOR UPDATE'));
            $q->execute([$tenantId,$unitId]);
            $existing=$q->fetch();
            $cscEncrypted=$existing['csc_token_encrypted']??null;
            if($newCsc!=='')$cscEncrypted=Crypto::encrypt($newCsc);
            if($existing){
                $s=$tx->prepare('UPDATE fiscal_profiles SET enabled=?,environment=?,default_document=?,legal_name=?,trade_name=?,cnpj=?,state_registration=?,state_code=?,city_code=?,tax_regime=?,street=?,address_number=?,address_complement=?,district=?,city_name=?,postal_code=?,phone=?,email=?,municipal_registration=?,cnae=?,country_code=?,country_name=?,nfce_series=?,nfe_series=?,csc_id=?,csc_token_encrypted=?,certificate_mode=?,contingency_enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');
                $s->execute([$enabled,$environment,$defaultDocument,$legalName?:null,$tradeName?:null,$cnpj,$ie,$stateCode,$cityCode?:null,$taxRegime?:null,$street?:null,$addressNumber?:null,$addressComplement?:null,$district?:null,$cityName?:null,$postalCode?:null,$phone?:null,$email?:null,$municipalRegistration?:null,$cnae?:null,$countryCode,$countryName,$nfceSeries,$nfeSeries,$cscId?:null,$cscEncrypted,$certificateMode,$contingency,(int)$existing['id'],$tenantId]);
                $id=(int)$existing['id'];
            }else{
                $s=$tx->prepare('INSERT INTO fiscal_profiles (tenant_id,unit_id,enabled,environment,default_document,legal_name,trade_name,cnpj,state_registration,state_code,city_code,tax_regime,street,address_number,address_complement,district,city_name,postal_code,phone,email,municipal_registration,cnae,country_code,country_name,nfce_series,nfe_series,csc_id,csc_token_encrypted,certificate_mode,contingency_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $s->execute([$tenantId,$unitId,$enabled,$environment,$defaultDocument,$legalName?:null,$tradeName?:null,$cnpj,$ie,$stateCode,$cityCode?:null,$taxRegime?:null,$street?:null,$addressNumber?:null,$addressComplement?:null,$district?:null,$cityName?:null,$postalCode?:null,$phone?:null,$email?:null,$municipalRegistration?:null,$cnae?:null,$countryCode,$countryName,$nfceSeries,$nfeSeries,$cscId?:null,$cscEncrypted,$certificateMode,$contingency]);
                $id=(int)$tx->lastInsertId();
            }
            Auth::audit('fiscal.profile_saved','fiscal_profile',(string)$id,['unit_id'=>$unitId,'enabled'=>(bool)$enabled,'environment'=>$environment,'certificate_mode'=>$certificateMode]);
            return $this->profileForUnit($unitId);
        });
    }

    public function products():array
    {
        Auth::requirePermission('fiscal.manage');
        $tenantId=Auth::tenantId();if(!$tenantId)return[];
        $s=Database::connection()->prepare('SELECT p.id product_id,p.name,p.sku,p.active,pfd.id fiscal_id,pfd.ncm,pfd.cest,pfd.cfop,pfd.commercial_unit,pfd.tributary_unit,pfd.origin,pfd.gtin,pfd.gtin_tributary,pfd.icms_cst,pfd.icms_csosn,pfd.icms_rate,pfd.pis_cst,pfd.pis_rate,pfd.cofins_cst,pfd.cofins_rate,pfd.ipi_cst,pfd.ipi_rate,pfd.benefit_code,pfd.tax_json,pfd.enabled fiscal_enabled FROM products p LEFT JOIN product_fiscal_data pfd ON pfd.product_id=p.id AND pfd.tenant_id=p.tenant_id WHERE p.tenant_id=? ORDER BY p.active DESC,p.name');
        $s->execute([$tenantId]);$rows=$s->fetchAll();
        foreach($rows as &$row){$row['tax']=json_decode((string)($row['tax_json']??''),true)?:[];unset($row['tax_json']);$row['ready']=$this->productFiscalReady($row);}unset($row);
        return$rows;
    }

    public function saveProductFiscal(array $data):array
    {
        Auth::requirePermission('fiscal.manage');$tenantId=Auth::tenantId();if(!$tenantId)throw new RuntimeException('Empresa inválida.');
        $productId=(int)($data['product_id']??0);if($productId<1)throw new RuntimeException('Produto inválido.');
        $pdo=Database::connection();$p=$pdo->prepare('SELECT id,name FROM products WHERE id=? AND tenant_id=? LIMIT 1');$p->execute([$productId,$tenantId]);$product=$p->fetch();if(!$product)throw new RuntimeException('Produto não encontrado.');
        $row=[
            'ncm'=>$this->digits($data['ncm']??'',8),'cest'=>$this->digits($data['cest']??'',7),'cfop'=>$this->digits($data['cfop']??'',4),
            'commercial_unit'=>strtoupper($this->text($data['commercial_unit']??'UN',6)),'tributary_unit'=>strtoupper($this->text($data['tributary_unit']??'UN',6)),
            'origin'=>$this->digits($data['origin']??'0',1),'gtin'=>$this->digits($data['gtin']??'',14),'gtin_tributary'=>$this->digits($data['gtin_tributary']??'',14),
            'icms_cst'=>$this->digits($data['icms_cst']??'',3),'icms_csosn'=>$this->digits($data['icms_csosn']??'',3),'icms_rate'=>$this->decimalOrNull($data['icms_rate']??null),
            'pis_cst'=>$this->digits($data['pis_cst']??'',2),'pis_rate'=>$this->decimalOrNull($data['pis_rate']??null),
            'cofins_cst'=>$this->digits($data['cofins_cst']??'',2),'cofins_rate'=>$this->decimalOrNull($data['cofins_rate']??null),
            'ipi_cst'=>$this->digits($data['ipi_cst']??'',2),'ipi_rate'=>$this->decimalOrNull($data['ipi_rate']??null),
            'benefit_code'=>$this->text($data['benefit_code']??'',20),'enabled'=>array_key_exists('enabled',$data)?(!empty($data['enabled'])?1:0):1,
        ];
        $tax=is_array($data['tax']??null)?$this->sanitizeArray($data['tax']):[];$taxJson=$tax?json_encode($tax,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;
        if($row['enabled']&&!$this->productFiscalReady($row))throw new RuntimeException('Complete NCM, CFOP, unidade, origem, ICMS, PIS e COFINS do produto.');
        $existing=$pdo->prepare('SELECT id FROM product_fiscal_data WHERE tenant_id=? AND product_id=? LIMIT 1');$existing->execute([$tenantId,$productId]);$id=(int)$existing->fetchColumn();
        if($id>0){$s=$pdo->prepare('UPDATE product_fiscal_data SET ncm=?,cest=?,cfop=?,commercial_unit=?,tributary_unit=?,origin=?,gtin=?,gtin_tributary=?,icms_cst=?,icms_csosn=?,icms_rate=?,pis_cst=?,pis_rate=?,cofins_cst=?,cofins_rate=?,ipi_cst=?,ipi_rate=?,benefit_code=?,tax_json=?,enabled=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?');$s->execute([$row['ncm'],$row['cest']?:null,$row['cfop'],$row['commercial_unit'],$row['tributary_unit'],$row['origin'],$row['gtin']?:null,$row['gtin_tributary']?:null,$row['icms_cst']?:null,$row['icms_csosn']?:null,$row['icms_rate'],$row['pis_cst']?:null,$row['pis_rate'],$row['cofins_cst']?:null,$row['cofins_rate'],$row['ipi_cst']?:null,$row['ipi_rate'],$row['benefit_code']?:null,$taxJson,$row['enabled'],$id,$tenantId]);}
        else{$s=$pdo->prepare('INSERT INTO product_fiscal_data (tenant_id,product_id,ncm,cest,cfop,commercial_unit,tributary_unit,origin,gtin,gtin_tributary,icms_cst,icms_csosn,icms_rate,pis_cst,pis_rate,cofins_cst,cofins_rate,ipi_cst,ipi_rate,benefit_code,tax_json,enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');$s->execute([$tenantId,$productId,$row['ncm'],$row['cest']?:null,$row['cfop'],$row['commercial_unit'],$row['tributary_unit'],$row['origin'],$row['gtin']?:null,$row['gtin_tributary']?:null,$row['icms_cst']?:null,$row['icms_csosn']?:null,$row['icms_rate'],$row['pis_cst']?:null,$row['pis_rate'],$row['cofins_cst']?:null,$row['cofins_rate'],$row['ipi_cst']?:null,$row['ipi_rate'],$row['benefit_code']?:null,$taxJson,$row['enabled']]);$id=(int)$pdo->lastInsertId();}
        Auth::audit('fiscal.product_saved','product',(string)$productId,['fiscal_id'=>$id,'ready'=>$this->productFiscalReady($row)]);
        foreach($this->products() as $item)if((int)$item['product_id']===$productId)return$item;
        throw new RuntimeException('Falha ao recarregar dados fiscais do produto.');
    }

    public function readiness(int $unitId):array
    {
        Auth::requirePermission('fiscal.manage');$profile=$this->profileForUnit($unitId);$products=$this->products();$missing=[];
        foreach($products as $p)if((int)($p['active']??0)===1&&!$p['ready'])$missing[]=['product_id'=>(int)$p['product_id'],'name'=>$p['name']];
        $profileReady=$profile!==[]&&$this->issuerReady($profile,(string)($profile['default_document']??'nfce'));
        return['profile_ready'=>$profileReady,'certificate_ready'=>$this->certificateReady($profile),'products_missing'=>$missing,'ready'=>$profileReady&&$this->certificateReady($profile)&&$missing===[]];
    }

    public function queueForOrder(int $orderId,string $document=''):array
    {
        Auth::requirePermission('fiscal.issue');
        $tenantId=Auth::tenantId();
        if(!$tenantId||$orderId<1)throw new RuntimeException('Pedido inválido.');
        return Database::transaction(function(PDO $tx)use($tenantId,$orderId,$document):array{
            $o=$tx->prepare(Database::portableSql($tx,'SELECT o.*,c.name customer_name,c.email customer_email,c.phone customer_phone,c.document customer_document,c.state_registration customer_state_registration,c.fiscal_street,c.fiscal_number,c.fiscal_complement,c.fiscal_district,c.fiscal_city_name,c.fiscal_city_code,c.fiscal_state_code,c.fiscal_postal_code,c.fiscal_country_code,c.fiscal_country_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id AND c.tenant_id=o.tenant_id WHERE o.id=? AND o.tenant_id=? LIMIT 1 FOR UPDATE'));
            $o->execute([$orderId,$tenantId]);$order=$o->fetch();
            if(!$order)throw new RuntimeException('Pedido não encontrado.');
            if((string)$order['status']==='cancelled')throw new RuntimeException('Pedido cancelado não pode gerar documento fiscal.');
            if((string)$order['payment_status']!=='paid')throw new RuntimeException('O pedido precisa estar pago antes da emissão fiscal.');
            $unitId=(int)($order['unit_id']??0);if($unitId<1)throw new RuntimeException('Pedido sem unidade fiscal definida.');

            $p=$tx->prepare(Database::portableSql($tx,'SELECT * FROM fiscal_profiles WHERE tenant_id=? AND unit_id=? AND enabled=1 LIMIT 1 FOR UPDATE'));
            $p->execute([$tenantId,$unitId]);$profile=$p->fetch();if(!$profile)throw new RuntimeException('Perfil fiscal ativo não configurado para esta unidade.');
            $kind=$document!==''?strtolower($document):(string)$profile['default_document'];if(!in_array($kind,['nfce','nfe'],true))throw new RuntimeException('Documento fiscal inválido.');
            if(!$this->issuerReady($profile,$kind))throw new RuntimeException('O cadastro fiscal do emitente está incompleto para '.strtoupper($kind).'.');
            if(!$this->certificateReady($profile,$tx))throw new RuntimeException('Certificado digital fiscal não está pronto ou está expirado.');
            if($kind==='nfe'&&!$this->recipientReady($order))throw new RuntimeException('NF-e exige destinatário com documento e endereço fiscal completos.');

            $items=$tx->prepare('SELECT oi.id,oi.product_id,oi.name_snapshot,oi.unit_price_cents,oi.quantity,oi.total_cents,p.sku,pfd.ncm,pfd.cest,pfd.cfop,pfd.commercial_unit,pfd.tributary_unit,pfd.origin,pfd.gtin,pfd.gtin_tributary,pfd.icms_cst,pfd.icms_csosn,pfd.icms_rate,pfd.pis_cst,pfd.pis_rate,pfd.cofins_cst,pfd.cofins_rate,pfd.ipi_cst,pfd.ipi_rate,pfd.benefit_code,pfd.tax_json,pfd.enabled fiscal_enabled FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id LEFT JOIN product_fiscal_data pfd ON pfd.product_id=oi.product_id AND pfd.tenant_id=? WHERE oi.order_id=? ORDER BY oi.id');
            $items->execute([$tenantId,$orderId]);$lines=$items->fetchAll();if(!$lines)throw new RuntimeException('Pedido sem itens fiscais.');
            foreach($lines as &$line){if(!$this->productFiscalReady($line)||empty($line['fiscal_enabled']))throw new RuntimeException('Produto "'.(string)$line['name_snapshot'].'" está sem classificação fiscal completa.');$line['tax']=json_decode((string)($line['tax_json']??''),true)?:[];unset($line['tax_json']);}unset($line);

            $model=$kind==='nfce'?'65':'55';$idempotency='fiscal:order:'.$orderId.':model:'.$model;
            $existing=$tx->prepare('SELECT * FROM fiscal_documents WHERE tenant_id=? AND idempotency_key=? LIMIT 1');$existing->execute([$tenantId,$idempotency]);if($row=$existing->fetch())return$row;

            $seriesField=$kind==='nfce'?'nfce_series':'nfe_series';$nextField=$kind==='nfce'?'nfce_next_number':'nfe_next_number';$series=max(1,(int)$profile[$seriesField]);$number=max(1,(int)$profile[$nextField]);
            $snapshot=$this->snapshot($tenantId,$kind,$model,$series,$number,$profile,$order,$lines);
            $snapshotJson=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);$snapshotHash=hash('sha256',$snapshotJson);$snapshotEncrypted=Crypto::encrypt($snapshot);
            $tx->prepare('UPDATE fiscal_profiles SET '.$nextField.'=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND tenant_id=?')->execute([$number+1,$profile['id'],$tenantId]);
            $insert=$tx->prepare('INSERT INTO fiscal_documents (tenant_id,unit_id,fiscal_profile_id,order_id,model,environment,series,document_number,status,snapshot_encrypted,snapshot_hash,idempotency_key) VALUES (?,?,?,?,?,?,?,?,"queued",?,?,?)');
            $insert->execute([$tenantId,$unitId,$profile['id'],$orderId,$model,$profile['environment'],$series,$number,$snapshotEncrypted,$snapshotHash,$idempotency]);
            $id=(int)$tx->lastInsertId();Auth::audit('fiscal.document_queued','fiscal_document',(string)$id,['order_id'=>$orderId,'model'=>$model,'series'=>$series,'number'=>$number,'unit_id'=>$unitId,'snapshot_hash'=>$snapshotHash]);
            $q=$tx->prepare('SELECT id,tenant_id,unit_id,fiscal_profile_id,order_id,model,environment,series,document_number,status,access_key,protocol,rejection_code,rejection_message,idempotency_key,issued_at,authorized_at,cancelled_at,created_at,updated_at FROM fiscal_documents WHERE id=? AND tenant_id=?');$q->execute([$id,$tenantId]);return$q->fetch()?:throw new RuntimeException('Falha ao preparar documento fiscal.');
        });
    }

    public function documents(int $limit=100):array
    {
        Auth::requirePermission('fiscal.issue');$tenantId=Auth::tenantId();if(!$tenantId)return[];$limit=max(1,min(200,$limit));
        $s=Database::connection()->prepare('SELECT fd.id,fd.unit_id,fd.order_id,fd.model,fd.environment,fd.series,fd.document_number,fd.status,fd.access_key,fd.protocol,fd.rejection_code,fd.rejection_message,fd.snapshot_hash,fd.issued_at,fd.authorized_at,fd.cancelled_at,fd.created_at,ou.name unit_name FROM fiscal_documents fd LEFT JOIN operating_units ou ON ou.id=fd.unit_id AND ou.tenant_id=fd.tenant_id WHERE fd.tenant_id=? ORDER BY fd.id DESC LIMIT '.$limit);$s->execute([$tenantId]);return$s->fetchAll();
    }

    public function snapshotForDocument(int $documentId):array
    {
        Auth::requirePermission('fiscal.issue');$tenantId=Auth::tenantId();if(!$tenantId||$documentId<1)throw new RuntimeException('Documento fiscal inválido.');$s=Database::connection()->prepare('SELECT snapshot_encrypted,snapshot_hash FROM fiscal_documents WHERE id=? AND tenant_id=? LIMIT 1');$s->execute([$documentId,$tenantId]);$row=$s->fetch();if(!$row||empty($row['snapshot_encrypted']))throw new RuntimeException('Snapshot fiscal não encontrado.');$snapshot=Crypto::decryptJson((string)$row['snapshot_encrypted']);$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);if(!hash_equals((string)$row['snapshot_hash'],hash('sha256',$json)))throw new RuntimeException('Integridade do snapshot fiscal não confere.');return$snapshot;
    }

    private function snapshot(int $tenantId,string $kind,string $model,int $series,int $number,array $profile,array $order,array $items):array
    {
        $issuer=$profile;foreach(['csc_token_encrypted','created_at','updated_at'] as $key)unset($issuer[$key]);
        return[
            'version'=>1,'tenant_id'=>$tenantId,'kind'=>$kind,'model'=>$model,'environment'=>$profile['environment'],'series'=>$series,'document_number'=>$number,'created_at'=>gmdate('c'),
            'issuer'=>$issuer,
            'recipient'=>['customer_id'=>$order['customer_id']??null,'name'=>$order['customer_name']??null,'document'=>$this->digits($order['customer_document']??'',14),'state_registration'=>$order['customer_state_registration']??null,'email'=>$order['customer_email']??null,'phone'=>$order['customer_phone']??null,'street'=>$order['fiscal_street']??null,'number'=>$order['fiscal_number']??null,'complement'=>$order['fiscal_complement']??null,'district'=>$order['fiscal_district']??null,'city_name'=>$order['fiscal_city_name']??null,'city_code'=>$order['fiscal_city_code']??null,'state_code'=>$order['fiscal_state_code']??null,'postal_code'=>$order['fiscal_postal_code']??null,'country_code'=>$order['fiscal_country_code']??'1058','country_name'=>$order['fiscal_country_name']??'Brasil'],
            'order'=>['id'=>(int)$order['id'],'channel'=>$order['channel']??null,'subtotal_cents'=>(int)($order['subtotal_cents']??0),'discount_cents'=>(int)($order['discount_cents']??0),'delivery_fee_cents'=>(int)($order['delivery_fee_cents']??0),'total_cents'=>(int)$order['total_cents'],'created_at'=>$order['created_at']??null],
            'items'=>$items,
        ];
    }

    private function issuerReady(array $profile,string $kind):bool
    {
        $basic=strlen($this->digits($profile['cnpj']??'',14))===14&&trim((string)($profile['legal_name']??''))!==''&&trim((string)($profile['state_registration']??''))!==''&&preg_match('/^[A-Z]{2}$/',(string)($profile['state_code']??''))&&$this->digits($profile['city_code']??'',16)!==''&&trim((string)($profile['city_name']??''))!==''&&trim((string)($profile['street']??''))!==''&&trim((string)($profile['address_number']??''))!==''&&trim((string)($profile['district']??''))!==''&&strlen($this->digits($profile['postal_code']??'',8))===8&&trim((string)($profile['tax_regime']??''))!=='';
        if(!$basic)return false;if($kind==='nfce'&&(empty($profile['csc_id'])||empty($profile['csc_token_encrypted'])&&empty($profile['has_csc'])))return false;return true;
    }

    private function certificateReady(array $profile,?PDO $pdo=null):bool
    {
        if(!$profile)return false;$mode=(string)($profile['certificate_mode']??'server');if(in_array($mode,['desktop','a3_local'],true))return true;$pdo??=Database::connection();$s=$pdo->prepare('SELECT id FROM fiscal_certificates WHERE tenant_id=? AND fiscal_profile_id=? AND status="active" AND (valid_until IS NULL OR valid_until>CURRENT_TIMESTAMP) ORDER BY id DESC LIMIT 1');$s->execute([$profile['tenant_id'],$profile['id']]);return(bool)$s->fetchColumn();
    }

    private function recipientReady(array $order):bool
    {
        $document=$this->digits($order['customer_document']??'',14);return in_array(strlen($document),[11,14],true)&&trim((string)($order['customer_name']??''))!==''&&trim((string)($order['fiscal_street']??''))!==''&&trim((string)($order['fiscal_number']??''))!==''&&trim((string)($order['fiscal_district']??''))!==''&&trim((string)($order['fiscal_city_name']??''))!==''&&$this->digits($order['fiscal_city_code']??'',16)!==''&&preg_match('/^[A-Z]{2}$/',(string)($order['fiscal_state_code']??''))&&strlen($this->digits($order['fiscal_postal_code']??'',8))===8;
    }

    private function productFiscalReady(array $row):bool
    {
        return strlen($this->digits($row['ncm']??'',8))===8&&strlen($this->digits($row['cfop']??'',4))===4&&trim((string)($row['commercial_unit']??''))!==''&&trim((string)($row['tributary_unit']??''))!==''&&preg_match('/^[0-8]$/',(string)($row['origin']??''))&&(strlen($this->digits($row['icms_cst']??'',3))>=2||strlen($this->digits($row['icms_csosn']??'',3))===3)&&strlen($this->digits($row['pis_cst']??'',2))===2&&strlen($this->digits($row['cofins_cst']??'',2))===2;
    }

    private function text(mixed $value,int $limit):string{return mb_substr(trim((string)$value),0,$limit);}
    private function digits(mixed $value,int $limit):string{return substr(preg_replace('/\D+/','',(string)$value)??'',0,$limit);}
    private function decimalOrNull(mixed $value):?float{if($value===null||$value==='')return null;$v=str_replace(',','.',trim((string)$value));return is_numeric($v)?round((float)$v,4):null;}
    private function sanitizeArray(array $data):array{$out=[];foreach(array_slice($data,0,100,true) as $key=>$value){$key=$this->text($key,80);if($key==='')continue;if(is_scalar($value)||$value===null)$out[$key]=is_string($value)?$this->text($value,500):$value;elseif(is_array($value))$out[$key]=$this->sanitizeArray($value);}return$out;}
}
