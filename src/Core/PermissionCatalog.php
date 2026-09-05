<?php

declare(strict_types=1);

namespace EventMenu\Core;

final class PermissionCatalog
{
    private const ROLES = [
        'admin'=>['dashboard','catalog.manage','inventory.manage','orders.manage','orders.view','orders.create','orders.kitchen','orders.delivery','orders.dispatch','payments.manage','refunds.manage','cash.manage','gateways.manage','events.manage','tickets.manage','users.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','audit.view','settings.manage','nfc.manage','nfc.collect'],
        'manager'=>['dashboard','catalog.manage','inventory.manage','orders.manage','orders.view','orders.create','orders.kitchen','orders.delivery','orders.dispatch','payments.manage','refunds.manage','cash.manage','events.manage','tickets.manage','customers.manage','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','nfc.collect'],
        'cashier'=>['dashboard','orders.manage','orders.view','orders.create','orders.dispatch','payments.manage','cash.manage','customers.manage','tables.manage','nfc.collect'],
        'attendant'=>['dashboard','orders.view','orders.create','orders.dispatch','tickets.manage','guests.manage','customers.manage','tables.manage','delivery.assign'],
        'waiter'=>['dashboard','orders.create','orders.view','orders.dispatch','tables.manage'],
        'kitchen'=>['dashboard','orders.kitchen'],
        'delivery'=>['dashboard','orders.delivery','nfc.collect'],
        'promoter'=>['dashboard','events.promoter','reports.own','guests.manage'],
    ];

    private const LABELS = [
        'dashboard'=>'Dashboard',
        'catalog.manage'=>'Cardápio / produtos',
        'inventory.manage'=>'Estoque',
        'orders.manage'=>'Alterar status de pedidos',
        'orders.view'=>'Visualizar pedidos',
        'orders.create'=>'Criar pedidos / PDV',
        'orders.kitchen'=>'Operação da cozinha',
        'orders.delivery'=>'Operação de entregas',
        'orders.dispatch'=>'Liberar pedidos prontos / servir mesa',
        'payments.manage'=>'Recebimentos operacionais',
        'refunds.manage'=>'Estornos',
        'cash.manage'=>'Caixa',
        'gateways.manage'=>'Configurar gateways',
        'events.manage'=>'Gerenciar eventos',
        'tickets.manage'=>'Ingressos / check-in',
        'users.manage'=>'Equipe / permissões',
        'customers.manage'=>'Clientes / pontos',
        'tables.manage'=>'Mesas / comandas',
        'coupons.manage'=>'Cupons',
        'guests.manage'=>'Lista de convidados',
        'promoters.manage'=>'Promotores',
        'reports.view'=>'Relatórios completos',
        'reports.own'=>'Relatórios próprios',
        'delivery.assign'=>'Atribuir entregador',
        'audit.view'=>'Auditoria',
        'settings.manage'=>'Configurações da empresa',
        'nfc.manage'=>'Configurar dispositivos NFC',
        'nfc.collect'=>'Cobrar por NFC',
        'events.promoter'=>'Área do promotor',
    ];

    public static function roles(): array { return self::ROLES; }
    public static function all(): array { return array_keys(self::LABELS); }
    public static function labels(): array { return self::LABELS; }
    public static function rolePermissions(string $role): array { return self::ROLES[$role] ?? []; }

    public static function effectiveForUser(int $tenantId,int $userId,string $role): array
    {
        $effective=array_fill_keys(self::rolePermissions($role),true);
        try{
            $s=Database::connection()->prepare('SELECT permission,allowed FROM user_permission_overrides WHERE tenant_id=? AND user_id=?');
            $s->execute([$tenantId,$userId]);
            foreach($s->fetchAll() as $row){
                $permission=(string)$row['permission'];
                if(!array_key_exists($permission,self::LABELS))continue;
                if((int)$row['allowed'])$effective[$permission]=true;else unset($effective[$permission]);
            }
        }catch(\Throwable){
            // Compatibilidade durante atualização anterior à migration 012.
        }
        return array_keys($effective);
    }

    public static function modesForPermissions(array $permissions): array
    {
        $set=array_fill_keys($permissions,true);$modes=[];
        if(isset($set['orders.create'])||isset($set['orders.kitchen'])||isset($set['orders.dispatch'])||isset($set['tables.manage'])||isset($set['cash.manage']))$modes[]='operation';
        if(isset($set['orders.delivery'])||isset($set['delivery.assign']))$modes[]='delivery';
        if(isset($set['tickets.manage'])||isset($set['guests.manage'])||isset($set['events.manage'])||isset($set['events.promoter']))$modes[]='events';
        if(isset($set['payments.manage'])||isset($set['cash.manage'])||isset($set['nfc.collect']))$modes[]='pay';
        return array_values(array_unique($modes));
    }

    public static function appPermissionMap(array $permissions): array
    {
        $set=array_fill_keys($permissions,true);
        $map=[
            'orders_create'=>'orders.create','orders_manage'=>'orders.manage','orders_view'=>'orders.view',
            'orders_kitchen'=>'orders.kitchen','orders_delivery'=>'orders.delivery','orders_dispatch'=>'orders.dispatch','delivery_assign'=>'delivery.assign',
            'cash'=>'cash.manage','payments'=>'payments.manage','nfc_collect'=>'nfc.collect','tickets'=>'tickets.manage',
            'guests'=>'guests.manage','tables'=>'tables.manage','customers'=>'customers.manage','events'=>'events.manage',
            'reports'=>'reports.view','promoter'=>'events.promoter',
        ];
        $out=[];foreach($map as $key=>$permission)$out[$key]=isset($set[$permission]);return $out;
    }
}
