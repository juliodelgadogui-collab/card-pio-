<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\TenantBrandService;

$token=trim((string)($_GET['t']??''));
$pdo=Database::connection();
$s=$pdo->prepare('SELECT t.code,t.status,e.name event_name,e.starts_at,e.venue,e.address,e.primary_color,b.name batch_name,c.name customer_name,tt.name ticket_type_name,tt.access_area FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN customers c ON c.id=t.customer_id LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE t.qr_token=? LIMIT 1');
$s->execute([$token]);$t=$s->fetch(PDO::FETCH_ASSOC);
if(!$t||!in_array((string)$t['status'],['paid','checked_in'],true)){http_response_code(404);exit;}
if(!function_exists('imagecreatetruecolor')||!class_exists('Endroid\\QrCode\\QrCode')||!class_exists('Endroid\\QrCode\\Writer\\PngWriter')){http_response_code(503);exit;}

$hex=function(string$v,string$f):string{$v=strtolower(trim($v));return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:$f;};
$brand=(new TenantBrandService())->defaults();$primary=$hex((string)($t['primary_color']??''),(string)($brand['primary_color']??'#6d28d9'));
$rgb=static function($im,string$h):int{return imagecolorallocate($im,hexdec(substr($h,1,2)),hexdec(substr($h,3,2)),hexdec(substr($h,5,2)));};
$im=imagecreatetruecolor(1080,1600);$white=imagecolorallocate($im,255,255,255);$dark=imagecolorallocate($im,31,29,43);$muted=imagecolorallocate($im,98,95,111);$accent=$rgb($im,$primary);imagefill($im,0,0,$white);imagefilledrectangle($im,0,0,1080,360,$accent);
$font=5;$line=26;
$write=function(string$text,int$x,int$y,int$color,int$max=54)use($im,$font,$line):int{$words=preg_split('/\s+/',trim($text))?:[];$row='';foreach($words as$w){$test=trim($row.' '.$w);if(strlen($test)>$max&&$row!==''){imagestring($im,$font,$x,$y,$row,$color);$y+=$line;$row=$w;}else$row=$test;}if($row!==''){imagestring($im,$font,$x,$y,$row,$color);$y+=$line;}return$y;};
imagestring($im,5,70,80,'EVENTMENU - SEU INGRESSO',$white);$y=$write((string)$t['event_name'],70,135,$white,48);$date=!empty($t['starts_at'])?date('d/m/Y H:i',strtotime((string)$t['starts_at'])):'';$y=430;imagestring($im,5,70,$y,$date,$dark);$y+=55;$y=$write(trim((string)($t['venue']??'')).' '.trim((string)($t['address']??'')),70,$y,$muted,65);$y+=30;
imagestring($im,5,70,$y,'COMPRADOR: '.mb_strtoupper(trim((string)($t['customer_name']??'Ingresso'))),$dark);$y+=55;imagestring($im,5,70,$y,'TIPO: '.mb_strtoupper(trim((string)($t['ticket_type_name']??'Ingresso'))),$dark);$y+=40;imagestring($im,5,70,$y,'LOTE: '.mb_strtoupper((string)$t['batch_name']),$dark);$y+=40;if(trim((string)($t['access_area']??''))!==''){imagestring($im,5,70,$y,'ACESSO: '.mb_strtoupper((string)$t['access_area']),$dark);$y+=45;}
$url=\app_absolute_url('ingresso.php?t='.rawurlencode($token));$qr=new \Endroid\QrCode\QrCode(data:$url,size:500,margin:12);$png=(new \Endroid\QrCode\Writer\PngWriter())->write($qr)->getString();$qri=@imagecreatefromstring($png);if($qri){imagecopyresampled($im,$qri,290,820,0,0,500,500,imagesx($qri),imagesy($qri));imagedestroy($qri);}imagestring($im,5,330,1360,(string)$t['code'],$muted);imagestring($im,5,345,1430,'APRESENTE O QR NA ENTRADA',$accent);imagestring($im,3,420,1515,'Tecnologia EventMenu',$muted);
header('Content-Type: image/png');header('Cache-Control: private, no-store, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');imagepng($im,null,6);imagedestroy($im);
