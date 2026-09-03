<?php

declare(strict_types=1);

$root=dirname(__DIR__);
$checks=[
    'Dashboard legado'=>['app/routes/dashboard.php',['Faturamento (Mês)','Pedidos (Mês)','Ingressos Vendidos','Ticket Médio','Últimos 7 dias','Vendas por Tipo','Pedidos Recentes']],
    'Cardápio público legado'=>['public/menu_legacy.php',['legacy-hero','Destaques','legacy-cart-drawer','Finalizar Pedido','checkout-stepper']],
    'Editor do cardápio'=>['app/routes/menu_editor.php',['Editar Cardápio Digital','Capa / banner','Logo','Prévia real','Categorias']],
    'Pedidos legado'=>['app/routes/orders_legacy.php',['Todos','Novos','Em Preparo','Saiu para Entrega','Concluídos']],
    'PDV legado'=>['app/routes/pos_legacy.php',['Nova venda','Pedido atual','Total estimado','Retirada parcial']],
    'Eventos legado'=>['app/routes/events_legacy.php',['Todos','Ativos','Encerrados','Rascunhos','Novo Evento','Adicionar Ingresso']],
    'Evento público legado'=>['public/evento.php',['Ingressos','Dados','Pagamento','Confirmação','Comprar Agora']],
    'Checkout legado'=>['public/pedido.php',['Forma de Pagamento','Confirmação','checkout-stepper']],
    'Ingressos e check-in legado'=>['app/routes/tickets.php',['Check-in rápido','Abrir câmera','Válidos','Utilizados','Reservados','Transferir ingresso']],
    'Super ADM legado'=>['app/routes/superadmin.php',['Clientes do EventMenu','Novo Cliente','Nome / Razão Social','WhatsApp','CNPJ / CPF','Tipo de Negócio','Eventos','Cardápio Digital','Acessar','Módulos do cliente']],
    'Configurações legado'=>['app/routes/settings_hub.php',['Central de Configurações','Empresa e operação','Cardápio Digital','Equipe e permissões','Pagamentos, Gateways e NFC','Central de Ajuda']],
    'Acessos por função'=>['app/admin_helpers.php',['Tela da Cozinha','Minhas Entregas','Nova Venda','Mesas e Comandas','Cardápio Digital','Financeiro']],
    'Ajuda legado'=>['app/routes/help.php',['Central de Ajuda EventMenu','Cardápio Digital','PDV / Balcão','Eventos e ingressos']],
];

$failed=[];
foreach($checks as$name=>[$file,$needles]){
    $path=$root.'/'.$file;
    if(!is_file($path)){$failed[]="$name: arquivo ausente ($file)";continue;}
    $content=(string)file_get_contents($path);
    foreach($needles as$needle){if(!str_contains($content,$needle))$failed[]="$name: marcador ausente [$needle]";}
}
$routes=(string)file_get_contents($root.'/public/index.php');
foreach(['orders_legacy.php','kitchen_legacy.php','my_deliveries_legacy.php','restaurant_legacy.php','cash_legacy.php','customers_legacy.php','reports_legacy.php','payments_legacy.php','help.php','tickets.php','superadmin.php','settings_hub.php','menu_hub.php','finance_hub.php'] as$file){if(!str_contains($routes,$file))$failed[]="Roteador: $file não está ativo";}
if($failed){fwrite(STDERR,"Legacy UI parity FAILED:\n - ".implode("\n - ",$failed)."\n");exit(1);}
echo "Legacy UI parity OK\n";
