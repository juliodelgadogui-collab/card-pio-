<?php

declare(strict_types=1);

namespace EventMenu\Services;

use PDO;
use RuntimeException;

final class MarketplaceCatalogService
{
    public function stores(PDO $pdo, array $filters = []): array
    {
        $q = mb_strtolower(trim((string)($filters['q'] ?? '')));
        $city = mb_strtolower(trim((string)($filters['city'] ?? '')));
        $state = mb_strtoupper(trim((string)($filters['state'] ?? '')));

        $sql = "SELECT t.id tenant_id,t.name,t.slug,t.settings,ms.city,ms.state,ou.id unit_id,ou.code unit_code,ou.name unit_name,ou.address unit_address
                FROM marketplace_tenant_settings ms
                JOIN tenants t ON t.id=ms.tenant_id
                JOIN operating_units ou ON ou.tenant_id=t.id AND ou.active=1
                WHERE ms.participates=1 AND ms.status='active' AND t.status='active'";
        $args = [];
        if ($city !== '') {$sql .= ' AND LOWER(ms.city)=?';$args[] = $city;}
        if ($state !== '') {$sql .= ' AND UPPER(ms.state)=?';$args[] = $state;}
        $sql .= ' ORDER BY t.name,ou.name LIMIT 300';

        $s = $pdo->prepare($sql);$s->execute($args);$rows = [];
        foreach ($s->fetchAll() as $row) {
            $settings = json_decode((string)($row['settings'] ?? '{}'), true) ?: [];
            $title = trim((string)($settings['menu_public_title'] ?? '')) ?: (string)$row['name'];
            $subtitle = trim((string)($settings['menu_subtitle'] ?? ''));
            $haystack = mb_strtolower($title.' '.$subtitle.' '.($row['unit_name'] ?? '').' '.($row['city'] ?? ''));
            if ($q !== '' && !str_contains($haystack, $q)) continue;
            $rows[] = ['tenant_id'=>(int)$row['tenant_id'],'unit_id'=>(int)$row['unit_id'],'slug'=>(string)$row['slug'],'name'=>$title,'description'=>$subtitle,'unit_name'=>(string)$row['unit_name'],'city'=>(string)($row['city']??''),'state'=>(string)($row['state']??''),'address'=>(string)($row['unit_address']??''),'logo_url'=>$this->publicUrl($settings['menu_logo_url']??''),'cover_url'=>$this->publicUrl($settings['menu_cover_url']??''),'delivery_fee_cents'=>max(0,(int)($settings['delivery_fee_cents']??0)),'minimum_order_cents'=>max(0,(int)($settings['min_delivery_order_cents']??0)),'accepting_orders'=>empty($settings['delivery_paused']),'delivery_eta_minutes'=>max(5,(int)($settings['delivery_eta_minutes']??45)),'delivery_radius_km'=>max(0,(float)($settings['delivery_radius_km']??0)),'pickup_enabled'=>!empty($settings['delivery_pickup_enabled']),'schedule_note'=>(string)($settings['delivery_schedule_note']??'')];
        }
        return $rows;
    }

