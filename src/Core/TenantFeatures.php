<?php

declare(strict_types=1);

namespace EventMenu\Core;

final class TenantFeatures
{
    public const FULL='full';public const MENU='menu';public const EVENT='event';
    public static function type(?int$tenantId=null):string{$tenantId??=Auth::tenantId();if(!$tenantId)return self::FULL;static$cache=[];if(isset($cache[$tenantId]))return$cache[$tenantId];$s=Database::connection()->prepare('SELECT plan,settings FROM tenants WHERE id=? LIMIT 1');$s->execute([$tenantId]);$row=$s->fetch();if(!$row)return self::FULL;$settings=json_decode((string)($row['settings']??'{}'),true);$type=is_array($settings)?(string)($settings['business_type']??''):'';if(!in_array($type,[self::FULL,self::MENU,self::EVENT],true)){$plan=(string)($row['plan']??'');$type=in_array($plan,[self::MENU,self::EVENT],true)?$plan:self::FULL;}return$cache[$tenantId]=$type;}
    public static function label(?int$tenantId=null):string{return match(self::type($tenantId)){self::MENU=>'Cardápio / Restaurante',self::EVENT=>'Eventos',default=>'Completo'};}
    public static function menu(?int$tenantId=null):bool{return in_array(self::type($tenantId),[self::FULL,self::MENU],true);}public static function events(?int$tenantId=null):bool{return in_array(self::type($tenantId),[self::FULL,self::EVENT],true);}
    public static function routeEnabled(string$route,?int$tenantId=null):bool{$menu=['pos','cash','kitchen','kds-stream','production','product-config','delivery','pickup','products','categories','inventory','purchases','restaurant'];$event=['events','event-admin','tickets','guests','promoters'];if(in_array($route,$menu,true))return self::menu($tenantId);if(in_array($route,$event,true))return self::events($tenantId);return true;}
}
