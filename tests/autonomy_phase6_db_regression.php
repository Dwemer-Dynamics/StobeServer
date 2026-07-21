<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function phase6DbFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function phase6DbAssert(bool $condition, string $message): void
{
    if (!$condition) {
        phase6DbFail($message);
    }
}

$db = $GLOBALS['db'];
foreach (['BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT'] as $command) {
    $action = $db->fetchOne(
        'SELECT command, is_activated FROM core_action WHERE UPPER(command) = $1',
        [$command]
    );
    phase6DbAssert(
        is_array($action) && stobeAutonomyBool($action['is_activated'] ?? false),
        "{$command} must be installed and enabled by default."
    );
}

$constraint = $db->fetchOne(
    "SELECT pg_get_constraintdef(oid) AS definition
     FROM pg_constraint
     WHERE conname = 'autonomy_pilot_step_command_check'
       AND conrelid = 'autonomy_pilot_step'::regclass"
);
$definition = strtoupper(strval($constraint['definition'] ?? ''));
foreach (['BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT'] as $command) {
    phase6DbAssert(str_contains($definition, $command), "Pilot constraint must include {$command}.");
}

$economyTable = $db->fetchOne(
    "SELECT to_regclass('public.autonomy_economy_snapshot') AS table_name"
);
phase6DbAssert(
    trim(strval($economyTable['table_name'] ?? '')) !== '',
    'Economy snapshot storage must be installed.'
);

$db->exec('BEGIN');
try {
    foreach (['BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT'] as $command) {
        $ok = $db->exec(
            "INSERT INTO autonomy_pilot_step (
                session_id, control_revision, command, arguments, status
             ) VALUES (1, 0, $1, '{}'::jsonb, 'CANCELLED')",
            [$command]
        );
        phase6DbAssert($ok !== false, "Database must accept {$command} pilot steps.");
    }
    $db->exec('ROLLBACK');
} catch (Throwable $exception) {
    $db->exec('ROLLBACK');
    throw $exception;
}

echo "PASS: autonomy Phase 6 database regression\n";
