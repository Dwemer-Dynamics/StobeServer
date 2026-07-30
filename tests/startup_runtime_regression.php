<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

$db = $GLOBALS['db'];

function startupFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function startupAssert(bool $condition, string $message): void
{
    if (!$condition) {
        startupFail($message);
    }
}

function startupConfSnapshot(string $id): array
{
    $db = $GLOBALS['db'];
    $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1 LIMIT 1', [$id]);
    if (!$row) {
        return ['exists' => false, 'value' => ''];
    }
    return ['exists' => true, 'value' => strval($row['value'] ?? '')];
}

function startupRestoreConf(string $id, array $snapshot): void
{
    $db = $GLOBALS['db'];
    if (!($snapshot['exists'] ?? false)) {
        $db->exec('DELETE FROM conf_opts WHERE id = $1', [$id]);
        return;
    }
    $db->exec(
        'INSERT INTO conf_opts (id, value, updated_at)
         VALUES ($1, $2, NOW())
         ON CONFLICT (id) DO UPDATE
         SET value = EXCLUDED.value,
             updated_at = NOW()',
        [$id, strval($snapshot['value'] ?? '')]
    );
}

$trackedKeys = [
    'BACKGROUND_PROCESSOR_LAST_TICK_TS',
    'BACKGROUND_PROCESSOR_LAST_TICK_GAMETS',
    'BACKGROUND_PROCESSOR_LAST_SUCCESS_TS',
    'BACKGROUND_PROCESSOR_LAST_ERROR_TS',
];

$confBackup = [];
foreach ($trackedKeys as $trackedKey) {
    $confBackup[$trackedKey] = startupConfSnapshot($trackedKey);
}

try {
    startupAssert($db instanceof sql, 'bootstrap should initialize sql database handle');
    startupAssert(function_exists('stobeDatabaseEncoding'), 'bootstrap should register database encoding helper');
    startupAssert(stobeDatabaseEncoding() === 'UTF8', 'test database should use UTF8');
    startupAssert(stobeDatabaseEncodingIsSupported(), 'UTF8 database should be supported');
    $unsupportedEncodingDb = new class {
        public function fetchOne(string $query): array
        {
            return ['server_encoding' => 'SQL_ASCII'];
        }
    };
    startupAssert(!stobeDatabaseEncodingIsSupported($unsupportedEncodingDb), 'SQL_ASCII database should be rejected');
    startupAssert(
        str_contains(stobeDatabaseEncodingError($unsupportedEncodingDb), 'run_db_updates.php'),
        'unsupported encoding error should identify the automatic update command'
    );
    startupAssert(function_exists('convert_gamets2skyrim_long_date2'), 'bootstrap should register compatibility date helper');
    startupAssert(function_exists('gamets2str_format_gregorian_date'), 'bootstrap should register gregorian date formatter');
    startupAssert(gamets2str_format_gregorian_date(86400, 'Y-m-d') !== '', 'gregorian date formatter should return a string');

    setConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_TS', '1000');
    $healthyProcessor = stobeBackgroundProcessorStatus(0.1, true, 1050);
    startupAssert(boolval($healthyProcessor['healthy'] ?? false), 'fresh manager tick and socket should be healthy');
    startupAssert(strval($healthyProcessor['state'] ?? '') === 'running', 'healthy processor should report running');

    $stalledProcessor = stobeBackgroundProcessorStatus(0.1, true, 1401);
    startupAssert(!boolval($stalledProcessor['healthy'] ?? true), 'stale manager tick should be unhealthy');
    startupAssert(strval($stalledProcessor['state'] ?? '') === 'stalled', 'stale manager tick should report stalled');

    $offlineProcessor = stobeBackgroundProcessorStatus(0.1, false, 1050);
    startupAssert(!boolval($offlineProcessor['healthy'] ?? true), 'missing socket should be unhealthy');
    startupAssert(strval($offlineProcessor['state'] ?? '') === 'offline', 'missing socket should report offline');

    $healthOutput = [];
    $healthExitCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../health.php') . ' 2>&1', $healthOutput, $healthExitCode);
    startupAssert($healthExitCode === 0, 'health endpoint script should exit cleanly');
    $healthPayload = json_decode(implode("\n", $healthOutput), true);
    startupAssert(is_array($healthPayload), 'health endpoint should emit valid JSON');
    startupAssert(boolval($healthPayload['ok'] ?? false) === true, 'health endpoint should report ok=true');
    startupAssert(strval($healthPayload['status'] ?? '') === 'ok', 'health endpoint should report status=ok');
    startupAssert(strval($healthPayload['service'] ?? '') === 'StobeServer', 'health endpoint should report StobeServer service name');
    startupAssert(boolval($healthPayload['database'] ?? false), 'health endpoint should report database connectivity');
    startupAssert(strval($healthPayload['database_encoding'] ?? '') === 'UTF8', 'health endpoint should report UTF8');
    startupAssert(boolval($healthPayload['database_encoding_supported'] ?? false), 'health endpoint should accept UTF8');
    startupAssert(boolval($healthPayload['database_schema_ready'] ?? false), 'health endpoint should report schema readiness');
    startupAssert(
        boolval($healthPayload['database_upgrade_required'] ?? true) === false,
        'health endpoint should not request an upgrade for the ready test database'
    );
    startupAssert(
        str_contains(strval($healthPayload['database_repair_command'] ?? ''), 'run_db_updates.php'),
        'health endpoint should expose the automatic repair command'
    );
    startupAssert(intval($healthPayload['timestamp'] ?? 0) > 0, 'health endpoint should emit a positive timestamp');

    foreach ($trackedKeys as $trackedKey) {
        $db->exec('DELETE FROM conf_opts WHERE id = $1', [$trackedKey]);
    }
    $db->exec('DELETE FROM eventlog');

    $managerOutput = [];
    $managerExitCode = 0;
    exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/../service/manager.php') . ' 2>&1', $managerOutput, $managerExitCode);
    startupAssert($managerExitCode === 0, 'background manager should exit cleanly when no gamets exist');
    startupAssert(intval(getConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_TS', '0')) > 0, 'background manager should record last tick timestamp');
    startupAssert(intval(getConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_GAMETS', '-1')) === 0, 'background manager should record zero gamets when eventlog is empty');
    startupAssert(getConfOpt('BACKGROUND_PROCESSOR_LAST_SUCCESS_TS', '') === '', 'background manager should not record success timestamp without gamets');
    startupAssert(getConfOpt('BACKGROUND_PROCESSOR_LAST_ERROR_TS', '') === '', 'background manager should not record error timestamp on clean no-op startup');

    echo 'All startup/runtime regression tests passed.' . PHP_EOL;
} finally {
    foreach ($confBackup as $key => $snapshot) {
        startupRestoreConf($key, $snapshot);
    }
}

exit(0);
