<?php

declare(strict_types=1);

namespace EventMenu\Services;

use EventMenu\Core\Database;
use RuntimeException;

final class PublicUnitService
{
    private function sessionKey(int $tenantId): string{return 'eventmenu_public_unit_'.$tenantId;}

    public function units(int $tenantId): array
    {
        if($tenantId<1)return[];$s=Database::connection()->prepare('SELECT id,code,name,address FROM operating_units WHERE tenant_id=? AND active=1 ORDER BY name');$s->execute([$tenantId]);return$s->fetchAll();
    }

    public function current(int $tenantId): ?array
    {
        $units=$this->units($tenantId);if(!$units)return null;$selected=(int)($_SESSION[$this->sessionKey($tenantId)]??0);if($selected>0){foreach($units as$unit)if((int)$unit['id']===$selected)return$unit;unset($_SESSION[$this->sessionKey($tenantId)]);}if(count($units)===1){$this->remember($tenantId,(int)$units[0]['id']);return$units[0];}return null;
    }

    public function requireCurrent(int $tenantId): array
    {
        $unit=$this->current($tenantId);if($unit)return$unit;$units=$this->units($tenantId);if(!$units)throw new RuntimeException('Esta empresa ainda não possui unidade disponível para pedidos.');throw new RuntimeException('Escolha a unidade antes de concluir o pedido.');
    }

    public function selectById(int $tenantId,int $unitId): array
    {
        foreach($this->units($tenantId)as$unit)if((int)$unit['id']===$unitId){$this->remember($tenantId,$unitId);return$unit;}throw new RuntimeException('Unidade não disponível.');
    }

    public function selectByCode(int $tenantId,string $code): array
    {
        $code=trim($code);foreach($this->units($tenantId)as$unit)if(hash_equals((string)$unit['code'],$code)){$this->remember($tenantId,(int)$unit['id']);return$unit;}throw new RuntimeException('Unidade não disponível.');
    }

    public function menuUrl(string $tenantSlug,array $unit): string{return \app_url('menu.php?empresa='.rawurlencode($tenantSlug).'&unidade='.rawurlencode((string)$unit['code']));}

    private function remember(int $tenantId,int $unitId): void{$_SESSION[$this->sessionKey($tenantId)]=$unitId;}
}
