<?php

declare(strict_types=1);

namespace EventMenu\Support;

final class UiVocabulary
{
    public static function orderStatus(string $status):string
    {
        return match(strtolower(trim($status))){
            'draft'=>'Rascunho',
            'pending'=>'Aguardando confirmação',
            'confirmed'=>'Confirmado',
            'preparing'=>'Em preparo',
            'ready'=>'Pronto',
            'served'=>'Servido',
            'out_for_delivery'=>'Saiu para entrega',
            'arrived'=>'Entregador chegou',
            'completed'=>'Concluído',
            'delivered'=>'Entregue',
            'cancelled'=>'Cancelado',
            default=>'Em andamento',
        };
    }

    public static function paymentStatus(string $status):string
    {
        return match(strtolower(trim($status))){
            'unpaid'=>'Aguardando pagamento',
            'created'=>'Aguardando pagamento',
            'pending','processing'=>'Aguardando confirmação',
            'authorized'=>'Autorizado',
            'paid','approved','confirmed'=>'Pago',
            'duplicate_paid'=>'Pagamento duplicado',
            'failed'=>'Não foi possível concluir',
            'cancelled'=>'Cancelado',
            'refunded'=>'Estornado',
            'partially_refunded'=>'Estorno parcial',
            default=>'Em andamento',
        };
    }

    public static function paymentMethod(string $provider):string
    {
        return match(strtolower(trim($provider))){
            'manual'=>'Dinheiro',
            'pagbank','mercadopago'=>'PIX',
            'stripe'=>'Cartão',
            default=>'Pagamento eletrônico',
        };
    }

    public static function refundStatus(string $status):string
    {
        return match(strtolower(trim($status))){
            'created','pending','provider_pending'=>'Aguardando confirmação',
            'completed','refunded'=>'Estorno concluído',
            'failed'=>'Precisa de atenção',
            'cancelled'=>'Cancelado',
            default=>'Em andamento',
        };
    }

    public static function channel(string $channel):string
    {
        return match(strtolower(trim($channel))){
            'counter'=>'Balcão',
            'pickup'=>'Retirada',
            'delivery'=>'Entrega',
            'table'=>'Mesa / comanda',
            'event_bar'=>'Evento / bar',
            default=>'Atendimento',
        };
    }

    public static function deviceStatus(string $status):string
    {
        return match(strtolower(trim($status))){
            'online','active','ready'=>'Disponível',
            'offline','disconnected'=>'Indisponível',
            'pending'=>'Aguardando autorização',
            'revoked'=>'Acesso revogado',
            'busy'=>'Em uso',
            'error','failed'=>'Precisa de atenção',
            default=>'Verificando',
        };
    }

    public static function deliveryStep(string $step):string
    {
        return match(strtolower(trim($step))){
            'assigned'=>'Entrega atribuída',
            'picked_up'=>'Pedido retirado',
            'route_started'=>'Rota iniciada',
            'arrived'=>'Chegada confirmada',
            'completed'=>'Entrega concluída',
            default=>'Em andamento',
        };
    }
}
