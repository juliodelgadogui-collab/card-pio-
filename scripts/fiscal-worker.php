<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\FiscalLifecycleTransmissionService;
use EventMenu\Services\FiscalLifecycleTransmitterInterface;
use EventMenu\Services\FiscalTransmissionService;
use EventMenu\Services\FiscalTransmitterFactory;

$limit = isset($argv[1]) ? max(1, min(50, (int)$argv[1])) : 10;

if (!FiscalTransmitterFactory::isConfigured()) {
    fwrite(STDOUT, "EventMenu Fiscal: nenhum transmissor SEFAZ configurado; filas preservadas.\n");
    exit(0);
}

try {
    $transmitter = FiscalTransmitterFactory::make();
    $pdo = Database::connection();
    $processed=0;$failed=0;

    // Documento normal. Uma contingência já solicitada precisa ser resolvida pelo fluxo local,
    // portanto o worker não disputa a mesma nota com o Desktop.
    $sql = 'SELECT id,tenant_id FROM fiscal_documents WHERE status="queued" AND (contingency_mode IS NULL OR contingency_mode="") ORDER BY id LIMIT '.$limit;
    $documents = $pdo->query($sql)->fetchAll();
    $service = new FiscalTransmissionService();
    foreach ($documents as $document) {
        $id=(int)$document['id'];$tenantId=(int)$document['tenant_id'];
        try{$result=$service->transmit($tenantId,$id,$transmitter);fwrite(STDOUT,"Documento {$id}: ".(string)($result['status']??'unknown')."\n");$processed++;}
        catch(Throwable $e){fwrite(STDERR,"Documento {$id}: ".worker_message($e)."\n");$failed++;}
    }

    if($transmitter instanceof FiscalLifecycleTransmitterInterface){
        $lifecycle=new FiscalLifecycleTransmissionService();

        $events=$pdo->query('SELECT id,tenant_id FROM fiscal_events WHERE status="queued" AND event_type="cancel" ORDER BY id LIMIT '.$limit)->fetchAll();
        foreach($events as$event){$id=(int)$event['id'];$tenantId=(int)$event['tenant_id'];try{$result=$lifecycle->transmitCancellation($tenantId,$id,$transmitter);fwrite(STDOUT,"Cancelamento {$id}: ".(string)($result['status']??'unknown')."\n");$processed++;}catch(Throwable$e){fwrite(STDERR,"Cancelamento {$id}: ".worker_message($e)."\n");$failed++;}}

        $ranges=$pdo->query('SELECT id,tenant_id FROM fiscal_inutilizations WHERE status="queued" ORDER BY id LIMIT '.$limit)->fetchAll();
        foreach($ranges as$range){$id=(int)$range['id'];$tenantId=(int)$range['tenant_id'];try{$result=$lifecycle->transmitInutilization($tenantId,$id,$transmitter);fwrite(STDOUT,"Inutilização {$id}: ".(string)($result['status']??'unknown')."\n");$processed++;}catch(Throwable$e){fwrite(STDERR,"Inutilização {$id}: ".worker_message($e)."\n");$failed++;}}

        $contingencies=$pdo->query('SELECT id,tenant_id FROM fiscal_documents WHERE status="contingency" AND contingency_xml_encrypted IS NOT NULL AND contingency_synced_at IS NULL ORDER BY id LIMIT '.$limit)->fetchAll();
        foreach($contingencies as$document){$id=(int)$document['id'];$tenantId=(int)$document['tenant_id'];try{$result=$lifecycle->transmitContingency($tenantId,$id,$transmitter);fwrite(STDOUT,"Contingência {$id}: ".(string)($result['status']??'unknown')."\n");$processed++;}catch(Throwable$e){fwrite(STDERR,"Contingência {$id}: ".worker_message($e)."\n");$failed++;}}
    }else{
        $pendingLifecycle=(int)$pdo->query('SELECT (SELECT COUNT(*) FROM fiscal_events WHERE status="queued") + (SELECT COUNT(*) FROM fiscal_inutilizations WHERE status="queued")')->fetchColumn();
        if($pendingLifecycle>0)fwrite(STDOUT,"EventMenu Fiscal: {$pendingLifecycle} evento(s) aguardam transmissor com suporte ao ciclo fiscal.\n");
    }

    if($processed===0&&$failed===0)fwrite(STDOUT,"EventMenu Fiscal: filas vazias.\n");
    else fwrite(STDOUT,"EventMenu Fiscal: {$processed} processado(s), {$failed} falha(s).\n");
    exit($failed>0?1:0);
} catch (Throwable $e) {
    fwrite(STDERR, "EventMenu Fiscal: ".worker_message($e)."\n");
    exit(1);
}

function worker_message(Throwable $e):string
{
    $message=$e->getMessage();$lower=strtolower($message);
    if(str_contains($lower,'sqlstate')||str_contains($lower,'stack trace')||str_contains($lower,'exception'))return'falha técnica';
    return $message!==''?$message:'falha técnica';
}
