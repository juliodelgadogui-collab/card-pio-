<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

function url_fail(string $message): never
{
    fwrite(STDERR, "URL CI FAIL: {$message}\n");
    exit(1);
}

function url_assert(bool $condition, string $message): void
{
    if (!$condition) url_fail($message);
}

url_assert(app_base_path() === '/1', 'APP_BASE_PATH não resolveu para /1.');
url_assert(app_url('') === '/1/', 'Raiz relativa incorreta.');
url_assert(app_url('assets/app.css') === '/1/assets/app.css', 'Asset URL incorreta.');
url_assert(app_url('?route=orders') === '/1/?route=orders', 'Rota do painel incorreta.');
url_assert(app_absolute_url('webhook.php?provider=pagbank&tenant=ci') === 'https://example.test/1/webhook.php?provider=pagbank&tenant=ci', 'URL absoluta de webhook incorreta.');
url_assert(app_absolute_url('pedido.php?t=pedido-ci') === 'https://example.test/1/pedido.php?t=pedido-ci', 'URL pública do pedido não respeita /1.');
url_assert(app_absolute_url('rastreio.php?t=rastreio-ci') === 'https://example.test/1/rastreio.php?t=rastreio-ci', 'URL pública de rastreamento não respeita /1.');

$html = '<a href="/pedido.php?t=abc">Pedido</a><img src="/assets/test.png"><form action="/checkout">';
$rewritten = app_rewrite_root_urls($html);
url_assert(str_contains($rewritten, 'href="/1/pedido.php?t=abc"'), 'href raiz não foi reescrito.');
url_assert(str_contains($rewritten, 'src="/1/assets/test.png"'), 'src raiz não foi reescrito.');
url_assert(str_contains($rewritten, 'action="/1/checkout"'), 'action raiz não foi reescrito.');

fwrite(STDOUT, "CI URL smoke OK (/1)\n");
