<?php

function phase6Fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function phase6Assert(bool $condition, string $message): void
{
    if (!$condition) {
        phase6Fail($message);
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

final class Phase6Db
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
        ], ['BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT']);
    }
}

require_once dirname(__DIR__) . '/lib/autonomy_planner_functions.php';

$GLOBALS['db'] = new Phase6Db();
$session = ['policy' => ['max_purchase_cats' => 500, 'minimum_sale_cats' => 20]];
$npc = ['name' => 'Doran', 'inventory' => 'Iron Club', 'equipment' => ''];
$snapshot = [
    'runtime_serial' => 884422,
    'status' => ['can_take_orders' => true],
    'health' => [],
    'economy' => ['cats' => 600],
    'inventory_items' => [
        ['name' => 'Iron Club', 'count' => 1, 'buy_value_each' => 120, 'sell_value_each' => 80],
    ],
    'nearby_actors' => [
        [
            'name' => 'Shopkeeper',
            'runtime_serial' => 7711,
            'distance' => 5.0,
            'trader' => true,
            'dead' => false,
            'unconscious' => false,
            'trader_items' => [
                ['name' => 'Foodcube', 'count' => 1, 'buy_value_each' => 250, 'sell_value_each' => 100],
                ['name' => 'Stacked Bread', 'count' => 2, 'buy_value_each' => 80, 'sell_value_each' => 30],
            ],
        ],
    ],
    'nearby_resources' => [
        [
            'name' => 'Iron Resource',
            'runtime_serial' => 9911,
            'distance' => 20.0,
            'usable' => true,
        ],
    ],
];

$allowlist = stobeAutonomyPlannerBuildAllowlist($session, $snapshot, $npc);
$byCommand = [];
foreach ($allowlist as $entry) {
    $byCommand[$entry['command']] = $entry;
}
foreach (['BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT'] as $command) {
    phase6Assert(isset($byCommand[$command]), "{$command} should be available from an eligible live snapshot.");
}
phase6Assert(
    isset($byCommand['BUY_ITEM']['valid_items_by_target']['shopkeeper']['foodcube']),
    'BUY_ITEM must expose an exact observed single-item trader stack.'
);
phase6Assert(
    !isset($byCommand['BUY_ITEM']['valid_items_by_target']['shopkeeper']['stacked bread']),
    'BUY_ITEM must reject multi-item stacks until exact quantity trade semantics are available.'
);

$buy = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Buy food', 'status' => 'active'],
    'decision' => [
        'command' => 'BUY_ITEM',
        'target' => 'Shopkeeper',
        'item' => 'Foodcube',
        'amount' => 1,
        'max_total_price' => 300,
    ],
], $allowlist);
phase6Assert(intval($buy['decision']['arguments']['target_runtime_serial'] ?? 0) === 7711, 'BUY_ITEM must bind the trader serial.');
phase6Assert(intval($buy['decision']['arguments']['observed_price'] ?? 0) === 250, 'BUY_ITEM must retain the observed price.');

$work = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Mine iron', 'status' => 'active'],
    'decision' => ['command' => 'WORK_RESOURCE', 'resource' => 'Iron Resource'],
], $allowlist);
phase6Assert(intval($work['decision']['arguments']['resource_runtime_serial'] ?? 0) === 9911, 'WORK_RESOURCE must bind the exact resource serial.');

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Overspend', 'status' => 'active'],
        'decision' => [
            'command' => 'BUY_ITEM',
            'target' => 'Shopkeeper',
            'item' => 'Foodcube',
            'amount' => 1,
            'max_total_price' => 100,
        ],
    ], $allowlist);
    phase6Fail('BUY_ITEM must reject a price above the proposed cap.');
} catch (InvalidArgumentException $exception) {
    phase6Assert($exception->getMessage() === 'planner_buy_price_limit_exceeded', 'Price-cap rejection should be stable.');
}

echo "PASS: autonomy Phase 6 economy and work regression\n";
