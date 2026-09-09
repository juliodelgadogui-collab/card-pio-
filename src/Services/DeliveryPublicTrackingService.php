<?php

declare(strict_types=1);

namespace EventMenu\Services;

final class DeliveryPublicTrackingService
{
    /** @return array<string,mixed>|null */
    public function snapshot(string $token): ?array
    {
        $tracking = (new DeliveryTrackingService())->latestPublic($token);
        if ($tracking === null) return null;

        if (($tracking['order_status'] ?? '') === 'completed') {
            $tracking['latitude'] = null;
            $tracking['longitude'] = null;
            $tracking['accuracy_m'] = null;
            $tracking['speed_kmh'] = null;
            $tracking['bearing_deg'] = null;
            $tracking['history'] = [];
            $tracking['route_distance_m'] = 0;
            $tracking['signal'] = [
                'status' => 'completed',
                'label' => 'Entregue',
                'age_seconds' => 0,
            ];
        }

        return $tracking;
    }
}
