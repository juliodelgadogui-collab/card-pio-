<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Auth;
use EventMenu\Core\Database;
use RuntimeException;

final class TenantModuleService
{
    private static array $cache=[];

    private const ROUTE_MODULE=[
        'pos'=>'menu',
        'counter-orders'=>'menu',
        'fulfillment'=>'menu',
        'products'=>'menu',
        'cash'=>'menu',
        'delivery'=>'delivery',
        'my-deliveries'=>'delivery',
        'kitchen'=>'restaurant',
        'restaurant'=>'restaurant',
        'events'=>'events',
        'tickets'=>'events',
        'guests'=>'events',
        'promoters'=>'events',
        'customers'=>'loyalty',
        'coupons'=>'loyalty',
    ];

    public static function routeModule(string $route):?string{return self::ROUTE_MODULE[$route]??null;}

    public static function enabled(int $tenantId,string $module):bool
    {
        if($tenantId<1||$module==='')return true;
        $key=$tenantId.':'.$module;
        if(array_key_exists($key,self::$cache))return self::$cache[$key];
        try{
            $s=Database::connection()->prepare('SELECT enabled FROM tenant_modules WHERE tenant_id=? AND module_key=? LIMIT 1');
            $s->execute([$tenantId,$module]);$value=$s->fetchColumn();
            // Empresas criadas antes da migration mantêm todos os módulos ativos por padrão.
            return self::$cache[$key]=$value===false?true:(bool)$value;
        }catch(\Throwable){return self::$cache[$key]=true;}
    }

    public static function routeEnabled(string $route,?int $tenantId=null):bool
    {
        $module=self::routeModule($route);if($module===null)return true;
        $tenantId??=Auth::tenantId();if(!$tenantId)return true;
        return self::enabled($tenantId,$module);
    }

    public static function requireRoute(string $route,?int $tenantId=null):void
    {
        if(self::routeEnabled($route,$tenantId))return;
        $module=self::routeModule($route)??'módulo';
        throw new RuntimeException('Este recurso está desativado para a empresa atual (módulo: '.$module.').');
    }

    public static function requireModule(int $tenantId,string $module):void
    {
        if(!self::enabled($tenantId,$module))throw new RuntimeException('Este módulo está desativado para a empresa atual.');
    }

    public static function clearCache():void{self::$cache=[];}
}
