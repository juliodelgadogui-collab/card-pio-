<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class ReceiptPresentationService
{
    public function forOrder(array $receipt): array
    {
        $tenantId=Auth::tenantId();
        if(!$tenantId) throw new RuntimeException('Empresa não selecionada.');
        $pdo=Database::connection();
        $s=$pdo->prepare('SELECT name,settings FROM tenants WHERE id=? LIMIT 1');
        $s->execute([$tenantId]);
        $tenant=$s->fetch();
        if(!$tenant) throw new RuntimeException('Empresa não encontrada.');
        $settings=json_decode((string)($tenant['settings']??'{}'),true);
        if(!is_array($settings))$settings=[];
        $order=is_array($receipt['order']??null)?$receipt['order']:[];
        $channel=(string)($receipt['channel']??$order['channel']??'');
        $qrPayload=null;
        if($this->bool($settings,'receipt_show_order_qr',true)&&!empty($order['public_token'])){
            $fulfillment=new OrderFulfillmentService();
            if($fulfillment->supportsChannel($channel)){
                try{$qrPayload=$fulfillment->qrPayload((string)$order['public_token']);}catch(\Throwable){}
            }
        }
        $adEnabled=$this->bool($settings,'platform_receipt_ad_enabled',false);
        $ad=$adEnabled?[
            'enabled'=>true,
            'label'=>'PUBLICIDADE',
            'headline'=>$this->text($settings,'platform_receipt_ad_headline',120),
            'body'=>$this->text($settings,'platform_receipt_ad_body',300),
            'image_url'=>$this->url($settings,'platform_receipt_ad_image_url'),
            'target_url'=>$this->url($settings,'platform_receipt_ad_target_url'),
            'campaign'=>$this->text($settings,'platform_receipt_ad_campaign',80),
        ]:['enabled'=>false,'label'=>'PUBLICIDADE','headline'=>'','body'=>'','image_url'=>'','target_url'=>'','campaign'=>''];
        return [
            'schema_version'=>2,
            'paper_width'=>$this->paperWidth($settings),
            'title'=>$this->text($settings,'receipt_title',80)?:'COMPROVANTE NÃO FISCAL',
            'subtitle'=>$this->text($settings,'receipt_subtitle',120)?:'Documento operacional EventMenu',
            'footer'=>$this->text($settings,'receipt_footer',300)?:'Obrigado pela preferência!',
            'qr'=>[
                'enabled'=>$qrPayload!==null,
                'payload'=>$qrPayload,
                'label'=>$this->text($settings,'receipt_qr_label',120)?:'QR DO PEDIDO',
                'help'=>$this->text($settings,'receipt_qr_help',180)?:($channel==='pickup'||$channel==='counter'?'Apresente este QR para retirada e conferência.':'QR para conferência do pedido.'),
            ],
            'sections'=>[
                'business'=>$this->bool($settings,'receipt_section_business',true),
                'customer'=>$this->bool($settings,'receipt_section_customer',true),
                'items'=>$this->bool($settings,'receipt_section_items',true),
                'payments'=>$this->bool($settings,'receipt_section_payments',true),
                'operator'=>$this->bool($settings,'receipt_show_operator',true),
                'customer_phone'=>$this->bool($settings,'receipt_show_customer_phone',true),
                'event'=>$this->bool($settings,'receipt_section_event',true),
            ],
            'advertisement'=>$ad,
            'managed_by'=>'server',
        ];
    }

    private function paperWidth(array $settings):string{$paper=(string)($settings['receipt_paper_width']??'80');return in_array($paper,['58','80'],true)?$paper:'80';}
    private function bool(array $s,string $key,bool $default):bool{return array_key_exists($key,$s)?(bool)$s[$key]:$default;}
    private function text(array $s,string $key,int $max):string{return mb_substr(trim((string)($s[$key]??'')),0,$max);}
    private function url(array $s,string $key):string{$v=trim((string)($s[$key]??''));return $v!==''&&filter_var($v,FILTER_VALIDATE_URL)&&in_array(strtolower((string)parse_url($v,PHP_URL_SCHEME)),['http','https'],true)?$v:'';}
}
