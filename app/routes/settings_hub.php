<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

if(!Auth::can('settings.manage')&&!Auth::can('units.manage')&&!Auth::can('users.manage')){http_response_code(403);exit('Acesso negado.');}em_require_tenant();
$cards=[
 ['settings','Configurações da empresa','Delivery, retirada, horários, agendamento, fidelidade e dados gerais.','settings.manage'],
 ['units','Unidades / filiais','Cadastre lojas e selecione a unidade operacional.','units.manage'],
 ['users','Equipe e permissões','Usuários, funções, unidades e permissões individuais.','users.manage'],
 ['gateways','Gateways e NFC','Credenciais, webhooks e dispositivos de pagamento.','gateways.manage'],
 ['legal','Termos e privacidade','Publique e versione documentos legais.','legal.manage'],
 ['help','Ajuda','Central de ajuda para Cardápio, PDV, pedidos, delivery, restaurante, eventos e financeiro.','settings.manage'],
 ['backup','Backup e restauração','Backup criptografado completo do sistema.','backup.manage'],
 ['diagnostic','Diagnóstico','Verifique servidor, banco e requisitos de produção.','backup.manage'],
];
em_header('Configurações','settings-hub');?>
<section class="grid" style="grid-template-columns:repeat(auto-fit,minmax(250px,1fr))"><?php foreach($cards as[$route,$title,$desc,$permission]):if($route==='backup'||$route==='diagnostic'){if(Auth::role()!=='super_admin')continue;}elseif($route!=='help'&&!Auth::can($permission))continue;?><a class="card" style="text-decoration:none;color:inherit" href="<?= Security::e(em_url('/?route='.$route)) ?>"><h2><?= Security::e($title) ?></h2><p class="muted"><?= Security::e($desc) ?></p><span class="button secondary">Abrir</span></a><?php endforeach;?></section><?php em_footer();
