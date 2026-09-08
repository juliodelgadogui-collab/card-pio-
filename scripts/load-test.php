<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este utilitário só pode ser executado por linha de comando.\n");
    exit(2);
}

function usage(): void
{
    echo <<<'TXT'
EventMenu — teste de carga HTTP seguro (somente GET)

Uso:
  php scripts/load-test.php [opções]

Opções:
  --url=URL                  Alvo. Padrão: http://127.0.0.1:8080/
  --requests=N               Requisições medidas. Padrão: 100 (máx. 5000)
  --concurrency=N            Concorrência. Padrão: 10 (máx. 50)
  --warmup=N                 Requisições sequenciais de aquecimento. Padrão: 3 (máx. 100)
  --timeout=SEG              Timeout por requisição. Padrão: 10 (máx. 60)
  --expected=LISTA           HTTP aceitos, separados por vírgula. Padrão: 200
  --fail-error-rate=PCT      Sai com erro se a taxa de falha ultrapassar o percentual.
  --fail-p95-ms=MS           Sai com erro se o p95 ultrapassar o limite em ms.
  --allow-remote             Libera alvo que não seja localhost.
  --confirm-host=HOST        Confirma exatamente o host remoto informado em --url.
  --json                     Imprime resultado em JSON.
  --dry-run                  Valida opções e proteções sem fazer rede.
  --help                     Mostra esta ajuda.

Proteção para servidor remoto:
  É obrigatório usar --allow-remote E --confirm-host com o host exato.

Exemplo local:
  php scripts/load-test.php --url=http://127.0.0.1:8080/1/evento.php?evento=teste --requests=500 --concurrency=20

Exemplo staging remoto:
  php scripts/load-test.php --url=https://staging.exemplo.com/1/evento.php?evento=teste \
    --requests=500 --concurrency=20 --allow-remote --confirm-host=staging.exemplo.com

O utilitário é intencionalmente somente GET: ele não cria pedido, não confirma pagamento,
não altera estoque e não deve ser apontado para endpoints de mutação.
TXT;
    echo "\n";
}

function fail(string $message, int $code = 2): never
{
    fwrite(STDERR, "ERRO: {$message}\n");
    exit($code);
}

function intOption(array $options, string $name, int $default, int $min, int $max): int
{
    if (!array_key_exists($name, $options)) return $default;
    $raw = (string)$options[$name];
    if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) fail("--{$name} precisa ser um número inteiro.");
    $value = (int)$raw;
    if ($value < $min || $value > $max) fail("--{$name} precisa estar entre {$min} e {$max}.");
    return $value;
}

function floatOption(array $options, string $name): ?float
{
    if (!array_key_exists($name, $options)) return null;
    $raw = trim((string)$options[$name]);
    if ($raw === '' || !is_numeric($raw)) fail("--{$name} precisa ser numérico.");
    $value = (float)$raw;
    if ($value < 0) fail("--{$name} não pode ser negativo.");
    return $value;
}

function isLocalHost(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) return true;
    return str_ends_with($host, '.localhost');
}

/** @return list<int> */
function expectedStatuses(string $raw): array
{
    $values = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) fail('--expected deve conter apenas códigos HTTP separados por vírgula.');
        $status = (int)$part;
        if ($status < 100 || $status > 599) fail("Código HTTP inválido em --expected: {$status}.");
        $values[$status] = $status;
    }
    if (!$values) fail('--expected não pode ficar vazio.');
    sort($values);
    return array_values($values);
}

function percentile(array $sortedValues, float $p): float
{
    $count = count($sortedValues);
    if ($count === 0) return 0.0;
    if ($count === 1) return (float)$sortedValues[0];
    $index = ($count - 1) * $p;
    $low = (int)floor($index);
    $high = (int)ceil($index);
    if ($low === $high) return (float)$sortedValues[$low];
    $weight = $index - $low;
    return ((float)$sortedValues[$low] * (1.0 - $weight)) + ((float)$sortedValues[$high] * $weight);
}

