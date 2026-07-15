<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function phase3DbFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function phase3DbSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        phase3DbFail($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

function phase3DbTick(int $sequence, int $npcId): array
{
    return stobeAutonomyApplyTick([
        'control_revision' => 2,
        'npc_id' => $npcId,
        'npc_storage_id' => 'hand_993311',
        'runtime_serial' => 993311,
        'state' => 'OBSERVING',
        'observation' => 'phase_3_test_snapshot',
        'event_key' => 'ut-phase3-state-' . $sequence,
        'snapshot_sequence' => $sequence,
        'snapshot_local_ts' => time(),
        'game_ts' => 865000 + $sequence,
        'position' => ['x' => 100.0, 'y' => 5.0, 'z' => 200.0],
        'status' => [
            'player_character' => true,
            'dead' => false,
            'unconscious' => false,
            'can_take_orders' => true,
            'has_player_orders' => false,
            'carrying' => false,
        ],
        'nearby_actors' => [[
            'name' => 'UT_PHASE3_BANDIT',
            'runtime_serial' => 993399,
            'distance' => 12.5,
            'dead' => false,
            'unconscious' => false,
            'player_character' => false,
        ]],
        'context_hash' => 'phase3-context-' . $sequence,
    ]);
}

$db = $GLOBALS['db'];
$db->exec('DELETE FROM autonomy_pilot_step');
$db->exec('DELETE FROM autonomy_decision');
$db->exec('DELETE FROM autonomy_event');
$db->exec("DELETE FROM core_npc WHERE name = 'UT_PHASE3_NPC'");
$db->exec("UPDATE autonomy_session SET npc_id = NULL, npc_storage_id = '', npc_name = '',
    enabled = FALSE, desired_state = 'DISABLED', plugin_state = 'DISABLED',
    control_revision = 0, plugin_control_revision = 0, runtime_serial = 0,
    planner_mode = 'llm', planner_status = 'idle', current_goal = '{}'::jsonb,
    planner_connector_id = NULL, planner_failure_count = 0, planner_backoff_seconds = 0,
    policy = '{\"preset\":\"full_autonomy\",\"actions\":\"all\"}'::jsonb,
    current_action = '{}'::jsonb, active_decision_id = NULL,
    last_decision_local_ts = 0, next_decision_local_ts = 0,
    last_prompt_hash = '', last_response_hash = '', last_allowlist = '[]'::jsonb,
    last_observation = '', last_error = '', last_plugin_seen_at = NULL,
    last_plugin_seen_local_ts = 0 WHERE id = 1");

$profile = $db->fetchOne('SELECT id FROM core_profiles WHERE is_player_faction_profile = TRUE LIMIT 1');
if (!$profile) {
    $profile = $db->fetchOne(
        "INSERT INTO core_profiles (label, is_player_faction_profile)
         VALUES ('UT_PHASE3_PLAYER', TRUE) RETURNING id"
    );
}
$npc = $db->fetchOne(
    "INSERT INTO core_npc (name, profile_id, metadata, inventory, gamets_last_updated)
     VALUES ('UT_PHASE3_NPC', $1, $2::jsonb, 'Cactus Rum, Bread', 865000) RETURNING id",
    [intval($profile['id']), json_encode(['storage_id' => 'hand_993311'])]
);
if (!$npc) {
    phase3DbFail('Phase 3 NPC should be created.');
}
$npcId = intval($npc['id']);
$connector = $db->fetchOne('SELECT id FROM core_llm_connector ORDER BY is_default DESC, id LIMIT 1');
$connectorId = intval($connector['id'] ?? 0);
if ($connectorId <= 0) {
    phase3DbFail('A planner connector should exist in the seeded schema.');
}
$actionRows = $db->fetchAll(
    'SELECT UPPER(command) AS command, is_activated FROM combined_core_action ORDER BY UPPER(command)'
);
$expectedCatalogCommands = array_keys(stobeAutonomyPlannerActionContracts());
sort($expectedCatalogCommands);
phase3DbSame(
    $expectedCatalogCommands,
    array_column($actionRows, 'command'),
    'The persisted action catalog should match every Phase 3 planner contract.'
);
phase3DbSame(
    count($actionRows),
    count(array_filter($actionRows, static fn(array $row): bool => stobeAutonomyBool($row['is_activated'] ?? false))),
    'Every registered action should be enabled by default.'
);

$invalidConnector = stobeAutonomyApplyControl('select', [
    'control_revision' => 0,
    'npc_id' => $npcId,
    'planner_connector_id' => 2147483647,
]);
phase3DbSame('planner_connector_invalid', $invalidConnector['error'] ?? '', 'Unknown planner connectors must be rejected.');

$selected = stobeAutonomyApplyControl('select', [
    'control_revision' => 0,
    'npc_id' => $npcId,
    'planner_mode' => 'llm',
    'planner_connector_id' => $connectorId,
    'long_term_directive' => 'Protect the squad.',
]);
phase3DbSame('llm', $selected['session']['planner_mode'] ?? '', 'Selection should retain LLM mode.');
phase3DbSame($connectorId, intval($selected['session']['planner_connector_id'] ?? 0), 'Selection should retain the autonomy connector.');
$started = stobeAutonomyApplyControl('start', ['control_revision' => 1]);
phase3DbSame(2, intval($started['session']['control_revision'] ?? 0), 'Start should advance revision.');
stobeAutonomyApplyPluginReport([
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_993311',
    'runtime_serial' => 993311,
    'state' => 'OBSERVING',
    'observation' => 'phase_3_ready',
    'event_key' => 'ut-phase3-ready',
]);

$GLOBALS['stobe_autonomy_planner_test_callback'] = static fn(): string => json_encode([
    'goal' => ['summary' => 'Protect the squad', 'status' => 'active'],
    'decision' => ['command' => 'ATTACK', 'target' => 'UT_PHASE3_BANDIT'],
    'reason' => 'A nearby hostile threatens the squad.',
]);
$issued = phase3DbTick(1, $npcId);
phase3DbSame(3, intval($issued['phase'] ?? 0), 'Planner tick should report Phase 3.');
phase3DbSame('ATTACK', $issued['decision']['command'] ?? '', 'Planner should issue an allowed catalog command.');
phase3DbSame('UT_PHASE3_BANDIT', $issued['decision']['arguments']['legacy_argument'] ?? '', 'Issued action should carry a validated adapter argument.');
$decisionId = strval($issued['decision']['decision_id'] ?? '');
if ($decisionId === '') {
    phase3DbFail('Issued decision should have an ID.');
}
$session = stobeAutonomyGetSession();
phase3DbSame('action_issued', $session['planner_status'] ?? '', 'Session should expose planner status.');
phase3DbSame(true, count($session['last_allowlist'] ?? []) > 0, 'Session should persist the live allowlist.');
$issuedEvent = $db->fetchOne(
    "SELECT context_snapshot FROM autonomy_event WHERE decision_id = $1 AND event_type = 'decision_issued'",
    [$decisionId]
);
$issuedAudit = stobeAutonomyDecodeJsonColumn($issuedEvent['context_snapshot'] ?? '{}');
phase3DbSame(true, in_array('ATTACK', $issuedAudit['allowlist_commands'] ?? [], true), 'Decision audit should retain the live allowlist result.');
phase3DbSame($connectorId, intval($issuedAudit['planner_connector_id'] ?? 0), 'Decision audit should retain the planner connector choice.');
phase3DbSame('full_autonomy', strval($issuedAudit['policy']['preset'] ?? ''), 'Decision audit should retain the policy preset.');

foreach (['DISPATCHED', 'COMPLETED'] as $index => $outcome) {
    $observed = stobeAutonomyApplyActionObservation([
        'control_revision' => 2,
        'npc_id' => $npcId,
        'npc_storage_id' => 'hand_993311',
        'runtime_serial' => 993311,
        'decision_id' => $decisionId,
        'event_key' => 'ut-phase3-action-' . $outcome,
        'outcome' => $outcome,
        'reason' => $outcome === 'DISPATCHED' ? 'validated_catalog_adapter' : 'accepted_by_catalog_adapter',
        'active_elapsed_ms' => $index,
    ]);
    phase3DbSame(true, $observed['ok'] ?? false, $outcome . ' observation should be accepted.');
}

$afterTerminal = stobeAutonomyGetSession();
phase3DbSame(
    true,
    intval($afterTerminal['next_decision_local_ts'] ?? 0) >= intval($afterTerminal['last_decision_local_ts'] ?? 0) + 30,
    'Terminal action handling must preserve the configured LLM decision floor.'
);
$cooldownPlannerCalls = 0;
$GLOBALS['stobe_autonomy_planner_test_callback'] = static function () use (&$cooldownPlannerCalls): string {
    $cooldownPlannerCalls++;
    return 'should-not-run';
};
$cooldown = phase3DbTick(2, $npcId);
phase3DbSame('decision_cooldown', $cooldown['reason'] ?? '', 'Changed context must not bypass the LLM decision floor.');
phase3DbSame(0, $cooldownPlannerCalls, 'Decision cooldown must skip the connector call entirely.');

$db->exec('UPDATE autonomy_session SET next_decision_local_ts = 0 WHERE id = 1');
$duplicatePlannerCalls = 0;
$GLOBALS['stobe_autonomy_planner_test_callback'] = static function () use (&$duplicatePlannerCalls): string {
    $duplicatePlannerCalls++;
    return json_encode([
        'goal' => ['summary' => 'Protect the squad', 'status' => 'active'],
        'decision' => ['command' => 'ATTACK', 'target' => 'UT_PHASE3_BANDIT'],
        'reason' => 'Repeat the same completed action.',
    ]);
};
$duplicate = phase3DbTick(3, $npcId);
phase3DbSame('duplicate_action_suppressed', $duplicate['reason'] ?? '', 'An identical completed action must not be reissued.');
phase3DbSame(null, $duplicate['decision'] ?? null, 'A suppressed duplicate must not create an executable decision.');
phase3DbSame(1, $duplicatePlannerCalls, 'Duplicate suppression should occur after one auditable planner call.');
$decisionTotal = $db->fetchOne('SELECT COUNT(*) AS total FROM autonomy_decision WHERE control_revision = 2');
phase3DbSame(1, intval($decisionTotal['total'] ?? 0), 'Duplicate suppression must preserve exactly one decision row.');

$db->exec('UPDATE autonomy_session SET next_decision_local_ts = 0 WHERE id = 1');
$GLOBALS['stobe_autonomy_planner_test_callback'] = static fn(): string => 'not-json';
$malformed = phase3DbTick(4, $npcId);
phase3DbSame(null, $malformed['decision'] ?? null, 'Malformed model output must produce no action.');
phase3DbSame('planner_malformed_json', $malformed['reason'] ?? '', 'Malformed output should expose a stable diagnostic.');
$afterMalformed = stobeAutonomyGetSession();
phase3DbSame('error', $afterMalformed['planner_status'] ?? '', 'Malformed output should set planner error status.');
phase3DbSame(1, intval($afterMalformed['planner_failure_count'] ?? 0), 'First planner failure should be counted.');
phase3DbSame(30, intval($afterMalformed['planner_backoff_seconds'] ?? 0), 'First planner failure should use the 30-second floor.');

$db->exec('UPDATE autonomy_session SET next_decision_local_ts = 0 WHERE id = 1');
$malformedAgain = phase3DbTick(5, $npcId);
phase3DbSame(null, $malformedAgain['decision'] ?? null, 'A repeated malformed response must still produce no action.');
$afterSecondMalformed = stobeAutonomyGetSession();
phase3DbSame(2, intval($afterSecondMalformed['planner_failure_count'] ?? 0), 'Consecutive planner failures should accumulate.');
phase3DbSame(60, intval($afterSecondMalformed['planner_backoff_seconds'] ?? 0), 'Consecutive planner failures should back off exponentially.');

$db->exec('UPDATE autonomy_session SET next_decision_local_ts = 0 WHERE id = 1');
$GLOBALS['stobe_autonomy_planner_test_callback'] = static fn(): string => json_encode([
    'goal' => ['summary' => 'Watch the squad', 'status' => 'active'],
    'decision' => null,
    'reason' => 'No immediate action is needed.',
]);
$waited = phase3DbTick(6, $npcId);
phase3DbSame('planner_wait', $waited['reason'] ?? '', 'A valid wait should be accepted after failures.');
$afterRecovery = stobeAutonomyGetSession();
phase3DbSame(0, intval($afterRecovery['planner_failure_count'] ?? 0), 'A valid response should reset the failure count.');
phase3DbSame(0, intval($afterRecovery['planner_backoff_seconds'] ?? 0), 'A valid response should clear failure backoff.');

unset($GLOBALS['stobe_autonomy_planner_test_callback']);
$db->exec('DELETE FROM autonomy_pilot_step');
$db->exec('DELETE FROM autonomy_decision');
$db->exec('DELETE FROM autonomy_event');
$db->exec("DELETE FROM core_npc WHERE name = 'UT_PHASE3_NPC'");
$db->exec("UPDATE autonomy_session SET npc_id = NULL, npc_storage_id = '', npc_name = '',
    enabled = FALSE, desired_state = 'DISABLED', plugin_state = 'DISABLED',
    runtime_serial = 0, planner_connector_id = NULL, planner_status = 'idle',
    planner_failure_count = 0, planner_backoff_seconds = 0, current_goal = '{}'::jsonb,
    active_decision_id = NULL, current_action = '{}'::jsonb,
    last_prompt_hash = '', last_response_hash = '', last_request_latency_ms = 0,
    planner_prompt_tokens = 0, planner_completion_tokens = 0,
    planner_decision_count = 0, last_allowlist = '[]'::jsonb,
    last_planner_context_hash = '', last_decision_local_ts = 0,
    next_decision_local_ts = 0, active_elapsed_ms = 0,
    last_observation = '', last_error = '', last_plugin_seen_at = NULL,
    last_plugin_seen_local_ts = 0 WHERE id = 1");

echo "PASS: autonomy Phase 3 supervised planner regression\n";
