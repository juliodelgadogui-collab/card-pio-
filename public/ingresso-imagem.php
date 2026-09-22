<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';

use EventMenu\Core\Database;
use EventMenu\Services\TenantBrandService;

$token=trim((string)($_GET['t']??''));$expectedId=(int)($_GET['ticket_id']??0);
$pdo=Database::connection();
$s=$pdo->prepare('SELECT t.id ticket_id,t.code,t.status,t.tenant_id,e.name event_name,e.public_subtitle,e.starts_at,e.venue,e.address,e.banner_url,e.primary_color,e.secondary_color,b.name batch_name,c.name customer_name,tt.name ticket_type_name,tt.access_area FROM tickets t JOIN events e ON e.id=t.event_id JOIN ticket_batches b ON b.id=t.batch_id LEFT JOIN customers c ON c.id=t.customer_id LEFT JOIN ticket_types tt ON tt.id=b.ticket_type_id WHERE t.qr_token=? LIMIT 1');
$s->execute([$token]);$t=$s->fetch(PDO::FETCH_ASSOC);
if(!$t||($expectedId>0&&$expectedId!==(int)$t['ticket_id'])||!in_array((string)$t['status'],['paid','checked_in'],true)){http_response_code(404);exit;}
if(!function_exists('imagecreatetruecolor')||!class_exists('Endroid\\QrCode\\QrCode')||!class_exists('Endroid\\QrCode\\Writer\\PngWriter')){http_response_code(503);exit;}

