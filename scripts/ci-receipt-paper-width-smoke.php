<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Services/ReceiptPresentationService.php';

use EventMenu\Services\ReceiptPresentationService;

function fail_receipt(string $message): never { fwrite(STDERR,"RECEIPT WIDTH CI FAIL: {$message}\n"); exit(1); }

$service=new ReceiptPresentationService();
$method=new ReflectionMethod($service,'paperWidth');
$cases=[
    [[], '80', 'configuração ausente'],
    [['receipt_paper_width'=>'58'], '58', '58 mm'],
    [['receipt_paper_width'=>'80'], '80', '80 mm'],
    [['receipt_paper_width'=>'72'], '80', 'valor inválido'],
    [['receipt_paper_width'=>''], '80', 'valor vazio'],
];
foreach($cases as[$settings,$expected,$label]){
    $actual=$method->invoke($service,$settings);
    if($actual!==$expected)fail_receipt("{$label}: esperado {$expected}, recebido {$actual}");
}

echo "receipt paper width smoke: OK\n";
