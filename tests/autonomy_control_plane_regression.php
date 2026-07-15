<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function autonomyFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function autonomyAssert(bool $condition, string $message): void
{
    if (!$condition) {
        autonomyFail($message);
    }
}

function autonomyAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        autonomyFail($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

function autonomyRunEndpoint(string $file, array $payload): array
{
    $root = dirname(__DIR__);
    $endpoint = $root . DIRECTORY_SEPARATOR . $file;
    $code = '$_SERVER["REQUEST_METHOD"] = "POST"; $_POST = '
        . var_export($payload, true) . '; require ' . var_export($endpoint, true) . ';';
    $process = proc_open(
        [PHP_BINARY, '-r', $code],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root
    );
    if (!is_resource($process)) {
        autonomyFail('Unable to invoke endpoint subprocess.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        autonomyFail('Endpoint subprocess failed: ' . trim(strval($error)));
    }
    $decoded = json_decode(strval($output), true);
    autonomyAssert(is_array($decoded), 'Endpoint should return JSON: ' . strval($output));
    return $decoded;
}

$db = $GLOBALS['db'];
$db->exec('DELETE FROM autonomy_event');
$db->exec("DELETE FROM core_npc WHERE name = 'UT_AUTONOMY_NPC'");
$db->exec("UPDATE autonomy_session SET npc_id = NULL, npc_storage_id = '', npc_name = '', enabled = FALSE,
    desired_state = 'DISABLED', plugin_state = 'DISABLED', control_revision = 0,
    plugin_control_revision = 0, runtime_serial = 0, stop_mode = 'normal',
    long_term_directive = '', last_observation = '', last_error = '', last_plugin_seen_at = NULL WHERE id = 1");

$profile = $db->fetchOne('SELECT id FROM core_profiles WHERE is_player_faction_profile = TRUE LIMIT 1');
if (!$profile) {
    $profile = $db->fetchOne(
        "INSERT INTO core_profiles (label, is_player_faction_profile) VALUES ('UT_AUTONOMY_PLAYER', TRUE) RETURNING id"
    );
}
autonomyAssert(is_array($profile), 'Player-faction profile should exist.');
$npc = $db->fetchOne(
    "INSERT INTO core_npc (name, profile_id, metadata, gamets_last_updated)
     VALUES ('UT_AUTONOMY_NPC', $1, $2::jsonb, 123456) RETURNING id",
    [intval($profile['id']), json_encode(['storage_id' => 'hand_884422'])]
);
autonomyAssert(is_array($npc), 'Autonomy test NPC should be created.');
$npcId = intval($npc['id']);

$eligible = stobeAutonomyListEligibleNpcs();
autonomyAssert(count(array_filter($eligible, static fn(array $row): bool => intval($row['id']) === $npcId)) === 1, 'Eligible list should include the player-faction NPC.');

$selected = stobeAutonomyApplyControl('select', [
    'control_revision' => 0,
    'npc_id' => $npcId,
    'long_term_directive' => 'Find useful work without waiting for orders.',
]);
autonomyAssertSame(true, $selected['ok'] ?? false, 'Select should succeed.');
autonomyAssertSame(1, intval($selected['session']['control_revision'] ?? -1), 'Select should advance revision.');
autonomyAssertSame('hand_884422', $selected['session']['npc_storage_id'] ?? '', 'Select should persist stable identity.');

$stale = stobeAutonomyApplyControl('start', ['control_revision' => 0]);
autonomyAssertSame(409, intval($stale['status'] ?? 0), 'A stale control revision should conflict.');

$started = stobeAutonomyApplyControl('start', ['control_revision' => 1]);
autonomyAssertSame('ARMING', $started['session']['desired_state'] ?? '', 'Start should request ARMING.');
autonomyAssertSame(true, $started['session']['enabled'] ?? false, 'Start should enable the session.');

$wrongIdentity = stobeAutonomyApplyPluginReport([
    'control_revision' => 2, 'npc_id' => $npcId, 'npc_storage_id' => 'hand_999', 'state' => 'OBSERVING',
]);
autonomyAssertSame(409, intval($wrongIdentity['status'] ?? 0), 'A plugin identity mismatch should conflict.');

$report = [
    'control_revision' => 2,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_884422',
    'runtime_serial' => 884422,
    'state' => 'OBSERVING',
    'observation' => 'Resolved exact player-faction NPC; Phase 1 idle observation active.',
    'event_key' => 'ut-plugin-observation-1',
];
$reported = stobeAutonomyApplyPluginReport($report);
autonomyAssertSame(true, $reported['ok'] ?? false, 'Valid plugin report should succeed.');
autonomyAssertSame('OBSERVING', $reported['session']['plugin_state'] ?? '', 'Plugin state should be stored.');
autonomyAssertSame(884422, intval($reported['session']['runtime_serial'] ?? 0), 'Runtime serial should be stored.');
stobeAutonomyApplyPluginReport($report);
$eventCount = $db->fetchOne("SELECT COUNT(*) AS total FROM autonomy_event WHERE event_key = 'ut-plugin-observation-1'");
autonomyAssertSame(1, intval($eventCount['total'] ?? 0), 'Repeated event key should be idempotent.');

$paused = stobeAutonomyApplyControl('pause', ['control_revision' => 2]);
autonomyAssertSame('PAUSED_USER', $paused['session']['desired_state'] ?? '', 'Pause should request PAUSED_USER.');
$staleReport = stobeAutonomyApplyPluginReport($report);
autonomyAssertSame(409, intval($staleReport['status'] ?? 0), 'A plugin report for the prior revision should conflict.');
$afterStaleReport = stobeAutonomyGetSession();
autonomyAssertSame(2, intval($afterStaleReport['plugin_control_revision'] ?? 0), 'A stale report must not overwrite the acknowledged plugin revision.');
$resumed = stobeAutonomyApplyControl('resume', ['control_revision' => 3]);
autonomyAssertSame('ARMING', $resumed['session']['desired_state'] ?? '', 'Resume should request a fresh ARMING validation.');

$tick = autonomyRunEndpoint('autonomy_tick.php', [
    'control_revision' => 4,
    'npc_id' => $npcId,
    'npc_storage_id' => 'hand_884422',
    'runtime_serial' => 884422,
    'state' => 'OBSERVING',
    'observation' => 'Endpoint contract check',
    'event_key' => 'ut-endpoint-tick-1',
]);
autonomyAssert(array_key_exists('decision', $tick), 'Phase 1 tick should include a decision field.');
autonomyAssert(array_key_exists('action', $tick), 'Phase 1 tick should include an action field.');
autonomyAssertSame(null, $tick['decision'], 'Phase 1 tick must not return a decision.');
autonomyAssertSame(null, $tick['action'], 'Phase 1 tick must not return an action.');
autonomyAssertSame('phase_1_control_plane_only', $tick['reason'] ?? '', 'Tick should explain the Phase 1 boundary.');

$emergency = stobeAutonomyApplyControl('emergency_stop', ['control_revision' => 4]);
autonomyAssertSame(false, $emergency['session']['enabled'] ?? true, 'Emergency stop should disable autonomy.');
autonomyAssertSame('emergency', $emergency['session']['stop_mode'] ?? '', 'Emergency stop mode should be retained for the plugin.');

echo "PASS: autonomy control plane regression\n";
