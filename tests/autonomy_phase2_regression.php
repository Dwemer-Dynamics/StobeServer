<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function phase2Fail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function phase2Assert(bool $condition, string $message): void
{
    if (!$condition) {
        phase2Fail($message);
    }
}

function phase2Same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        phase2Fail($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

function phase2Tick(int $revision, int $npcId, string $storageId, int $sequence): array
{
    return stobeAutonomyApplyTick([
        'control_revision' => $revision,
        'npc_id' => $npcId,
        'npc_storage_id' => $storageId,
        'runtime_serial' => 992211,
        'state' => 'OBSERVING',
        'observation' => 'phase_2_test_snapshot',
        'event_key' => 'ut-phase2-state-' . $sequence,
        'snapshot_sequence' => $sequence,
        'snapshot_local_ts' => time(),
        'game_ts' => 765000 + $sequence,
        'position' => ['x' => 100.0, 'y' => 5.0, 'z' => 200.0],
        'context_hash' => 'phase2-context-' . $sequence,
    ]);
}

$db = $GLOBALS['db'];
$db->exec('DELETE FROM autonomy_pilot_step');
$db->exec('DELETE FROM autonomy_decision');
$db->exec('DELETE FROM autonomy_event');
$db->exec("DELETE FROM location_zones WHERE zone_name = 'UT_PHASE2_ZONE'");
$db->exec("DELETE FROM core_npc WHERE name = 'UT_PHASE2_NPC'");
$db->exec("UPDATE autonomy_session SET npc_id = NULL, npc_storage_id = '', npc_name = '',
    enabled = FALSE, desired_state = 'DISABLED', plugin_state = 'DISABLED',
    control_revision = 0, plugin_control_revision = 0, runtime_serial = 0,
    stop_mode = 'normal', current_goal = '{}'::jsonb, current_action = '{}'::jsonb,
    active_decision_id = NULL, last_decision_local_ts = 0,
    next_decision_local_ts = 0, active_elapsed_ms = 0,
    last_observation = '', last_error = '', last_plugin_seen_at = NULL WHERE id = 1");

$profile = $db->fetchOne('SELECT id FROM core_profiles WHERE is_player_faction_profile = TRUE LIMIT 1');
if (!$profile) {
    $profile = $db->fetchOne(
        "INSERT INTO core_profiles (label, is_player_faction_profile)
         VALUES ('UT_PHASE2_PLAYER', TRUE) RETURNING id"
    );
}
phase2Assert(is_array($profile), 'Player-faction profile should exist.');
$npc = $db->fetchOne(
    "INSERT INTO core_npc (name, profile_id, metadata, gamets_last_updated)
     VALUES ('UT_PHASE2_NPC', $1, $2::jsonb, 765000) RETURNING id",
    [intval($profile['id']), json_encode(['storage_id' => 'hand_992211'])]
);
$location = $db->fetchOne(
    "INSERT INTO location_zones (
        zone_name, city_name, x, y, z, first_game_ts, last_game_ts
     ) VALUES ('UT_PHASE2_ZONE', 'UT Pilot City', 321.25, 4.5, -876.75, 700000, 765000)
     RETURNING id"
);
phase2Assert(is_array($npc) && is_array($location), 'Test NPC and visited location should be created.');
$npcId = intval($npc['id']);
$locationId = intval($location['id']);

$selected = stobeAutonomyApplyControl('select', [
    'control_revision' => 0,
    'npc_id' => $npcId,
]);
phase2Same(true, $selected['ok'] ?? false, 'NPC selection should succeed.');
$started = stobeAutonomyApplyControl('start', ['control_revision' => 1]);
phase2Same(2, intval($started['session']['control_revision'] ?? 0), 'Start should advance to revision 2.');
$ready = stobeAutonomyApplyPluginReport([
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_992211',
    'runtime_serial' => 992211,
    'state' => 'OBSERVING',
    'observation' => 'phase_2_ready',
    'event_key' => 'ut-phase2-ready',
]);
phase2Same(true, $ready['ok'] ?? false, 'Plugin ready report should succeed.');

$rawCoordinates = stobeAutonomyApplyPilotControl('enqueue_travel', [
    'control_revision' => 2,
    'location_zone_id' => 0,
    'x' => 1,
    'y' => 2,
    'z' => 3,
]);
phase2Same(422, intval($rawCoordinates['status'] ?? 0), 'Raw browser coordinates must not enqueue travel.');

$travel = stobeAutonomyApplyPilotControl('enqueue_travel', [
    'control_revision' => 2,
    'location_zone_id' => $locationId,
]);
phase2Same(true, $travel['ok'] ?? false, 'Exact visited location should enqueue travel.');
$idle = stobeAutonomyApplyPilotControl('enqueue_idle', ['control_revision' => 2]);
phase2Same(true, $idle['ok'] ?? false, 'Idle pilot step should enqueue.');

$invalidSnapshot = stobeAutonomyTickSnapshot([
    'runtime_serial' => 992211,
    'snapshot_sequence' => 1,
    'snapshot_local_ts' => 0,
    'context_hash' => '',
    'position' => ['x' => 0, 'y' => 0, 'z' => 0],
]);
phase2Same(false, $invalidSnapshot, 'Tick snapshots must include fresh sequence and context metadata.');

$firstTick = phase2Tick(2, $npcId, 'hand_992211', 1);
phase2Same(true, $firstTick['ok'] ?? false, 'First Phase 2 tick should succeed.');
$firstDecision = $firstTick['decision'] ?? null;
phase2Assert(is_array($firstDecision), 'Travel tick should return a decision.');
phase2Same('TRAVEL_LOCATION', $firstDecision['command'] ?? '', 'Travel should be the first queued decision.');
phase2Same($locationId, intval($firstDecision['arguments']['location_zone_id'] ?? 0), 'Decision should retain exact location ID.');
phase2Same(321.25, floatval($firstDecision['arguments']['x'] ?? 0), 'Decision should use server-snapshotted X.');
$firstDecisionId = strval($firstDecision['decision_id'] ?? '');
phase2Assert($firstDecisionId !== '', 'Decision ID should be assigned.');

$wrongRuntime = stobeAutonomyApplyActionObservation([
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_992211',
    'runtime_serial' => 123456,
    'decision_id' => $firstDecisionId,
    'event_key' => 'ut-phase2-wrong-runtime',
    'outcome' => 'DISPATCHED',
]);
phase2Same(409, intval($wrongRuntime['status'] ?? 0), 'Action observations must match the decision runtime serial.');

$duplicateTick = phase2Tick(2, $npcId, 'hand_992211', 2);
phase2Same($firstDecisionId, strval($duplicateTick['decision']['decision_id'] ?? ''), 'Duplicate tick should return the open decision.');
$openCount = $db->fetchOne("SELECT COUNT(*) AS total FROM autonomy_decision WHERE status IN ('ISSUED', 'DISPATCHED')");
phase2Same(1, intval($openCount['total'] ?? 0), 'Only one decision may be open.');

$dispatchPayload = [
    'report_type' => 'action',
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_992211',
    'runtime_serial' => 992211,
    'decision_id' => $firstDecisionId,
    'event_key' => 'ut-phase2-dispatch-1',
    'outcome' => 'DISPATCHED',
    'reason' => 'owned_order_accepted',
    'active_elapsed_ms' => 10,
];
$dispatched = stobeAutonomyApplyPluginReport($dispatchPayload);
phase2Same('DISPATCHED', $dispatched['decision']['status'] ?? '', 'Dispatch should advance the decision.');
phase2Same('EXECUTING', $dispatched['session']['plugin_state'] ?? '', 'Dispatch should expose EXECUTING.');
$duplicateDispatch = stobeAutonomyApplyPluginReport($dispatchPayload);
phase2Same(true, $duplicateDispatch['duplicate'] ?? false, 'Repeated dispatch observation should be idempotent.');

$completed = stobeAutonomyApplyActionObservation([
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_992211',
    'runtime_serial' => 992211,
    'decision_id' => $firstDecisionId,
    'event_key' => 'ut-phase2-complete-1',
    'outcome' => 'COMPLETED',
    'reason' => 'destination_reached',
    'active_elapsed_ms' => 2500,
]);
phase2Same('COMPLETED', $completed['decision']['status'] ?? '', 'Travel completion should be durable.');
phase2Same('COOLDOWN', $completed['session']['plugin_state'] ?? '', 'Completion should enter cooldown.');
$illegalTerminal = stobeAutonomyApplyActionObservation([
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_992211',
    'runtime_serial' => 992211,
    'decision_id' => $firstDecisionId,
    'event_key' => 'ut-phase2-illegal-terminal',
    'outcome' => 'FAILED',
]);
phase2Same(409, intval($illegalTerminal['status'] ?? 0), 'A terminal decision must reject further transitions.');

$db->exec('UPDATE autonomy_session SET next_decision_local_ts = 0 WHERE id = 1');
$secondTick = phase2Tick(2, $npcId, 'hand_992211', 3);
phase2Same('IDLE', strval($secondTick['decision']['command'] ?? ''), 'Second queued decision should be IDLE.');
$secondDecisionId = strval($secondTick['decision']['decision_id'] ?? '');
phase2Assert($secondDecisionId !== '' && $secondDecisionId !== $firstDecisionId, 'Second decision should have a unique ID.');

$paused = stobeAutonomyApplyControl('pause', ['control_revision' => 2]);
phase2Same(3, intval($paused['session']['control_revision'] ?? 0), 'Pause should advance the revision.');
$cancelled = $db->fetchOne('SELECT status, outcome_reason FROM autonomy_decision WHERE decision_id = $1', [$secondDecisionId]);
phase2Same('CANCELLED', strval($cancelled['status'] ?? ''), 'Control change should cancel the old open decision.');
$staleObservation = stobeAutonomyApplyActionObservation([
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_992211',
    'decision_id' => $secondDecisionId,
    'event_key' => 'ut-phase2-stale-terminal',
    'outcome' => 'COMPLETED',
]);
phase2Same(409, intval($staleObservation['status'] ?? 0), 'Old-revision terminal observation should be rejected.');

$events = $db->fetchOne("SELECT COUNT(*) AS total FROM autonomy_event WHERE decision_id = $1", [$firstDecisionId]);
phase2Same(3, intval($events['total'] ?? 0), 'Issued, dispatched, and terminal events should be recorded exactly once.');

$db->exec('DELETE FROM autonomy_pilot_step');
$db->exec('DELETE FROM autonomy_decision');
$db->exec('DELETE FROM autonomy_event');
$db->exec("DELETE FROM location_zones WHERE id = $1", [$locationId]);
$db->exec("DELETE FROM core_npc WHERE id = $1", [$npcId]);

echo "PASS: autonomy Phase 2 decision ledger regression\n";
