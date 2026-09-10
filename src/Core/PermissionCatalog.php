<?php

declare(strict_types=1);

namespace EventMenu\Core;

final class PermissionCatalog
{
    private const ROLES = [
        'admin'=>['dashboard','catalog.manage','inventory.manage','inventory.approve','production.manage','production.print','orders.manage','orders.view','orders.create','orders.kitchen','orders.delivery','orders.dispatch','orders.fulfill','orders.fulfillment_correct','orders.reopen','payments.manage','discounts.request','discounts.approve','cancellations.request','cancellations.approve','refunds.manage','cash.manage','gateways.manage','events.manage','events.bar','tickets.manage','users.manage','customers.manage','loyalty.redeem','loyalty.adjust','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','audit.view','settings.manage','nfc.manage','nfc.collect','hardware.manage','terminal.request','terminal.collect'],
        'manager'=>['dashboard','catalog.manage','inventory.manage','inventory.approve','production.manage','production.print','orders.manage','orders.view','orders.create','orders.kitchen','orders.delivery','orders.dispatch','orders.fulfill','orders.fulfillment_correct','orders.reopen','payments.manage','discounts.request','discounts.approve','cancellations.request','cancellations.approve','refunds.manage','cash.manage','events.manage','events.bar','tickets.manage','customers.manage','loyalty.redeem','loyalty.adjust','tables.manage','coupons.manage','guests.manage','promoters.manage','reports.view','delivery.assign','nfc.collect','terminal.request','terminal.collect'],
        'cashier'=>['dashboard','orders.manage','orders.view','orders.create','orders.dispatch','orders.fulfill','payments.manage','discounts.request','cancellations.request','cash.manage','customers.manage','loyalty.redeem','tables.manage','events.bar','nfc.collect','terminal.request','terminal.collect'],
        'attendant'=>['dashboard','orders.view','orders.create','orders.dispatch','orders.fulfill','cancellations.request','events.bar','tickets.manage','guests.manage','customers.manage','loyalty.redeem','tables.manage','delivery.assign','terminal.request'],
        'waiter'=>['dashboard','orders.create','orders.view','orders.dispatch','cancellations.request','tables.manage','terminal.request'],
        'kitchen'=>['dashboard','orders.kitchen','production.print'],
        'delivery'=>['dashboard','orders.delivery','cancellations.request','nfc.collect'],
        'promoter'=>['dashboard','events.promoter','reports.own','guests.manage'],
    ];

    private const LABELS = [
        'dashboard'=>'Dashboard',
        'catalog.manage'=>'Cardápio / produtos',
        'inventory.manage'=>'Estoque / compras / contagem',
        'inventory.approve'=>'Aprovar inventário e ajuste de contagem',
        'production.manage'=>'Configurar estações / produção',
        'production.print'=>'Imprimir / reimprimir produção',
        'orders.manage'=>'Alterar status de pedidos',
        'orders.view'=>'Visualizar pedidos',
        'orders.create'=>'Criar pedidos / PDV',
        'orders.kitchen'=>'Operação da cozinha',
        'orders.delivery'=>'Operação de entregas',
        'orders.dispatch'=>'Liberar pedidos prontos / servir mesa',
        'orders.fulfill'=>'Entregar itens por QR / retirada',
        'orders.fulfillment_correct'=>'Corrigir retirada registrada',
        'orders.reopen'=>'Reabrir pedido finalizado',
        'payments.manage'=>'Recebimentos operacionais',
        'discounts.request'=>'Solicitar desconto',
        'discounts.approve'=>'Aprovar / rejeitar desconto',
        'cancellations.request'=>'Solicitar cancelamento',
        'cancellations.approve'=>'Aprovar / rejeitar cancelamento',
        'refunds.manage'=>'Estornos',
        'cash.manage'=>'Caixa',
        'gateways.manage'=>'Configurar gateways',
        'events.manage'=>'Gerenciar eventos',
        'events.bar'=>'Bar / consumo no evento',
        'tickets.manage'=>'Ingressos / check-in',
        'users.manage'=>'Equipe / permissões',
        'customers.manage'=>'Clientes / consultar pontos',
        'loyalty.redeem'=>'Usar pontos em pedidos',
        'loyalty.adjust'=>'Ajustar saldo de pontos',
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
        'hardware.manage'=>'Configurar hardware do Desktop / Hub',
        'terminal.request'=>'Solicitar cobrança em PINPad vinculado',
        'terminal.collect'=>'Operar cobranças TEF / PINPad',
        'events.promoter'=>'Área do promotor',
    ];

    public static function roles(): array
    {
        return self::ROLES;
    }

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function rolePermissions(string $role): array
    {
        return self::ROLES[$role] ?? [];
    }

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
        }catch(\Throwable $e){
            error_log('EventMenu permission lookup failed for tenant '.$tenantId.' user '.$userId.': '.get_class($e));
            return [];
        }
        return array_keys($effective);
    }

    public static function modesForPermissions(array $permissions): array
    {
        $set=array_fill_keys($permissions,true);$modes=[];
        if(isset($set['orders.create'])||isset($set['orders.kitchen'])||isset($set['orders.dispatch'])||isset($set['orders.fulfill'])||isset($set['orders.fulfillment_correct'])||isset($set['production.manage'])||isset($set['production.print'])||isset($set['orders.reopen'])||isset($set['tables.manage'])||isset($set['cash.manage']))$modes[]='operation';
        if(isset($set['orders.delivery'])||isset($set['delivery.assign']))$modes[]='delivery';
        if(isset($set['tickets.manage'])||isset($set['guests.manage'])||isset($set['events.manage'])||isset($set['events.bar'])||isset($set['events.promoter']))$modes[]='events';
        if(isset($set['payments.manage'])||isset($set['cash.manage'])||isset($set['nfc.collect'])||isset($set['terminal.collect']))$modes[]='pay';
        return array_values(array_unique($modes));
    }

    public static function appPermissionMap(array $permissions): array
    {
        $set=array_fill_keys($permissions,true);
        $map=[
            'orders_create'=>'orders.create',
            'orders_manage'=>'orders.manage',
            'orders_view'=>'orders.view',
            'orders_kitchen'=>'orders.kitchen',
            'orders_delivery'=>'orders.delivery',
            'orders_dispatch'=>'orders.dispatch',
            'orders_reopen'=>'orders.reopen',
            'delivery_assign'=>'delivery.assign',
            'cash'=>'cash.manage',
            'payments'=>'payments.manage',
            'inventory'=>'inventory.manage',
            'discount_request'=>'discounts.request',
            'discount_approve'=>'discounts.approve',
            'cancellation_request'=>'cancellations.request',
            'cancellation_approve'=>'cancellations.approve',
            'loyalty_redeem'=>'loyalty.redeem',
            'loyalty_adjust'=>'loyalty.adjust',
            'nfc_collect'=>'nfc.collect',
            'hardware_manage'=>'hardware.manage',
            'terminal_request'=>'terminal.request',
            'terminal_collect'=>'terminal.collect',
            'tickets'=>'tickets.manage',
            'guests'=>'guests.manage',
            'tables'=>'tables.manage',
            'customers'=>'customers.manage',
            'events'=>'events.manage',
            'event_bar'=>'events.bar',
            'reports'=>'reports.view',
            'promoter'=>'events.promoter',
        ];
        $out=[];
        foreach($map as $key=>$permission)$out[$key]=isset($set[$permission]);
        return $out;
    }
}
