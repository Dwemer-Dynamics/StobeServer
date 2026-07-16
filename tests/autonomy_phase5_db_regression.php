<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function phase5DbFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function phase5DbAssert(bool $condition, string $message): void
{
    if (!$condition) {
        phase5DbFail($message);
    }
}

$db = $GLOBALS['db'];
$equip = $db->fetchOne(
    "SELECT command, is_activated FROM core_action WHERE UPPER(command) = 'EQUIP_ITEM'"
);
phase5DbAssert(
    is_array($equip) && stobeAutonomyBool($equip['is_activated'] ?? false),
    'EQUIP_ITEM must be installed and enabled by default.'
);

$constraint = $db->fetchOne(
    "SELECT pg_get_constraintdef(oid) AS definition
     FROM pg_constraint
     WHERE conname = 'autonomy_pilot_step_command_check'
       AND conrelid = 'autonomy_pilot_step'::regclass"
);
$definition = strtoupper(strval($constraint['definition'] ?? ''));
foreach ([
    'ATTACK', 'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT',
    'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
] as $command) {
    phase5DbAssert(
        str_contains($definition, $command),
        "Pilot command constraint must include {$command}."
    );
}

$db->exec('BEGIN');
try {
    foreach ([
        'ATTACK', 'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT',
        'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
    ] as $command) {
        $ok = $db->exec(
            "INSERT INTO autonomy_pilot_step (
                session_id, control_revision, command, arguments, status
             ) VALUES (1, 0, $1, '{}'::jsonb, 'CANCELLED')",
            [$command]
        );
        phase5DbAssert($ok !== false, "Database must accept the {$command} pilot command.");
    }
    $db->exec('ROLLBACK');
} catch (Throwable $exception) {
    $db->exec('ROLLBACK');
    throw $exception;
}

echo "PASS: autonomy Phase 5 database regression\n";
