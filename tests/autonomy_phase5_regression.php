<?php

function phase5Fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function phase5Assert(bool $condition, string $message): void
{
    if (!$condition) {
        phase5Fail($message);
    }
}

function stobeAutonomyBool(mixed $value): bool
{
    return is_bool($value) ? $value : in_array(strtolower(trim(strval($value))), ['1', 'true', 'yes', 'on'], true);
}

function stobeAutonomyListVisitedLocations(int $limit = 120): array
{
    return [];
}

function stobeAutonomyGetVisitedLocation(int $id): array|false
{
    return false;
}

final class Phase5Db
{
    public function fetchAll(string $sql, array $params = []): array
    {
        if (!str_contains($sql, 'combined_core_action')) {
            return [];
        }
        return array_map(static fn(string $command): array => [
            'command' => $command,
            'action_name' => $command,
            'description' => $command,
        ], [
            'ATTACK', 'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT',
            'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
        ]);
    }
}

require_once dirname(__DIR__) . '/lib/autonomy_planner_functions.php';

$GLOBALS['db'] = new Phase5Db();
$session = ['policy' => []];
$npc = [
    'name' => 'Doran',
    'inventory' => 'Nodachi, Hacksaw',
    'equipment' => '',
];
$snapshot = [
    'runtime_serial' => 884422,
    'status' => ['can_take_orders' => true],
    'health' => [],
    'nearby_actors' => [
        [
            'name' => 'Dust Bandit',
            'runtime_serial' => 9001,
            'distance' => 8.0,
            'dead' => false,
            'unconscious' => false,
            'hostile' => true,
        ],
        [
            'name' => 'Hungry Bandit',
            'runtime_serial' => 9002,
            'distance' => 6.0,
            'dead' => false,
            'unconscious' => true,
            'hostile' => false,
        ],
        [
            'name' => 'Duplicate',
            'runtime_serial' => 9003,
            'distance' => 5.0,
            'dead' => false,
            'unconscious' => true,
        ],
        [
            'name' => 'Duplicate',
            'runtime_serial' => 9004,
            'distance' => 7.0,
            'dead' => false,
            'unconscious' => true,
        ],
    ],
];

$allowlist = stobeAutonomyPlannerBuildAllowlist($session, $snapshot, $npc);
$byCommand = [];
foreach ($allowlist as $entry) {
    $byCommand[$entry['command']] = $entry;
}
phase5Assert(isset($byCommand['ATTACK']), 'ATTACK should be exposed while an observed living target exists.');
phase5Assert(isset($byCommand['TAKE_ITEM']), 'TAKE_ITEM should be exposed for an observed helpless target.');
phase5Assert(isset($byCommand['EQUIP_ITEM']), 'EQUIP_ITEM should be exposed when the actor carries inventory.');
phase5Assert(
    ($byCommand['KNOCKOUT']['valid_targets'] ?? []) === ['Hungry Bandit', 'Doran'],
    'KNOCKOUT must be limited to helpless targets plus self.'
);
phase5Assert(
    !in_array('Duplicate', $byCommand['TAKE_ITEM']['valid_targets'] ?? [], true),
    'Ambiguous duplicate names must be removed from targetable actions.'
);

$take = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Take food', 'status' => 'active'],
    'decision' => [
        'command' => 'TAKE_ITEM',
        'target' => 'Hungry Bandit',
        'item' => 'Bread',
        'amount' => 2,
    ],
], $allowlist);
phase5Assert(
    intval($take['decision']['arguments']['target_runtime_serial'] ?? 0) === 9002,
    'TAKE_ITEM must bind the exact observed target serial.'
);
phase5Assert(
    intval($take['decision']['arguments']['amount'] ?? 0) === 2,
    'TAKE_ITEM must retain a bounded explicit amount.'
);

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Loot everything', 'status' => 'active'],
        'decision' => [
            'command' => 'TAKE_ITEM',
            'target' => 'Hungry Bandit',
            'item' => 'all',
            'amount' => 20,
        ],
    ], $allowlist);
    phase5Fail('Broad TAKE_ITEM requests must be rejected.');
} catch (InvalidArgumentException $exception) {
    phase5Assert(
        $exception->getMessage() === 'planner_loot_item_must_be_specific',
        'Broad loot rejection should have a stable reason.'
    );
}

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Attack by name only', 'status' => 'active'],
        'decision' => ['command' => 'ATTACK', 'target' => 'Duplicate'],
    ], $allowlist);
    phase5Fail('Ambiguous duplicate targets must be rejected.');
} catch (InvalidArgumentException $exception) {
    phase5Assert(
        $exception->getMessage() === 'planner_target_not_observed',
        'Ambiguous target rejection should fail closed.'
    );
}

$blocked = $snapshot;
$blocked['status']['can_take_orders'] = false;
phase5Assert(
    stobeAutonomyPlannerBuildAllowlist($session, $blocked, $npc) === [],
    'All Phase 5 actions must be hidden while the actor cannot take orders.'
);

echo "PASS: autonomy Phase 5 equipment, loot, and combat regression\n";