function newRequest(string $url, int $timeout): CurlHandle
{
    $ch = curl_init($url);
    if ($ch === false) fail('Não foi possível inicializar cURL.');
    curl_setopt_array($ch, [
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'EventMenu-Load-Test/1.0',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/json;q=0.9,*/*;q=0.8', 'Cache-Control: no-cache', 'Pragma: no-cache'],
        CURLOPT_WRITEFUNCTION => static fn(CurlHandle $handle, string $data): int => strlen($data),
    ]);
    return $ch;
}

/** @return array{status:int,ms:float,error:?string} */
function runOne(string $url, int $timeout): array
{
    $ch = newRequest($url, $timeout);
    $ok = curl_exec($ch);
    $error = $ok === false ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000.0;
    curl_close($ch);
    return ['status' => $status, 'ms' => $ms, 'error' => $error !== '' ? $error : null];
}

$options = getopt('', [
    'url:', 'requests:', 'concurrency:', 'warmup:', 'timeout:', 'expected:',
    'fail-error-rate:', 'fail-p95-ms:', 'allow-remote', 'confirm-host:', 'json', 'dry-run', 'help',
]);
if ($options === false) fail('Não foi possível interpretar as opções.');
if (array_key_exists('help', $options)) {
    usage();
    exit(0);
}

$url = trim((string)($options['url'] ?? 'http://127.0.0.1:8080/'));
if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) fail('Informe uma URL HTTP/HTTPS válida em --url.');
$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
$host = strtolower((string)parse_url($url, PHP_URL_HOST));
if (!in_array($scheme, ['http', 'https'], true) || $host === '') fail('Somente URLs HTTP/HTTPS são permitidas.');
if (str_contains($url, '#')) fail('Remova fragmentos (#...) da URL de teste.');

$requests = intOption($options, 'requests', 100, 1, 5000);
$concurrency = intOption($options, 'concurrency', 10, 1, 50);
$warmup = intOption($options, 'warmup', 3, 0, 100);
$timeout = intOption($options, 'timeout', 10, 1, 60);
if ($concurrency > $requests) $concurrency = $requests;
$expected = expectedStatuses((string)($options['expected'] ?? '200'));
$failErrorRate = floatOption($options, 'fail-error-rate');
$failP95Ms = floatOption($options, 'fail-p95-ms');
if ($failErrorRate !== null && $failErrorRate > 100) fail('--fail-error-rate deve estar entre 0 e 100.');

$local = isLocalHost($host);
if (!$local) {
    if (!array_key_exists('allow-remote', $options)) {
        fail("Alvo remoto bloqueado. Para testar {$host}, use --allow-remote e --confirm-host={$host} somente em ambiente autorizado.");
    }
    $confirmed = strtolower(trim((string)($options['confirm-host'] ?? '')));
    if ($confirmed === '' || !hash_equals($host, $confirmed)) {
        fail("Confirmação de host inválida. Use exatamente --confirm-host={$host}.");
    }
}

$config = [
    'url' => $url,
    'host' => $host,
    'local' => $local,
    'requests' => $requests,
    'concurrency' => $concurrency,
    'warmup' => $warmup,
    'timeout_seconds' => $timeout,
    'expected_statuses' => $expected,
    'method' => 'GET',
];

if (array_key_exists('dry-run', $options)) {
    if (array_key_exists('json', $options)) {
        echo json_encode(['ok' => true, 'dry_run' => true, 'config' => $config], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        echo "Configuração válida. Nenhuma requisição foi enviada.\n";
        echo 'Alvo: ' . $url . "\n";
        echo 'Tipo: ' . ($local ? 'local' : 'remoto confirmado') . "\n";
        echo "GET medidos: {$requests} | concorrência: {$concurrency} | aquecimento: {$warmup}\n";
    }
    exit(0);
}

if (!extension_loaded('curl')) fail('A extensão PHP cURL é obrigatória.');

for ($i = 0; $i < $warmup; $i++) {
    $probe = runOne($url, $timeout);
    if ($probe['error'] !== null) fail('Falha no aquecimento: ' . $probe['error'], 3);
    if (!in_array($probe['status'], $expected, true)) {
        fail('Aquecimento retornou HTTP ' . $probe['status'] . '; esperado: ' . implode(',', $expected) . '. Corrija o alvo antes de gerar carga.', 3);
    }
}

$multi = curl_multi_init();
$handles = [];
$durations = [];
$statusCounts = [];
$transportErrors = [];
$success = 0;
$failed = 0;
$next = 0;
$completed = 0;
$startedAt = microtime(true);

$addRequest = static function () use (&$handles, &$next, $requests, $url, $timeout, $multi): void {
    if ($next >= $requests) return;
    $ch = newRequest($url, $timeout);
    $id = spl_object_id($ch);
    $handles[$id] = $ch;
    curl_multi_add_handle($multi, $ch);
    $next++;
};

while ($next < $requests && count($handles) < $concurrency) $addRequest();

while ($completed < $requests) {
    do {
        $multiStatus = curl_multi_exec($multi, $running);
    } while ($multiStatus === CURLM_CALL_MULTI_PERFORM);

    if ($multiStatus !== CURLM_OK) {
        curl_multi_close($multi);
        fail('Erro interno do cURL multi: ' . curl_multi_strerror($multiStatus), 3);
    }

    while (($info = curl_multi_info_read($multi)) !== false) {
        /** @var CurlHandle $ch */
        $ch = $info['handle'];
        $id = spl_object_id($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $ms = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000.0;
        $error = $info['result'] === CURLE_OK ? null : curl_strerror((int)$info['result']);

        $durations[] = $ms;
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        if ($error !== null) $transportErrors[$error] = ($transportErrors[$error] ?? 0) + 1;

        if ($error === null && in_array($status, $expected, true)) $success++;
        else $failed++;

        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        unset($handles[$id]);
        $completed++;

        while ($next < $requests && count($handles) < $concurrency) $addRequest();
    }

    if ($completed < $requests && $running > 0) {
        $selected = curl_multi_select($multi, 1.0);
        if ($selected === -1) usleep(10000);
    }
}

curl_multi_close($multi);
$elapsed = max(0.000001, microtime(true) - $startedAt);
sort($durations, SORT_NUMERIC);
ksort($statusCounts, SORT_NUMERIC);
ksort($transportErrors, SORT_STRING);

$errorRate = ($failed / $requests) * 100.0;
$result = [
    'ok' => $failed === 0,
    'config' => $config,
    'summary' => [
        'total' => $requests,
        'success' => $success,
        'failed' => $failed,
        'error_rate_pct' => round($errorRate, 3),
        'elapsed_seconds' => round($elapsed, 3),
        'requests_per_second' => round($requests / $elapsed, 2),
    ],
    'latency_ms' => [
        'min' => round((float)($durations[0] ?? 0), 2),
        'avg' => round($durations ? array_sum($durations) / count($durations) : 0, 2),
        'p50' => round(percentile($durations, 0.50), 2),
        'p95' => round(percentile($durations, 0.95), 2),
        'p99' => round(percentile($durations, 0.99), 2),
        'max' => round((float)($durations[count($durations) - 1] ?? 0), 2),
    ],
    'http_statuses' => $statusCounts,
    'transport_errors' => $transportErrors,
];

$thresholdFailures = [];
if ($failErrorRate !== null && $errorRate > $failErrorRate) {
    $thresholdFailures[] = sprintf('taxa de falha %.3f%% > limite %.3f%%', $errorRate, $failErrorRate);
}
if ($failP95Ms !== null && (float)$result['latency_ms']['p95'] > $failP95Ms) {
    $thresholdFailures[] = sprintf('p95 %.2f ms > limite %.2f ms', (float)$result['latency_ms']['p95'], $failP95Ms);
}
$result['thresholds_ok'] = $thresholdFailures === [];
$result['threshold_failures'] = $thresholdFailures;

if (array_key_exists('json', $options)) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo "\nEventMenu — resultado do teste de carga\n";
    echo "Alvo: {$url}\n";
    echo "GET: {$requests} | concorrência: {$concurrency} | tempo: " . number_format($elapsed, 3, ',', '.') . " s\n";
    echo 'Sucesso: ' . $success . ' | falhas: ' . $failed . ' | taxa de falha: ' . number_format($errorRate, 3, ',', '.') . "%\n";
    echo 'Throughput: ' . number_format((float)$result['summary']['requests_per_second'], 2, ',', '.') . " req/s\n";
    echo 'Latência ms — min ' . number_format((float)$result['latency_ms']['min'], 2, ',', '.')
        . ' | média ' . number_format((float)$result['latency_ms']['avg'], 2, ',', '.')
        . ' | p50 ' . number_format((float)$result['latency_ms']['p50'], 2, ',', '.')
        . ' | p95 ' . number_format((float)$result['latency_ms']['p95'], 2, ',', '.')
        . ' | p99 ' . number_format((float)$result['latency_ms']['p99'], 2, ',', '.')
        . ' | max ' . number_format((float)$result['latency_ms']['max'], 2, ',', '.') . "\n";
    echo 'HTTP: ' . json_encode($statusCounts, JSON_UNESCAPED_SLASHES) . "\n";
    if ($transportErrors) echo 'Erros de transporte: ' . json_encode($transportErrors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    if ($thresholdFailures) echo 'LIMITES REPROVADOS: ' . implode('; ', $thresholdFailures) . "\n";
}

exit($thresholdFailures === [] ? 0 : 4);
