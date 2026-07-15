<?php

function phase3Fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function phase3Assert(bool $condition, string $message): void
{
    if (!$condition) {
        phase3Fail($message);
    }
}

function stobeAutonomyBool(mixed $value): bool
{
    return is_bool($value) ? $value : in_array(strtolower(trim(strval($value))), ['1', 'true', 'yes', 'on'], true);
}

function stobeAutonomyGetVisitedLocation(int $id): array|false
{
    return $id === 77 ? [
        'id' => 77,
        'zone_name' => 'The Hub',
        'city_name' => 'The Hub',
        'x' => 10.5,
        'y' => 20.25,
        'z' => -2.0,
    ] : false;
}

require_once dirname(__DIR__) . '/lib/autonomy_planner_functions.php';

$defaultPolicy = stobeAutonomyPlannerPolicy([]);
phase3Assert($defaultPolicy['minimum_interval_seconds'] === 30, 'The idle planner floor should default to 30 seconds.');
phase3Assert(stobeAutonomyPlannerBackoffSeconds(1, 30) === 30, 'First planner failure should use the configured floor.');
phase3Assert(stobeAutonomyPlannerBackoffSeconds(2, 30) === 60, 'Second planner failure should double the backoff.');
phase3Assert(stobeAutonomyPlannerBackoffSeconds(5, 30) === 300, 'Planner failure backoff should cap at five minutes.');
phase3Assert(!stobeAutonomyPlannerConnectorRequiresApiKey([
    'connector_type' => 'player2json',
    'base_url' => 'http://127.0.0.1:4315/v1',
]), 'Player2 should not require a stored API key.');
phase3Assert(!stobeAutonomyPlannerConnectorRequiresApiKey([
    'connector_type' => 'openaijson',
    'base_url' => 'http://localhost:5001/v1',
]), 'Loopback OpenAI-compatible connectors should support keyless operation.');
phase3Assert(stobeAutonomyPlannerConnectorRequiresApiKey([
    'connector_type' => 'openrouterjson',
    'base_url' => 'https://openrouter.ai/api/v1',
]), 'Remote OpenRouter connectors should require credentials.');

$expectedCommands = [
    'ATTACK', 'CUT_HORNS', 'DRINK', 'DROP_ITEM', 'FACTION_RELATIONS',
    'FOLLOW', 'FORCE_DRINK', 'GIVE_CATS', 'GIVE_ITEM', 'IDLE',
    'JOIN_PARTY', 'KILL', 'KNOCKOUT', 'LEAVE', 'PICKUP_NPC',
    'REMOVE_LIMB', 'ROLEPLAY_ACTION', 'SET_BLOCK', 'SET_HOLD',
    'SET_JOBS', 'SET_MEDIC', 'SET_PASSIVE', 'SET_RANGED',
    'SET_RESOURCE', 'SET_SNEAK', 'SET_TAUNT', 'STOP_CARRYING',
    'STOP_FOLLOW', 'SUICIDE', 'TAKE_CATS', 'TAKE_ITEM', 'TALK',
    'TRAVEL_LOCATION', 'USE_DRUGS', 'USE_OBJECT',
];
$contracts = stobeAutonomyPlannerActionContracts();
$contractCommands = array_keys($contracts);
sort($contractCommands);
phase3Assert($contractCommands === $expectedCommands, 'Every registered Stobe action must have exactly one planner contract.');

$sampleFields = [
    'target' => 'Dust Bandit',
    'amount' => 5,
    'item' => 'Cactus Rum',
    'message' => 'Keep watch near the gate.',
    'enabled' => true,
    'limb' => 'LEFT_ARM',
    'object' => 'Camp Bed',
    'duration_ms' => 1500,
    'location_zone_id' => 77,
];
foreach ($contracts as $catalogCommand => $fields) {
    $catalogEntry = ['command' => $catalogCommand];
    if (in_array('target', $fields, true)) {
        $catalogEntry['valid_targets'] = ['Dust Bandit'];
    }
    if ($catalogCommand === 'TRAVEL_LOCATION') {
        $catalogEntry['visited_locations'] = [['location_zone_id' => 77]];
    }
    $catalogDecision = ['command' => $catalogCommand];
    foreach ($fields as $field) {
        $catalogDecision[$field] = $sampleFields[$field];
    }
    $normalizedCatalogDecision = stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Exercise the complete action catalog', 'status' => 'active'],
        'decision' => $catalogDecision,
        'reason' => 'Contract regression coverage.',
    ], [$catalogEntry]);
    phase3Assert(
        ($normalizedCatalogDecision['decision']['command'] ?? '') === $catalogCommand,
        "Catalog action {$catalogCommand} should normalize through the validated adapter."
    );
    phase3Assert(
        array_key_exists('legacy_argument', $normalizedCatalogDecision['decision']['arguments'] ?? []),
        "Catalog action {$catalogCommand} should produce an adapter argument."
    );
}

