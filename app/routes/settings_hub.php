<?php

declare(strict_types=1);

use EventMenu\Core\Auth;
use EventMenu\Core\Security;

if(!Auth::can('settings.manage')&&!Auth::can('units.manage')&&!Auth::can('users.manage')){http_response_code(403);exit('Acesso negado.');}
em_require_tenant();
$sections=[
 'Geral'=>[
  ['settings','Empresa e operação','Dados gerais, horários, agendamento, delivery, retirada e fidelidade.','settings.manage','⚙'],
  ['menu-editor','Cardápio Digital','Capa, logo, cores, categorias e visual do cardápio público.','catalog.manage','☰'],
  ['units','Unidades / filiais','Cadastre lojas, endereços e selecione a unidade operacional.','units.manage','⌂'],
 ],
 'Equipe e atendimento'=>[
  ['users','Equipe e permissões','Usuários, funções, unidades e permissões individuais.','users.manage','♙'],
  ['notifications','Notificações','Avisos internos de pedidos, pagamentos, estoque e operação.','notifications.view','◉'],
  ['help','Central de Ajuda','Orientações para Cardápio, PDV, Delivery, Restaurante, Eventos e Financeiro.','settings.manage','?'],
 ],
 'Pagamentos e segurança'=>[
  ['gateways','Pagamentos, Gateways e NFC','Credenciais, webhooks, dispositivos e meios de pagamento.','gateways.manage','▣'],
  ['legal','Termos e privacidade','Publique e versione os documentos legais da empresa.','legal.manage','§'],
  ['audit','Auditoria','Histórico de alterações e ações importantes dos usuários.','audit.view','◎'],
 ],
];
if(Auth::role()==='super_admin')$sections['Sistema']=[['backup','Backup e restauração','Backup criptografado completo do sistema.','backup.manage','⇩'],['diagnostic','Diagnóstico','Servidor, banco, extensões e requisitos técnicos.','backup.manage','✓']];

em_header('Configurações','settings-hub');
?>
<style>.settings-intro{display:flex;justify-content:space-between;gap:15px;align-items:end;margin-bottom:18px}.settings-intro h2{margin:0;font-size:23px}.settings-group{margin-bottom:24px}.settings-group>h3{font-size:11px;text-transform:uppercase;letter-spacing:.11em;color:#9693a5;margin:0 0 9px}.settings-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.settings-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:17px;display:flex;gap:13px;align-items:flex-start;transition:.16s ease}.settings-card:hover{border-color:#d8d0ff;box-shadow:0 8px 25px rgba(40,30,80,.06);transform:translateY(-1px)}.settings-icon{width:42px;height:42px;border-radius:12px;background:var(--accent-soft);color:var(--accent);display:grid;place-items:center;font-size:18px;font-weight:900;flex:0 0 42px}.settings-card h2{font-size:15px;margin:1px 0 5px}.settings-card p{font-size:11px;line-height:1.45;color:var(--muted);margin:0}.settings-card .open{display:inline-block;margin-top:10px;color:var(--accent);font-weight:850;font-size:10px}@media(max-width:1000px){.settings-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.settings-grid{grid-template-columns:1fr}.settings-intro{align-items:flex-start;flex-direction:column}}</style>
<section class="settings-intro"><div><h2>Central de Configurações</h2><p class="muted">Tudo que controla a empresa fica organizado aqui, como no EventMenu anterior.</p></div><?php if(Auth::can('catalog.manage')):?><a class="button secondary" target="_blank" href="<?= Security::e(em_url('/?route=menu-hub')) ?>">Cardápio Digital</a><?php endif;?></section>
<?php foreach($sections as$title=>$cards):?><section class="settings-group"><h3><?= Security::e($title) ?></h3><div class="settings-grid"><?php foreach($cards as[$route,$name,$desc,$permission,$icon]):if(!Auth::can($permission)&&!($route==='help'&&Auth::can('settings.manage')))continue;?><a class="settings-card" href="<?= Security::e(em_url('/?route='.$route)) ?>"><span class="settings-icon"><?= Security::e($icon) ?></span><span><h2><?= Security::e($name) ?></h2><p><?= Security::e($desc) ?></p><span class="open">Abrir →</span></span></a><?php endforeach;?></div></section><?php endforeach;?>
<?php em_footer();
