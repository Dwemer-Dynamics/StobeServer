<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function phase4DbFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function phase4DbAssert(bool $condition, string $message): void
{
    if (!$condition) {
        phase4DbFail($message);
    }
}

function phase4DbSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        phase4DbFail($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

function phase4DbTick(int $sequence, int $npcId, array $overrides = []): array
{
    $payload = [
        'control_revision' => 2,
        'npc_id' => $npcId,
        'npc_storage_id' => 'hand_994411',
        'runtime_serial' => 994411,
        'state' => 'OBSERVING',
        'observation' => 'phase_4_test_snapshot',
        'event_key' => 'ut-phase4-state-' . $sequence,
        'snapshot_sequence' => $sequence,
        'snapshot_local_ts' => time(),
        'game_ts' => 965000 + $sequence,
        'position' => ['x' => 100.0, 'y' => 5.0, 'z' => 200.0],
        'status' => [
            'player_character' => true,
            'dead' => false,
            'unconscious' => false,
            'can_take_orders' => true,
            'has_player_orders' => false,
            'carrying' => false,
            'fully_rested' => true,
            'probably_dying' => false,
            'rest_bed_available' => false,
        ],
        'health' => [
            'overall' => 1.0,
            'blood' => 100.0,
            'max_blood' => 100.0,
            'bleed_rate' => 0.0,
            'first_aid_need' => 0.0,
            'robotic_aid_need' => 0.0,
        ],
        'nearby_actors' => [],
        'context_hash' => 'phase4-context-' . $sequence,
    ];
    foreach ($overrides as $field => $value) {
        if (in_array($field, ['status', 'health', 'position'], true) && is_array($value)) {
            $payload[$field] = array_replace($payload[$field], $value);
        } else {
            $payload[$field] = $value;
        }
    }
    return stobeAutonomyApplyTick($payload);
}

function phase4DbComplete(array $decision, int $npcId, int $sequence): void
{
    foreach (['DISPATCHED', 'COMPLETED'] as $index => $outcome) {
        $result = stobeAutonomyApplyActionObservation([
            'control_revision' => 2,
            'npc_id' => $npcId,
            'npc_storage_id' => 'hand_994411',
            'runtime_serial' => 994411,
            'decision_id' => strval($decision['decision_id'] ?? ''),
            'event_key' => 'ut-phase4-action-' . $sequence . '-' . strtolower($outcome),
            'outcome' => $outcome,
            'reason' => $outcome === 'DISPATCHED' ? 'owned_order_accepted' : 'phase4_test_complete',
            'active_elapsed_ms' => $index * 1000,
        ]);
        phase4DbSame(true, $result['ok'] ?? false, $outcome . ' observation should be accepted.');
    }
    $GLOBALS['db']->exec('UPDATE autonomy_session SET next_decision_local_ts = 0 WHERE id = 1');
}

$db = $GLOBALS['db'];
$db->exec('DELETE FROM autonomy_pilot_step');
$db->exec('DELETE FROM autonomy_decision');
$db->exec('DELETE FROM autonomy_event');
$db->exec("DELETE FROM core_npc WHERE name = 'UT_PHASE4_NPC'");
$db->exec("UPDATE autonomy_session SET npc_id = NULL, npc_storage_id = '', npc_name = '',
    enabled = FALSE, desired_state = 'DISABLED', plugin_state = 'DISABLED',
    control_revision = 0, plugin_control_revision = 0, runtime_serial = 0,
    planner_mode = 'llm', planner_status = 'idle', current_goal = '{}'::jsonb,
    planner_connector_id = NULL, policy = '{\"preset\":\"full_autonomy\",\"actions\":\"all\"}'::jsonb,
    current_action = '{}'::jsonb, active_decision_id = NULL,
    last_decision_local_ts = 0, next_decision_local_ts = 0,
    last_allowlist = '[]'::jsonb, last_error = '' WHERE id = 1");

$profile = $db->fetchOne('SELECT id FROM core_profiles WHERE is_player_faction_profile = TRUE LIMIT 1');
if (!$profile) {
    $profile = $db->fetchOne(
        "INSERT INTO core_profiles (label, is_player_faction_profile)
         VALUES ('UT_PHASE4_PLAYER', TRUE) RETURNING id"
    );
}
$npc = $db->fetchOne(
    "INSERT INTO core_npc (name, profile_id, metadata, inventory, gamets_last_updated)
     VALUES ('UT_PHASE4_NPC', $1, $2::jsonb, 'Standard First Aid Kit', 965000) RETURNING id",
    [intval($profile['id']), json_encode(['storage_id' => 'hand_994411'])]
);
phase4DbAssert(is_array($npc), 'Phase 4 NPC should be created.');
$npcId = intval($npc['id']);
$connector = $db->fetchOne('SELECT id FROM core_llm_connector ORDER BY is_default DESC, id LIMIT 1');
$connectorId = intval($connector['id'] ?? 0);
phase4DbAssert($connectorId > 0, 'A planner connector should exist.');

stobeAutonomyApplyControl('select', [
    'control_revision' => 0,
    'npc_id' => $npcId,
    'planner_mode' => 'llm',
    'planner_connector_id' => $connectorId,
]);
$started = stobeAutonomyApplyControl('start', ['control_revision' => 1]);
phase4DbSame(2, intval($started['session']['control_revision'] ?? 0), 'Start should advance revision.');
stobeAutonomyApplyPluginReport([
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_994411',
    'runtime_serial' => 994411,
    'state' => 'OBSERVING',
    'observation' => 'phase_4_ready',
    'event_key' => 'ut-phase4-ready',
]);

$GLOBALS['stobe_autonomy_planner_test_callback'] = static fn(): string => json_encode([
    'goal' => ['summary' => 'Check the eastern ground', 'status' => 'active'],
    'decision' => ['command' => 'MOVE_NEARBY', 'direction' => 'E', 'distance' => 25],
]);
$move = phase4DbTick(1, $npcId);
phase4DbSame(4, intval($move['phase'] ?? 0), 'Phase 4 tick should report protocol 4.');
phase4DbSame('MOVE_NEARBY', $move['decision']['command'] ?? '', 'MOVE_NEARBY should issue through the ledger.');
phase4DbAssert(abs(floatval($move['decision']['arguments']['x'] ?? 0) - 125.0) < 0.001, 'MOVE_NEARBY should persist its derived destination.');
phase4DbComplete($move['decision'], $npcId, 1);

$GLOBALS['stobe_autonomy_planner_test_callback'] = static fn(): string => json_encode([
    'goal' => ['summary' => 'Escape the bandit', 'status' => 'active'],
    'decision' => ['command' => 'FLEE'],
]);
$flee = phase4DbTick(2, $npcId, [
    'status' => ['probably_dying' => true],
    'health' => ['overall' => 0.3],
    'nearby_actors' => [[
        'name' => 'UT_PHASE4_BANDIT', 'runtime_serial' => 994499,
        'distance' => 10.0, 'x' => 90.0, 'y' => 5.0, 'z' => 200.0,
        'hostile' => true, 'dead' => false, 'player_character' => false,
    ]],
]);
phase4DbSame('FLEE', $flee['decision']['command'] ?? '', 'FLEE should issue through the ledger.');
phase4DbAssert(floatval($flee['decision']['arguments']['x'] ?? 0) > 100.0, 'FLEE should persist a destination away from the threat.');
phase4DbComplete($flee['decision'], $npcId, 2);

$GLOBALS['stobe_autonomy_planner_test_callback'] = static fn(): string => json_encode([
    'goal' => ['summary' => 'Stop the bleeding', 'status' => 'active'],
    'decision' => ['command' => 'FIRST_AID', 'target' => 'UT_PHASE4_NPC'],
]);
$aid = phase4DbTick(3, $npcId, [
    'status' => ['fully_rested' => false],
    'health' => ['overall' => 0.6, 'bleed_rate' => 0.5, 'first_aid_need' => 8.0],
]);
phase4DbSame('FIRST_AID', $aid['decision']['command'] ?? '', 'FIRST_AID should issue through the ledger.');
phase4DbSame(994411, intval($aid['decision']['arguments']['target_runtime_serial'] ?? 0), 'FIRST_AID should persist the observed target serial.');
phase4DbComplete($aid['decision'], $npcId, 3);

$GLOBALS['stobe_autonomy_planner_test_callback'] = static fn(): string => json_encode([
    'goal' => ['summary' => 'Recover in a nearby bed', 'status' => 'active'],
    'decision' => ['command' => 'REST'],
]);
$rest = phase4DbTick(4, $npcId, [
    'status' => ['fully_rested' => false, 'rest_bed_available' => true],
    'health' => ['overall' => 0.8],
]);
phase4DbSame('REST', $rest['decision']['command'] ?? '', 'REST should issue through the ledger.');
phase4DbComplete($rest['decision'], $npcId, 4);

$rows = $db->fetchAll(
    "SELECT command, status FROM autonomy_decision
     WHERE control_revision = 2 ORDER BY issued_at, command"
);
phase4DbSame(['MOVE_NEARBY', 'FLEE', 'FIRST_AID', 'REST'], array_column($rows, 'command'), 'The ledger should retain all four Phase 4 commands in issue order.');
phase4DbSame(['COMPLETED', 'COMPLETED', 'COMPLETED', 'COMPLETED'], array_column($rows, 'status'), 'Every Phase 4 decision should reach a terminal state.');

$pilotQueued = stobeAutonomyApplyPilotControl('enqueue_move_nearby', [
    'control_revision' => 2,
    'direction' => 'N',
    'distance' => 30,
]);
phase4DbSame(true, $pilotQueued['ok'] ?? false, 'The deterministic pilot should queue MOVE_NEARBY.');
$pilotMove = phase4DbTick(5, $npcId);
phase4DbSame(4, intval($pilotMove['phase'] ?? 0), 'A Phase 4 pilot decision should report protocol 4.');
phase4DbSame('MOVE_NEARBY', $pilotMove['decision']['command'] ?? '', 'The pilot should claim MOVE_NEARBY through the production normalizer.');
phase4DbAssert(floatval($pilotMove['decision']['arguments']['z'] ?? 0) < 200.0, 'The pilot should derive its north destination from the live position.');
phase4DbComplete($pilotMove['decision'], $npcId, 5);

unset($GLOBALS['stobe_autonomy_planner_test_callback']);
$db->exec('DELETE FROM autonomy_pilot_step');
$db->exec('DELETE FROM autonomy_decision');
$db->exec('DELETE FROM autonomy_event');
$db->exec("DELETE FROM core_npc WHERE name = 'UT_PHASE4_NPC'");
$db->exec("UPDATE autonomy_session SET npc_id = NULL, npc_storage_id = '', npc_name = '',
    enabled = FALSE, desired_state = 'DISABLED', plugin_state = 'DISABLED',
    runtime_serial = 0, planner_connector_id = NULL, planner_status = 'idle',
    current_goal = '{}'::jsonb, active_decision_id = NULL,
    current_action = '{}'::jsonb, last_allowlist = '[]'::jsonb,
    next_decision_local_ts = 0, last_error = '' WHERE id = 1");

echo "PASS: autonomy Phase 4 decision ledger regression\n";