$allowlist = [
    ['command' => 'ATTACK', 'valid_targets' => ['Dust Bandit']],
    ['command' => 'TRAVEL_LOCATION', 'visited_locations' => [['location_zone_id' => 77]]],
    ['command' => 'IDLE'],
    ['command' => 'SET_JOBS'],
];

$attack = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Defend the squad', 'status' => 'active'],
    'decision' => ['command' => 'ATTACK', 'target' => 'Dust Bandit'],
    'reason' => 'A hostile is close.',
], $allowlist);
phase3Assert($attack['decision']['command'] === 'ATTACK', 'Allowed command should survive normalization.');
phase3Assert($attack['decision']['arguments']['legacy_argument'] === 'Dust Bandit', 'Target should become the adapter argument.');

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Defend', 'status' => 'active'],
        'decision' => ['command' => 'ATTACK', 'target' => 'Dust Bandit', 'script' => 'invented'],
    ], $allowlist);
    phase3Fail('An undeclared decision field must be rejected.');
} catch (InvalidArgumentException $exception) {
    phase3Assert($exception->getMessage() === 'planner_unexpected_decision_field', 'Extra fields should fail with a stable reason.');
}

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Defend', 'status' => 'active'],
        'reason' => 'Missing decision key.',
    ], $allowlist);
    phase3Fail('A missing decision key must be rejected.');
} catch (InvalidArgumentException $exception) {
    phase3Assert($exception->getMessage() === 'planner_decision_missing', 'Missing decisions should fail with a stable reason.');
}

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Wait', 'status' => 'active'],
        'decision' => ['command' => 'IDLE'],
    ], $allowlist);
    phase3Fail('A required duration must not be filled in silently.');
} catch (InvalidArgumentException $exception) {
    phase3Assert($exception->getMessage() === 'planner_missing_duration_ms', 'Missing required arguments should fail closed.');
}

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Wait', 'status' => 'invented'],
        'decision' => null,
    ], $allowlist);
    phase3Fail('An invalid goal status must be rejected.');
} catch (InvalidArgumentException $exception) {
    phase3Assert($exception->getMessage() === 'planner_goal_status_invalid', 'Goal status must match the strict enum.');
}

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Defend', 'status' => 'active'],
        'decision' => ['command' => 'ATTACK', 'target' => 'Unseen Enemy'],
    ], $allowlist);
    phase3Fail('An unobserved target must be rejected.');
} catch (InvalidArgumentException $exception) {
    phase3Assert($exception->getMessage() === 'planner_target_not_observed', 'Unobserved target should have a stable reason.');
}

$travel = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Return to town', 'status' => 'active'],
    'decision' => ['command' => 'TRAVEL_LOCATION', 'location_zone_id' => 77],
], $allowlist);
phase3Assert(floatval($travel['decision']['arguments']['x']) === 10.5, 'Travel must resolve coordinates from the visited table.');
phase3Assert(str_contains($travel['decision']['arguments']['legacy_argument'], 'The Hub'), 'Travel adapter argument should include its visited label.');

try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Go somewhere', 'status' => 'active'],
        'decision' => ['command' => 'TRAVEL_LOCATION', 'location_zone_id' => 999],
    ], $allowlist);
    phase3Fail('An unvisited location must be rejected.');
} catch (InvalidArgumentException $exception) {
    phase3Assert($exception->getMessage() === 'planner_location_not_visited', 'Unvisited location should have a stable reason.');
}

$wait = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Watch the gate', 'status' => 'active'],
    'decision' => null,
    'reason' => 'Nothing requires action.',
], $allowlist);
phase3Assert($wait['decision'] === null, 'The planner must be able to wait without inventing an action.');

$toggle = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Resume work', 'status' => 'active'],
    'decision' => ['command' => 'SET_JOBS', 'enabled' => true],
], $allowlist);
phase3Assert($toggle['decision']['arguments']['legacy_argument'] === 'ON', 'Boolean toggles should use the existing action adapter format.');

phase3Assert(stobeAutonomyPlannerDecodeResponse("```json\n{\"decision\":null}\n```") !== false, 'Fenced JSON should be tolerated.');
phase3Assert(stobeAutonomyPlannerDecodeResponse('not-json') === false, 'Malformed output must fail closed.');

echo "PASS: autonomy Phase 3 planner contract regression\n";