$brandService=new TenantBrandService();$tenantBrand=$brandService->get((int)$t['tenant_id']);$visual=(bool)($tenantBrand['apply_web']??false)?$tenantBrand:$brandService->defaults();
$hex=static function(mixed$v,string$f):string{$v=strtolower(trim((string)$v));return preg_match('/^#[0-9a-f]{6}$/',$v)?$v:$f;};
$primary=$hex($t['primary_color']??'',(string)($visual['primary_color']??'#6d28d9'));$secondary=$hex($t['secondary_color']??'',(string)($visual['secondary_color']??'#21134f'));
$rgb=static function($im,string$h):int{return imagecolorallocate($im,hexdec(substr($h,1,2)),hexdec(substr($h,3,2)),hexdec(substr($h,5,2)));};
$im=imagecreatetruecolor(1080,1600);imagealphablending($im,true);$white=imagecolorallocate($im,255,255,255);$surface=imagecolorallocate($im,247,247,251);$dark=imagecolorallocate($im,32,29,43);$muted=imagecolorallocate($im,100,95,111);$green=imagecolorallocate($im,23,98,58);$p=$rgb($im,$primary);$scolor=$rgb($im,$secondary);
imagefill($im,0,0,$surface);
for($y=0;$y<500;$y++){ $ratio=$y/499;$r=(int)(hexdec(substr($secondary,1,2))*(1-$ratio)+hexdec(substr($primary,1,2))*$ratio);$g=(int)(hexdec(substr($secondary,3,2))*(1-$ratio)+hexdec(substr($primary,3,2))*$ratio);$b=(int)(hexdec(substr($secondary,5,2))*(1-$ratio)+hexdec(substr($primary,5,2))*$ratio);imageline($im,0,$y,1080,$y,imagecolorallocate($im,$r,$g,$b));}
$banner=trim((string)($t['banner_url']??''));if($banner!==''&&str_starts_with($banner,'https://')){try{$ctx=stream_context_create(['http'=>['timeout'=>4,'user_agent'=>'EventMenu-Ticket/1.0']]);$raw=@file_get_contents($banner,false,$ctx,0,8*1024*1024);$bi=$raw?@imagecreatefromstring($raw):false;if($bi){$iw=imagesx($bi);$ih=imagesy($bi);$scale=max(1080/$iw,500/$ih);$dw=(int)($iw*$scale);$dh=(int)($ih*$scale);imagecopyresampled($im,$bi,(int)((1080-$dw)/2),(int)((500-$dh)/2),0,0,$dw,$dh,$iw,$ih);imagedestroy($bi);$shade=imagecolorallocatealpha($im,10,8,26,55);imagefilledrectangle($im,0,0,1080,500,$shade);}}catch(Throwable){}}
imagefilledrectangle($im,0,500,1080,1600,$surface);
$font='';foreach(['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf','/usr/share/fonts/dejavu/DejaVuSans.ttf','/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf'] as$f){if(is_file($f)){$font=$f;break;}}
$bold='';foreach(['/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf','/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf','/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf'] as$f){if(is_file($f)){$bold=$f;break;}}if($bold==='')$bold=$font;
$plain=static function(string$v):string{return iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v)?:$v;};
$draw=static function($im,string$text,int$x,int$y,int$size,int$color,bool$isBold=false)use($font,$bold,$plain):void{if($font!==''&&function_exists('imagettftext')){@imagettftext($im,$size,0,$x,$y,$color,$isBold?$bold:$font,$text);return;}imagestring($im,5,$x,$y-$size,$plain($text),$color);};
$wrap=static function($im,string$text,int$x,int$y,int$size,int$color,int$maxWidth,int$lineHeight,bool$isBold=false)use($font,$bold,$draw):int{$words=preg_split('/\s+/u',trim($text))?:[];$line='';foreach($words as$word){$test=$line===''?$word:$line.' '.$word;$width=$font!==''&&function_exists('imagettfbbox')?abs((imagettfbbox($size,0,$isBold?$bold:$font,$test)[2]??0)-(imagettfbbox($size,0,$isBold?$bold:$font,$test)[0]??0)):strlen($test)*10;if($line!==''&&$width>$maxWidth){$draw($im,$line,$x,$y,$size,$color,$isBold);$y+=$lineHeight;$line=$word;}else{$line=$test;}}if($line!==''){$draw($im,$line,$x,$y,$size,$color,$isBold);$y+=$lineHeight;}return$y;};
$draw($im,'SEU INGRESSO',70,305,24,$white,true);$y=$wrap($im,(string)$t['event_name'],70,375,58,$white,940,68,true);$subtitle=trim((string)($t['public_subtitle']??''));if($subtitle!=='')$wrap($im,$subtitle,70,min(465,$y+8),27,$white,940,34,false);
$date=!empty($t['starts_at'])?date('d/m/Y · H:i',strtotime((string)$t['starts_at'])):'';$draw($im,$date,70,590,31,$dark,true);$place=implode(' · ',array_filter([trim((string)($t['venue']??'')),trim((string)($t['address']??''))]));$wrap($im,$place,70,640,25,$muted,940,33,false);
imagefilledrectangle($im,70,705,1010,835,imagecolorallocate($im,235,231,247));$type=trim((string)($t['ticket_type_name']??''))?:'Ingresso';$draw($im,mb_strtoupper($type.' · '.(string)$t['batch_name']),100,755,24,$p,true);$holder=trim((string)($t['customer_name']??''))?:'Ingresso';$draw($im,$holder,100,807,33,$dark,true);
$url=\app_absolute_url('ingresso.php?t='.rawurlencode($token));$qr=new \Endroid\QrCode\QrCode(data:$url,size:470,margin:12);$png=(new \Endroid\QrCode\Writer\PngWriter())->write($qr)->getString();$qri=@imagecreatefromstring($png);if($qri){imagefilledrectangle($im,290,870,790,1370,$white);imagecopyresampled($im,$qri,315,895,0,0,450,450,imagesx($qri),imagesy($qri));imagedestroy($qri);}
$code=(string)$t['code'];$box=$font!==''&&function_exists('imagettfbbox')?imagettfbbox(22,0,$font,$code):null;$cw=$box?abs(($box[2]??0)-($box[0]??0)):strlen($code)*10;$draw($im,$code,max(40,(int)((1080-$cw)/2)),1420,22,$muted,false);$status=(string)$t['status']==='checked_in'?'UTILIZADO':'VÁLIDO';$draw($im,$status,455,1470,24,(string)$t['status']==='checked_in'?$p:$green,true);$draw($im,'Apresente o QR na entrada · ingresso individual',280,1520,18,$muted,false);$draw($im,'Tecnologia EventMenu',430,1560,16,$muted,false);
header('Content-Type: image/png');header('Cache-Control: private, no-store, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');imagepng($im,null,6);imagedestroy($im);
