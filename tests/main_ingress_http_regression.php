<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function mainIngressFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function mainIngressAssert(bool $condition, string $message): void
{
    if (!$condition) {
        mainIngressFail($message);
    }
}

function mainIngressAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        mainIngressFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function mainIngressAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        mainIngressFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

function mainIngressNormalizeWireLine(string $line): string
{
    $trimmed = trim($line);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^([^|]+\|[^|]+\|[^|]*)(?:\|.*)?$/', $trimmed, $match) === 1) {
        return strval($match[1]);
    }

    return $trimmed;
}

function mainIngressForceNoApiKey(): void
{
    $db = $GLOBALS['db'];
    $db->exec("UPDATE core_api_badge SET api_key = ''");
    try {
        $db->exec("UPDATE core_llm_connector SET api_key = ''");
    } catch (Throwable $exception) {
        // Older schemas may not persist connector-level API keys.
    }
}

function mainIngressFindFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($socket)) {
        mainIngressFail('failed to allocate local HTTP port: ' . $errstr);
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $parts = explode(':', strval($name));
    return intval(end($parts));
}

function mainIngressHttpRequest(int $port, string $path): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);
    $body = file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
    $status = 0;
    $headers = $http_response_header ?? [];
    foreach ($headers as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', strval($header), $match) === 1) {
            $status = intval($match[1]);
            break;
        }
    }
    return [
        'status' => $status,
        'body' => is_string($body) ? $body : '',
        'headers' => $headers,
    ];
}

function mainIngressStartServer(int $port)
{
    $root = dirname(__DIR__);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        mainIngressFail('failed to start PHP built-in server');
    }
    foreach ($pipes as $pipe) {
        stream_set_blocking($pipe, false);
    }

    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline) {
        $response = mainIngressHttpRequest($port, '/health.php');
        if (intval($response['status']) === 200) {
            return [$process, $pipes];
        }
        usleep(100000);
    }

    proc_terminate($process);
    mainIngressFail('PHP built-in server did not become ready');
}

function mainIngressStopServer($process, array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

function mainIngressRequestPath(string $eventType, int $timestamp, int $gamets, string $data, array $query = []): string
{
    $encodedEvent = base64_encode($eventType . '|' . $timestamp . '|' . $gamets . '|' . $data);
    $params = array_merge(['data' => $encodedEvent], $query);
    return '/main.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function mainIngressEventRows(): array
{
    return $GLOBALS['db']->fetchAll('SELECT type, data, people FROM eventlog ORDER BY rowid ASC');
}

mainIngressForceNoApiKey();
$GLOBALS['db']->exec('DELETE FROM eventlog');

$port = mainIngressFindFreePort();
[$serverProcess, $serverPipes] = mainIngressStartServer($port);

try {
    $speaker = 'UT_MAIN_INGRESS_SPEAKER';
    $target = 'UT_MAIN_INGRESS_TARGET';
    $people = json_encode([$speaker . '|4001', $target . '|4002'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($people)) {
        mainIngressFail('failed to encode people payload');
    }

    $response = mainIngressHttpRequest(
        $port,
        mainIngressRequestPath(
            'inputtext',
            time(),
            999001,
            $speaker . ': Hello through main',
            [
                'profile' => $target,
                'people' => $people,
                'mode' => 'talk',
                'tts_enabled' => '0',
            ]
        )
    );
    mainIngressAssertSameInt(200, intval($response['status']), 'main.php inputtext request should return HTTP 200');
    mainIngressAssert(
        mainIngressNormalizeWireLine(strval($response['body'])) === $target . '|ScriptQueue|No OpenRouter API key configured yet.',
        'main.php inputtext request should stream deterministic no-API-key fallback'
    );

    $rows = mainIngressEventRows();
    mainIngressAssertSameInt(3, count($rows), 'main.php inputtext request should store input, mirrored chat, and fallback reply');
    mainIngressAssertSame('inputtext', strval($rows[0]['type'] ?? ''), 'first main ingress row should be inputtext');
    mainIngressAssertSame($speaker . ': Hello through main (talking to: ' . $target . ')', strval($rows[0]['data'] ?? ''), 'main ingress input row should include target from profile');
    mainIngressAssertSame('chat', strval($rows[1]['type'] ?? ''), 'second main ingress row should be mirrored chat');
    mainIngressAssertSame($speaker . ': Hello through main (talking to: ' . $target . ')', strval($rows[1]['data'] ?? ''), 'main ingress mirror row should preserve target payload');
    mainIngressAssertSame('chat', strval($rows[2]['type'] ?? ''), 'third main ingress row should be fallback reply');
    mainIngressAssertSame($target . ': No OpenRouter API key configured yet. (talking to: ' . $speaker . ')', strval($rows[2]['data'] ?? ''), 'main ingress fallback reply should target the speaker');
    mainIngressAssert(strpos(strval($rows[0]['people'] ?? ''), $speaker . '|hand_4001') !== false, 'main ingress should normalize speaker storage id in people cache');
    mainIngressAssert(strpos(strval($rows[0]['people'] ?? ''), $target . '|hand_4002') !== false, 'main ingress should normalize target storage id in people cache');

    $speakerData = getNpcData($speaker);
    $targetData = getNpcData($target);
    mainIngressAssert(is_array($speakerData), 'main.php should JIT-create speaker profile from people payload');
    mainIngressAssert(is_array($targetData), 'main.php should JIT-create target profile from profile/people payload');

    $GLOBALS['db']->exec('DELETE FROM eventlog');
    $injectionResponse = mainIngressHttpRequest(
        $port,
        mainIngressRequestPath(
            'injection',
            time(),
            999002,
            $speaker . ': The town gate collapses',
            [
                'profile' => $target,
                'mode' => 'inject',
                'people' => json_encode([$speaker, $target], JSON_UNESCAPED_SLASHES),
            ]
        )
    );
    mainIngressAssertSameInt(200, intval($injectionResponse['status']), 'main.php injection request should return HTTP 200');
    mainIngressAssertSame('ok', trim(strval($injectionResponse['body'])), 'plain injection should acknowledge storage');
    $injectionRows = mainIngressEventRows();
    mainIngressAssertSameInt(1, count($injectionRows), 'plain injection through main.php should store exactly one row');
    mainIngressAssertSame('injection', strval($injectionRows[0]['type'] ?? ''), 'main ingress injection row should preserve event type');
    mainIngressAssertSame(
        $speaker . ': (The town gate collapses) (talking to: ' . $target . ')',
        strval($injectionRows[0]['data'] ?? ''),
        'main ingress injection should store a parenthetical world event'
    );

    $GLOBALS['db']->exec('DELETE FROM eventlog');
    $unknownResponse = mainIngressHttpRequest(
        $port,
        mainIngressRequestPath('ut_unhandled_event', time(), 999003, 'payload stored only')
    );
    mainIngressAssertSameInt(200, intval($unknownResponse['status']), 'unhandled main.php event should return HTTP 200');
    mainIngressAssertSame('ok', trim(strval($unknownResponse['body'])), 'unhandled main.php event should return ok');
    $unknownRows = mainIngressEventRows();
    mainIngressAssertSameInt(1, count($unknownRows), 'unhandled main.php event should store exactly one row');
    mainIngressAssertSame('ut_unhandled_event', strval($unknownRows[0]['type'] ?? ''), 'unhandled event row should preserve event type');
    mainIngressAssertSame('payload stored only', strval($unknownRows[0]['data'] ?? ''), 'unhandled event row should preserve event data');
} finally {
    mainIngressStopServer($serverProcess, $serverPipes);
}

echo "All main ingress HTTP regression tests passed.\n";
