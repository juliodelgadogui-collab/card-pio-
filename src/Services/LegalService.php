<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use EventMenu\Core\Security;
use RuntimeException;

final class LegalService
{
    public function publish(string $type,string $version,string $title,string $content):int
    {
        Auth::requirePermission('legal.manage');if(!in_array($type,['terms','privacy'],true))throw new RuntimeException('Tipo de documento inválido.');$version=trim($version);$title=trim($title);$content=trim($content);if($version===''||$title===''||$content==='')throw new RuntimeException('Preencha versão, título e conteúdo.');$hash=hash('sha256',$content);$pdo=Database::connection();$pdo->prepare('UPDATE legal_documents SET active=0 WHERE document_type=?')->execute([$type]);$s=$pdo->prepare('INSERT INTO legal_documents (document_type,version,content_hash,title,content,published_at,active) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),1) ON DUPLICATE KEY UPDATE content_hash=VALUES(content_hash),title=VALUES(title),content=VALUES(content),published_at=UTC_TIMESTAMP(),active=1');$s->execute([$type,$version,$hash,$title,$content]);$id=(int)($pdo->lastInsertId()?:0);if(!$id){$q=$pdo->prepare('SELECT id FROM legal_documents WHERE document_type=? AND version=?');$q->execute([$type,$version]);$id=(int)$q->fetchColumn();}Auth::audit('legal.published','legal_document',(string)$id,['type'=>$type,'version'=>$version]);return$id;
    }
    public function pendingForUser(int $userId):array
    {
        $s=Database::connection()->prepare('SELECT d.* FROM legal_documents d LEFT JOIN legal_acceptances a ON a.legal_document_id=d.id AND a.user_id=? WHERE d.active=1 AND a.id IS NULL ORDER BY d.document_type');$s->execute([$userId]);return$s->fetchAll();
    }
    public function accept(int $tenantId,int $userId,int $documentId):void
    {
        $pdo=Database::connection();$s=$pdo->prepare('SELECT * FROM legal_documents WHERE id=? AND active=1 LIMIT 1');$s->execute([$documentId]);$d=$s->fetch();if(!$d)throw new RuntimeException('Documento legal não encontrado.');$pdo->prepare('INSERT IGNORE INTO legal_acceptances (tenant_id,user_id,legal_document_id,document_type,version,content_hash,ip_address,user_agent,accepted_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$tenantId?:null,$userId,$documentId,$d['document_type'],$d['version'],$d['content_hash'],Security::clientIp(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);Auth::audit('legal.accepted','legal_document',(string)$documentId,['version'=>$d['version']]);
    }
}
