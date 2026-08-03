<?php

function phase4Assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

final class Phase4Db
{
    public function exec(string $sql, array $params = []): bool
    {
        return true;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (!str_contains($sql, 'combined_core_action')) {
            return [];
        }
        return array_map(static fn(string $command): array => [
            'command' => $command,
            'action_name' => $command,
            'description' => $command,
        ], ['MOVE_NEARBY', 'FLEE', 'FIRST_AID', 'REST']);
    }
}

require_once dirname(__DIR__) . '/lib/autonomy_helper_functions.php';
require_once dirname(__DIR__) . '/lib/autonomy_planner_functions.php';

$GLOBALS['db'] = new Phase4Db();
$session = ['policy' => []];
$npc = ['name' => 'Doran', 'inventory' => 'Standard First Aid Kit', 'equipment' => ''];
$base = [
    'runtime_serial' => 884422,
    'position' => ['x' => 10.0, 'y' => 2.0, 'z' => 20.0],
    'status' => [
        'can_take_orders' => true,
        'fully_rested' => true,
        'probably_dying' => false,
        'rest_bed_available' => true,
    ],
    'health' => [
        'overall' => 1.0,
        'bleed_rate' => 0.0,
        'first_aid_need' => 0.0,
        'robotic_aid_need' => 0.0,
    ],
    'nearby_actors' => [],
];

$healthy = stobeAutonomyPlannerBuildAllowlist($session, $base, $npc);
phase4Assert(array_column($healthy, 'command') === ['MOVE_NEARBY'], 'Healthy state should expose only local movement from the Phase 4 set.');
$move = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Check the ridge', 'status' => 'active'],
    'decision' => ['command' => 'MOVE_NEARBY', 'direction' => 'E', 'distance' => 25],
], $healthy);
phase4Assert(abs(floatval($move['decision']['arguments']['x']) - 35.0) < 0.001, 'MOVE_NEARBY must derive X from the observed origin.');
phase4Assert(abs(floatval($move['decision']['arguments']['z']) - 20.0) < 0.001, 'MOVE_NEARBY must preserve the perpendicular axis.');

$threatened = $base;
$threatened['status']['probably_dying'] = true;
$threatened['health']['overall'] = 0.3;
$threatened['nearby_actors'][] = [
    'name' => 'Dust Bandit', 'runtime_serial' => 9001, 'distance' => 10.0,
    'x' => 0.0, 'y' => 2.0, 'z' => 20.0, 'hostile' => true,
    'dead' => false, 'player_character' => false,
];
$threatAllowlist = stobeAutonomyPlannerBuildAllowlist($session, $threatened, $npc);
$fleeEntry = current(array_filter($threatAllowlist, static fn(array $entry): bool => $entry['command'] === 'FLEE'));
phase4Assert(is_array($fleeEntry) && ($fleeEntry['required_now'] ?? false) === true, 'A dying NPC near a hostile must require FLEE.');
phase4Assert(floatval($fleeEntry['x']) > 10.0, 'FLEE must derive a destination away from the hostile.');
try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Freeze under pressure', 'status' => 'active'],
        'decision' => null,
    ], $threatAllowlist);
    phase4Assert(false, 'Waiting must be rejected during a required survival response.');
} catch (InvalidArgumentException $exception) {
    phase4Assert($exception->getMessage() === 'planner_survival_action_required', 'Required survival must also reject decision:null.');
}
try {
    stobeAutonomyPlannerNormalizeProposal([
        'goal' => ['summary' => 'Ignore danger', 'status' => 'active'],
        'decision' => ['command' => 'MOVE_NEARBY', 'direction' => 'N', 'distance' => 20],
    ], $threatAllowlist);
    phase4Assert(false, 'Non-survival actions must be rejected during a required survival response.');
} catch (InvalidArgumentException $exception) {
    phase4Assert($exception->getMessage() === 'planner_survival_action_required', 'Survival rejection should have a stable reason.');
}

