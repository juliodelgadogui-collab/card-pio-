<?php

declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use EventMenu\Core\PermissionCatalog;

function permission_fail(string $message): never { fwrite(STDERR,"PERMISSION CI FAIL: {$message}\n"); exit(1); }
function permission_assert(bool $ok,string $message): void { if(!$ok)permission_fail($message); }

$roles=PermissionCatalog::roles();
foreach(['admin','manager','cashier','attendant','waiter','delivery'] as $role){
    permission_assert(isset($roles[$role]),'Perfil ausente: '.$role);
}
foreach(['admin','manager'] as $role){
    permission_assert(in_array('loyalty.redeem',$roles[$role],true),$role.' precisa poder usar pontos.');
    permission_assert(in_array('loyalty.adjust',$roles[$role],true),$role.' precisa poder ajustar pontos.');
}
foreach(['cashier','attendant'] as $role){
    permission_assert(in_array('customers.manage',$roles[$role],true),$role.' precisa manter acesso a clientes.');
    permission_assert(in_array('loyalty.redeem',$roles[$role],true),$role.' precisa poder usar pontos no atendimento.');
    permission_assert(!in_array('loyalty.adjust',$roles[$role],true),$role.' não pode ajustar saldo manualmente.');
}
foreach(['waiter','delivery'] as $role){
    permission_assert(!in_array('loyalty.adjust',$roles[$role],true),$role.' não pode ajustar pontos.');
    permission_assert(!in_array('loyalty.redeem',$roles[$role],true),$role.' não pode resgatar pontos sem autorização de atendimento.');
}
$managerMap=PermissionCatalog::appPermissionMap($roles['manager']);
$cashierMap=PermissionCatalog::appPermissionMap($roles['cashier']);
permission_assert(($managerMap['loyalty_adjust']??false)===true,'Mapa Android do gerente perdeu loyalty_adjust.');
permission_assert(($managerMap['loyalty_redeem']??false)===true,'Mapa Android do gerente perdeu loyalty_redeem.');
permission_assert(($cashierMap['loyalty_adjust']??true)===false,'Mapa Android do caixa não pode ter loyalty_adjust.');
permission_assert(($cashierMap['loyalty_redeem']??false)===true,'Mapa Android do caixa precisa de loyalty_redeem.');

echo "CI permission smoke OK\n";