    public function catalog(PDO $pdo, int $tenantId, int $unitId): array
    {
        $market=(new MarketplaceCommissionService())->assertTenantCanReceive($pdo,$tenantId,$unitId);
        $tenant=$pdo->prepare("SELECT id,name,slug,settings FROM tenants WHERE id=? AND status='active' LIMIT 1");$tenant->execute([$tenantId]);$row=$tenant->fetch();if(!$row)throw new RuntimeException('Loja indisponível.');
        $unit=$pdo->prepare('SELECT id,code,name,address FROM operating_units WHERE id=? AND tenant_id=? AND active=1 LIMIT 1');$unit->execute([$unitId,$tenantId]);$unitRow=$unit->fetch();if(!$unitRow)throw new RuntimeException('Unidade indisponível.');
        $settings=json_decode((string)($row['settings']??'{}'),true)?:[];
        $c=$pdo->prepare('SELECT id,name,sort_order FROM categories WHERE tenant_id=? AND active=1 ORDER BY sort_order,name');$c->execute([$tenantId]);$categories=array_map(static fn(array$r):array=>['id'=>(int)$r['id'],'name'=>(string)$r['name']],$c->fetchAll());
        $p=$pdo->prepare('SELECT p.id,p.category_id,p.name,p.description,p.price_cents,p.image_url,p.track_stock,COALESCE(ui.stock_qty,0) unit_stock_qty,EXISTS(SELECT 1 FROM product_recipes r WHERE r.tenant_id=p.tenant_id AND r.product_id=p.id) has_recipe FROM products p LEFT JOIN unit_inventory ui ON ui.tenant_id=p.tenant_id AND ui.unit_id=? AND ui.product_id=p.id WHERE p.tenant_id=? AND p.active=1 ORDER BY p.category_id,p.name');$p->execute([$unitId,$tenantId]);$products=$p->fetchAll();$modifiers=(new ConfiguredOrderService())->catalogModifiers($pdo,$tenantId,array_column($products,'id'));
        $safeProducts=[];
        foreach($products as$product){
            $available=!(int)$product['track_stock']||(int)$product['has_recipe']||(float)$product['unit_stock_qty']>0;$groups=[];
            foreach($modifiers[(int)$product['id']]??[]as$group){$opts=[];foreach((array)($group['options']??[])as$option)$opts[]=['id'=>(int)$option['id'],'name'=>(string)$option['name'],'price_delta_cents'=>(int)$option['price_delta_cents']];$min=max((int)($group['min_select']??0),(int)($group['required']??0)?1:0);$max=max(1,(int)($group['max_select']??1));$groups[]=['id'=>(int)$group['id'],'name'=>(string)$group['name'],'required'=>$min>0,'min_select'=>$min,'max_select'=>$max,'options'=>$opts];}
            $safeProducts[]=['id'=>(int)$product['id'],'category_id'=>$product['category_id']!==null?(int)$product['category_id']:null,'name'=>(string)$product['name'],'description'=>(string)($product['description']??''),'price_cents'=>(int)$product['price_cents'],'image_url'=>$this->publicUrl($product['image_url']??''),'available'=>$available,'modifier_groups'=>$groups];
        }
        return ['store'=>['tenant_id'=>$tenantId,'unit_id'=>$unitId,'slug'=>(string)$row['slug'],'name'=>trim((string)($settings['menu_public_title']??''))?:(string)$row['name'],'description'=>(string)($settings['menu_subtitle']??''),'unit_name'=>(string)$unitRow['name'],'city'=>(string)($market['city']??''),'state'=>(string)($market['state']??''),'address'=>(string)($unitRow['address']??''),'logo_url'=>$this->publicUrl($settings['menu_logo_url']??''),'cover_url'=>$this->publicUrl($settings['menu_cover_url']??''),'delivery_fee_cents'=>max(0,(int)($settings['delivery_fee_cents']??0)),'minimum_order_cents'=>max(0,(int)($settings['min_delivery_order_cents']??0)),'accepting_orders'=>empty($settings['delivery_paused']),'delivery_eta_minutes'=>max(5,(int)($settings['delivery_eta_minutes']??45)),'delivery_radius_km'=>max(0,(float)($settings['delivery_radius_km']??0)),'pickup_enabled'=>!empty($settings['delivery_pickup_enabled']),'schedule_note'=>(string)($settings['delivery_schedule_note']??'')],'categories'=>$categories,'products'=>$safeProducts];
    }

    private function publicUrl(mixed $value): string
    {
        $url=trim((string)$value);if($url==='')return '';
        if(str_starts_with($url,'https://'))return $url;
        $appUrl=trim((string)\env('APP_URL',''));$app=parse_url($appUrl);$appHost=strtolower((string)($app['host']??''));$appHttps=strtolower((string)($app['scheme']??''))==='https';
        if(str_starts_with($url,'http://')){$parts=parse_url($url);$host=strtolower((string)($parts['host']??''));if(!$appHttps||$appHost===''||$host!==$appHost)return '';return 'https://'.substr($url,strlen('http://'));}
        $absolute=\app_absolute_url(ltrim($url,'/'));if(str_starts_with($absolute,'https://'))return $absolute;
        if(str_starts_with($absolute,'http://')&&$appHttps){$parts=parse_url($absolute);$host=strtolower((string)($parts['host']??''));if($host===$appHost)return 'https://'.substr($absolute,strlen('http://'));}
        return '';
    }
}