$injured = $base;
$injured['status']['fully_rested'] = false;
$injured['health']['bleed_rate'] = 0.5;
$injured['health']['first_aid_need'] = 8.0;
$aidAllowlist = stobeAutonomyPlannerBuildAllowlist($session, $injured, $npc);
$aidEntry = current(array_filter($aidAllowlist, static fn(array $entry): bool => $entry['command'] === 'FIRST_AID'));
phase4Assert(($aidEntry['required_now'] ?? false) === true, 'Active bleeding without a threat must require FIRST_AID.');
$aid = stobeAutonomyPlannerNormalizeProposal([
    'goal' => ['summary' => 'Stop the bleeding', 'status' => 'active'],
    'decision' => ['command' => 'FIRST_AID', 'target' => 'Doran'],
], $aidAllowlist);
phase4Assert(intval($aid['decision']['arguments']['target_runtime_serial']) === 884422, 'FIRST_AID must bind the observed runtime serial.');
phase4Assert(!stobeAutonomyPlannerShouldSuppressCompletedDuplicate('FIRST_AID'), 'A renewed FIRST_AID need must not be permanently deduplicated.');
phase4Assert(!stobeAutonomyPlannerShouldSuppressCompletedDuplicate('REST'), 'A renewed REST need must not be permanently deduplicated.');
phase4Assert(stobeAutonomyPlannerShouldSuppressCompletedDuplicate('TALK'), 'Ordinary identical completed actions should retain duplicate suppression.');

$recovering = $base;
$recovering['status']['fully_rested'] = false;
$restAllowlist = stobeAutonomyPlannerBuildAllowlist($session, $recovering, $npc);
phase4Assert(in_array('REST', array_column($restAllowlist, 'command'), true), 'REST should be available only for a treated, non-threatened NPC needing recovery.');
$noBed = $recovering;
$noBed['status']['rest_bed_available'] = false;
phase4Assert(!in_array('REST', array_column(stobeAutonomyPlannerBuildAllowlist($session, $noBed, $npc), 'command'), true), 'REST must be hidden when no usable bed is observed.');

$payload = [
    'snapshot_local_ts' => time(), 'snapshot_sequence' => 1,
    'context_hash' => 'phase4-test', 'runtime_serial' => 884422,
    'position' => ['x' => 1, 'y' => 2, 'z' => 3],
    'status' => ['can_take_orders' => true, 'fully_rested' => false],
    'health' => ['overall' => 0.7, 'first_aid_need' => 2.5],
    'nearby_actors' => [[
        'name' => 'Ally', 'runtime_serial' => 22, 'distance' => 4,
        'player_character' => true, 'first_aid_need' => 3.0,
    ]],
    'nearby_work' => [[
        'name' => 'Wheat Farm', 'kind' => 'farm',
        'runtime_serial' => 33, 'distance' => 12,
        'read_only' => false, 'usable' => true, 'needs_work' => true,
        'input_empty' => false, 'output_full' => false,
        'power_on' => true, 'task' => 141,
    ]],
];
$snapshot = stobeAutonomyTickSnapshot($payload);
phase4Assert(is_array($snapshot) && intval($snapshot['runtime_serial']) === 884422, 'Snapshot normalization must retain the selected runtime serial.');
phase4Assert(floatval($snapshot['nearby_actors'][0]['first_aid_need']) === 3.0, 'Snapshot normalization must retain patient telemetry.');
phase4Assert(($snapshot['nearby_work'][0]['kind'] ?? '') === 'farm', 'Snapshot normalization must retain supported work facilities.');
phase4Assert(($snapshot['nearby_work'][0]['needs_work'] ?? false) === true, 'Snapshot normalization must retain the native needs-work result.');
phase4Assert(($snapshot['nearby_work'][0]['read_only'] ?? false) === true, 'Work facilities must remain explicitly read-only server-side.');

$aiPayload = $payload;
$aiPayload['ai'] = [
    'current_goal' => 'FIRST_AID_ORDER',
    'task_expired' => false,
    'goal_expired' => false,
    'path_failure_count' => 2,
    'intends_to_attack_target' => true,
];
$aiSnapshot = stobeAutonomyTickSnapshot($aiPayload);
phase4Assert(strval($aiSnapshot['ai']['current_goal'] ?? '') === 'FIRST_AID_ORDER', 'Snapshot normalization must retain the native current goal.');
phase4Assert(intval($aiSnapshot['ai']['path_failure_count'] ?? 0) === 2, 'Snapshot normalization must retain native path failures.');
phase4Assert(boolval($aiSnapshot['ai']['intends_to_attack_target'] ?? false), 'Snapshot normalization must retain native attack intent.');

echo "PASS: autonomy Phase 4 survival regression\n";
