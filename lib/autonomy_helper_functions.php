<?php

/**
 * Phase 1 autonomy control plane. This layer stores control intent and plugin
 * state only; it never requests an LLM decision or returns an executable action.
 */

function stobeAutonomyStates(): array
{
    return [
        'DISABLED', 'ARMING', 'OBSERVING', 'DECIDING', 'ACTION_QUEUED',
        'EXECUTING', 'COOLDOWN', 'PAUSED_USER', 'PAUSED_UNSAFE', 'ERROR',
    ];
}

function stobeAutonomyNormalizeState(mixed $value, string $fallback = 'DISABLED'): string
{
    $state = strtoupper(trim(strval($value)));
    return in_array($state, stobeAutonomyStates(), true) ? $state : $fallback;
}

function stobeAutonomyBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim(strval($value))), ['1', 'true', 'yes', 'on', 't'], true);
}

function stobeAutonomyEnsureSchema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        throw new RuntimeException('Autonomy database handle is unavailable.');
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS autonomy_session (
            id SMALLINT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
            npc_id INT,
            npc_storage_id TEXT NOT NULL DEFAULT '',
            npc_name TEXT NOT NULL DEFAULT '',
            enabled BOOLEAN NOT NULL DEFAULT FALSE,
            desired_state TEXT NOT NULL DEFAULT 'DISABLED',
            plugin_state TEXT NOT NULL DEFAULT 'DISABLED',
            control_revision BIGINT NOT NULL DEFAULT 0,
            plugin_control_revision BIGINT NOT NULL DEFAULT 0,
            runtime_serial BIGINT NOT NULL DEFAULT 0,
            stop_mode TEXT NOT NULL DEFAULT 'normal',
            policy JSONB NOT NULL DEFAULT '{\"preset\":\"full_autonomy\",\"actions\":\"all\"}'::jsonb,
            long_term_directive TEXT NOT NULL DEFAULT '',
            current_goal JSONB NOT NULL DEFAULT '{}'::jsonb,
            current_action JSONB NOT NULL DEFAULT '{}'::jsonb,
            last_observation TEXT NOT NULL DEFAULT '',
            last_error TEXT NOT NULL DEFAULT '',
            last_plugin_seen_at TIMESTAMP,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )",
        "CREATE TABLE IF NOT EXISTS autonomy_event (
            id BIGSERIAL PRIMARY KEY,
            session_id SMALLINT NOT NULL DEFAULT 1,
            control_revision BIGINT NOT NULL DEFAULT 0,
            decision_id TEXT,
            event_key TEXT,
            local_ts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW())::BIGINT,
            game_ts BIGINT NOT NULL DEFAULT 0,
            event_type TEXT NOT NULL,
            state TEXT NOT NULL DEFAULT '',
            goal JSONB NOT NULL DEFAULT '{}'::jsonb,
            command TEXT NOT NULL DEFAULT '',
            arguments JSONB NOT NULL DEFAULT '{}'::jsonb,
            outcome TEXT NOT NULL DEFAULT '',
            reason TEXT NOT NULL DEFAULT '',
            context_snapshot JSONB NOT NULL DEFAULT '{}'::jsonb,
            prompt_hash TEXT NOT NULL DEFAULT '',
            response_hash TEXT NOT NULL DEFAULT '',
            request_latency_ms INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_autonomy_event_key ON autonomy_event (event_key) WHERE event_key IS NOT NULL",
        "CREATE INDEX IF NOT EXISTS idx_autonomy_event_session_created ON autonomy_event (session_id, created_at DESC, id DESC)",
        "CREATE INDEX IF NOT EXISTS idx_autonomy_event_revision ON autonomy_event (control_revision DESC, id DESC)",
        "INSERT INTO autonomy_session (id) VALUES (1) ON CONFLICT (id) DO NOTHING",
    ];
    foreach ($statements as $statement) {
        if ($db->exec($statement) === false) {
            throw new RuntimeException('Failed to initialize autonomy schema: ' . $db->GetLastError());
        }
    }
    $ensured = true;
}

function stobeAutonomyDecodeJsonColumn(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode(strval($value), true);
    return is_array($decoded) ? $decoded : [];
}

function stobeAutonomyNormalizeSession(array $row): array
{
    $lastPluginRaw = trim(strval($row['last_plugin_seen_at'] ?? ''));
    $lastPluginTs = 0;
    if ($lastPluginRaw !== '') {
        $parsed = strtotime($lastPluginRaw . ' UTC');
        $lastPluginTs = $parsed === false ? 0 : intval($parsed);
    }
    return [
        'id' => intval($row['id'] ?? 1),
        'npc_id' => intval($row['npc_id'] ?? 0),
        'npc_storage_id' => trim(strval($row['npc_storage_id'] ?? '')),
        'npc_name' => trim(strval($row['npc_name'] ?? '')),
        'enabled' => stobeAutonomyBool($row['enabled'] ?? false),
        'desired_state' => stobeAutonomyNormalizeState($row['desired_state'] ?? 'DISABLED'),
        'plugin_state' => stobeAutonomyNormalizeState($row['plugin_state'] ?? 'DISABLED'),
        'control_revision' => intval($row['control_revision'] ?? 0),
        'plugin_control_revision' => intval($row['plugin_control_revision'] ?? 0),
        'runtime_serial' => intval($row['runtime_serial'] ?? 0),
        'stop_mode' => strtolower(trim(strval($row['stop_mode'] ?? 'normal'))) === 'emergency' ? 'emergency' : 'normal',
        'policy' => stobeAutonomyDecodeJsonColumn($row['policy'] ?? '{}'),
        'long_term_directive' => strval($row['long_term_directive'] ?? ''),
        'current_goal' => stobeAutonomyDecodeJsonColumn($row['current_goal'] ?? '{}'),
        'current_action' => stobeAutonomyDecodeJsonColumn($row['current_action'] ?? '{}'),
        'last_observation' => strval($row['last_observation'] ?? ''),
        'last_error' => strval($row['last_error'] ?? ''),
        'last_plugin_seen_at' => $lastPluginRaw,
        'last_plugin_seen_ts' => $lastPluginTs,
        'plugin_online' => $lastPluginTs > 0 && (time() - $lastPluginTs) <= 8,
        'updated_at' => strval($row['updated_at'] ?? ''),
    ];
}

function stobeAutonomyGetSession(bool $forUpdate = false): array
{
    stobeAutonomyEnsureSchema();
    $suffix = $forUpdate ? ' FOR UPDATE' : '';
    $row = $GLOBALS['db']->fetchOne('SELECT * FROM autonomy_session WHERE id = 1' . $suffix);
    if (!$row) {
        throw new RuntimeException('Autonomy singleton session is missing.');
    }
    return stobeAutonomyNormalizeSession($row);
}

function stobeAutonomyStorageIdFromRow(array $row): string
{
    $storageId = trim(strval($row['storage_id'] ?? ''));
    if ($storageId !== '') {
        return $storageId;
    }
    $metadata = stobeAutonomyDecodeJsonColumn($row['metadata'] ?? '{}');
    return trim(strval($metadata['storage_id'] ?? ($metadata['refid'] ?? '')));
}

function stobeAutonomyRuntimeSerialFromStorageId(string $storageId): int
{
    if (preg_match('/^(?:hand_|serial:)([0-9]+)$/i', trim($storageId), $match) !== 1) {
        return 0;
    }
    return intval($match[1]);
}

function stobeAutonomyNpcIsEligible(array $row): bool
{
    if (stobeAutonomyBool($row['is_player_faction_profile'] ?? false)) {
        return true;
    }
    if (function_exists('stobeNpcIsInPlayerFactionForProfileOverride')) {
        try {
            return stobeNpcIsInPlayerFactionForProfileOverride($row);
        } catch (Throwable $exception) {
        }
    }
    return false;
}

function stobeAutonomyGetNpc(int $npcId, bool $requireEligible = true): array|false
{
    if ($npcId <= 0) {
        return false;
    }
    $row = $GLOBALS['db']->fetchOne(
        "SELECT n.id, n.name, n.race, n.faction, n.profile_id, n.metadata,
                n.gamets_last_updated, n.updated_at,
                COALESCE(p.is_player_faction_profile, FALSE) AS is_player_faction_profile,
                COALESCE(NULLIF(n.metadata->>'storage_id', ''), NULLIF(n.metadata->>'refid', ''), '') AS storage_id
         FROM core_npc n
         LEFT JOIN core_profiles p ON p.id = n.profile_id
         WHERE n.id = $1
         LIMIT 1",
        [$npcId]
    );
    if (!$row || ($requireEligible && !stobeAutonomyNpcIsEligible($row))) {
        return false;
    }
    $storageId = stobeAutonomyStorageIdFromRow($row);
    if ($requireEligible && ($storageId === '' ||
        stobeAutonomyRuntimeSerialFromStorageId($storageId) <= 0 ||
        intval($row['gamets_last_updated'] ?? 0) <= 0)) {
        return false;
    }
    $row['storage_id'] = $storageId;
    $row['runtime_serial'] = stobeAutonomyRuntimeSerialFromStorageId($storageId);
    return $row;
}

function stobeAutonomyListEligibleNpcs(int $limit = 200): array
{
    stobeAutonomyEnsureSchema();
    $safeLimit = max(1, min(500, $limit));
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT n.id, n.name, n.race, n.faction, n.profile_id, n.metadata,
                n.gamets_last_updated, n.updated_at,
                COALESCE(p.is_player_faction_profile, FALSE) AS is_player_faction_profile,
                COALESCE(NULLIF(n.metadata->>'storage_id', ''), NULLIF(n.metadata->>'refid', ''), '') AS storage_id
         FROM core_npc n
         LEFT JOIN core_profiles p ON p.id = n.profile_id
         WHERE COALESCE(n.gamets_last_updated, 0) > 0
         ORDER BY n.gamets_last_updated DESC, n.updated_at DESC, n.id DESC
         LIMIT " . $safeLimit
    );
    $result = [];
    foreach ($rows as $row) {
        if (!stobeAutonomyNpcIsEligible($row)) {
            continue;
        }
        $storageId = stobeAutonomyStorageIdFromRow($row);
        if ($storageId === '' || stobeAutonomyRuntimeSerialFromStorageId($storageId) <= 0) {
            continue;
        }
        $result[] = [
            'id' => intval($row['id'] ?? 0),
            'name' => strval($row['name'] ?? ''),
            'storage_id' => $storageId,
            'runtime_serial' => stobeAutonomyRuntimeSerialFromStorageId($storageId),
            'race' => strval($row['race'] ?? ''),
            'faction' => strval($row['faction'] ?? ''),
            'gamets_last_updated' => intval($row['gamets_last_updated'] ?? 0),
        ];
    }
    return $result;
}

function stobeAutonomyRecordEvent(array $event): void
{
    stobeAutonomyEnsureSchema();
    $arguments = is_array($event['arguments'] ?? null) ? $event['arguments'] : [];
    $context = is_array($event['context_snapshot'] ?? null) ? $event['context_snapshot'] : [];
    $goal = is_array($event['goal'] ?? null) ? $event['goal'] : [];
    $GLOBALS['db']->exec(
        "INSERT INTO autonomy_event (
            session_id, control_revision, decision_id, event_key, local_ts,
            game_ts, event_type, state, goal, command, arguments, outcome,
            reason, context_snapshot, prompt_hash, response_hash,
            request_latency_ms
         ) VALUES (
            1, $1, NULLIF($2, ''), NULLIF($3, ''), EXTRACT(EPOCH FROM NOW())::BIGINT,
            $4, $5, $6, $7::jsonb, $8, $9::jsonb, $10, $11, $12::jsonb,
            $13, $14, $15
         ) ON CONFLICT (event_key) WHERE event_key IS NOT NULL DO NOTHING",
        [
            intval($event['control_revision'] ?? 0), strval($event['decision_id'] ?? ''),
            strval($event['event_key'] ?? ''), intval($event['game_ts'] ?? 0),
            strval($event['event_type'] ?? 'state'),
            stobeAutonomyNormalizeState($event['state'] ?? 'DISABLED'),
            json_encode($goal, JSON_UNESCAPED_SLASHES), strval($event['command'] ?? ''),
            json_encode($arguments, JSON_UNESCAPED_SLASHES), strval($event['outcome'] ?? ''),
            strval($event['reason'] ?? ''), json_encode($context, JSON_UNESCAPED_SLASHES),
            strval($event['prompt_hash'] ?? ''), strval($event['response_hash'] ?? ''),
            intval($event['request_latency_ms'] ?? 0),
        ]
    );
}

function stobeAutonomyListEvents(int $limit = 50): array
{
    stobeAutonomyEnsureSchema();
    $safeLimit = max(1, min(200, $limit));
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT id, control_revision, event_type, state, outcome, reason,
                command, local_ts, game_ts, created_at
         FROM autonomy_event WHERE session_id = 1
         ORDER BY id DESC LIMIT " . $safeLimit
    );
    foreach ($rows as &$row) {
        $row['id'] = intval($row['id'] ?? 0);
        $row['control_revision'] = intval($row['control_revision'] ?? 0);
        $row['local_ts'] = intval($row['local_ts'] ?? 0);
        $row['game_ts'] = intval($row['game_ts'] ?? 0);
    }
    unset($row);
    return $rows;
}

function stobeAutonomyApplyControl(string $action, array $payload): array
{
    stobeAutonomyEnsureSchema();
    $db = $GLOBALS['db'];
    $action = strtolower(trim($action));
    if (!in_array($action, ['select', 'start', 'pause', 'resume', 'stop', 'emergency_stop'], true)) {
        return ['ok' => false, 'status' => 400, 'error' => 'invalid_action'];
    }

    $db->exec('BEGIN');
    try {
        $session = stobeAutonomyGetSession(true);
        $expectedRevision = intval($payload['control_revision'] ?? -1);
        if ($expectedRevision !== intval($session['control_revision'])) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'stale_control_revision', 'session' => $session];
        }

        $npcId = intval($session['npc_id']);
        $npcStorageId = strval($session['npc_storage_id']);
        $npcName = strval($session['npc_name']);
        $enabled = boolval($session['enabled']);
        $desiredState = strval($session['desired_state']);
        $stopMode = 'normal';
        $directive = array_key_exists('long_term_directive', $payload)
            ? trim(strval($payload['long_term_directive']))
            : strval($session['long_term_directive']);
        $policy = is_array($payload['policy'] ?? null) ? $payload['policy'] : $session['policy'];

        if ($action === 'select') {
            $npc = stobeAutonomyGetNpc(intval($payload['npc_id'] ?? 0), true);
            if (!$npc) {
                $db->exec('ROLLBACK');
                return ['ok' => false, 'status' => 422, 'error' => 'npc_not_eligible'];
            }
            $npcId = intval($npc['id']);
            $npcStorageId = strval($npc['storage_id']);
            $npcName = strval($npc['name']);
            $enabled = false;
            $desiredState = 'DISABLED';
        } elseif ($action === 'start' || $action === 'resume') {
            $npc = stobeAutonomyGetNpc($npcId, true);
            if (!$npc || strval($npc['storage_id']) !== $npcStorageId) {
                $db->exec('ROLLBACK');
                return ['ok' => false, 'status' => 422, 'error' => 'selected_npc_invalid'];
            }
            $enabled = true;
            $desiredState = 'ARMING';
        } elseif ($action === 'pause') {
            if ($npcId <= 0) {
                $db->exec('ROLLBACK');
                return ['ok' => false, 'status' => 422, 'error' => 'no_selected_npc'];
            }
            $enabled = true;
            $desiredState = 'PAUSED_USER';
        } else {
            $enabled = false;
            $desiredState = 'DISABLED';
            $stopMode = $action === 'emergency_stop' ? 'emergency' : 'normal';
        }

        $revision = intval($session['control_revision']) + 1;
        $updated = $db->fetchOne(
            "UPDATE autonomy_session
             SET npc_id = NULLIF($1, 0), npc_storage_id = $2, npc_name = $3,
                 enabled = $4, desired_state = $5, control_revision = $6,
                 stop_mode = $7, policy = $8::jsonb,
                 long_term_directive = $9, last_error = '', updated_at = NOW()
             WHERE id = 1 RETURNING *",
            [
                $npcId, $npcStorageId, $npcName, $enabled, $desiredState, $revision,
                $stopMode, json_encode($policy, JSON_UNESCAPED_SLASHES), $directive,
            ]
        );
        if (!$updated) {
            throw new RuntimeException('Autonomy session update failed: ' . $db->GetLastError());
        }
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'event_key' => 'control:' . $revision,
            'event_type' => 'control_' . $action,
            'state' => $desiredState,
            'outcome' => 'accepted',
            'reason' => $stopMode,
            'arguments' => ['npc_id' => $npcId, 'npc_storage_id' => $npcStorageId],
        ]);
        $db->exec('COMMIT');
        return ['ok' => true, 'status' => 200, 'session' => stobeAutonomyNormalizeSession($updated)];
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function stobeAutonomyApplyPluginReport(array $payload): array
{
    stobeAutonomyEnsureSchema();
    $session = stobeAutonomyGetSession();
    $revision = intval($payload['control_revision'] ?? -1);
    if ($revision !== intval($session['control_revision'])) {
        return ['ok' => false, 'status' => 409, 'error' => 'stale_control_revision', 'session' => $session];
    }

    $reportedNpcId = intval($payload['npc_id'] ?? 0);
    $reportedStorageId = trim(strval($payload['npc_storage_id'] ?? ''));
    if (intval($session['npc_id']) > 0 &&
        ($reportedNpcId !== intval($session['npc_id']) || $reportedStorageId !== strval($session['npc_storage_id']))) {
        return ['ok' => false, 'status' => 409, 'error' => 'npc_identity_mismatch', 'session' => $session];
    }

    $state = stobeAutonomyNormalizeState($payload['state'] ?? 'ERROR', 'ERROR');
    $runtimeSerial = max(0, intval($payload['runtime_serial'] ?? 0));
    $observation = trim(strval($payload['observation'] ?? ''));
    $error = trim(strval($payload['error'] ?? ''));
    $updated = $GLOBALS['db']->fetchOne(
        "UPDATE autonomy_session
         SET plugin_state = $1, plugin_control_revision = $2,
             runtime_serial = $3, last_observation = $4, last_error = $5,
             last_plugin_seen_at = NOW(), updated_at = NOW()
         WHERE id = 1 RETURNING *",
        [$state, $revision, $runtimeSerial, $observation, $error]
    );
    if (!$updated) {
        throw new RuntimeException('Autonomy plugin report update failed.');
    }

    $eventKey = trim(strval($payload['event_key'] ?? ''));
    if ($eventKey !== '' || $state !== strval($session['plugin_state']) || $error !== strval($session['last_error'])) {
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'event_key' => $eventKey,
            'game_ts' => intval($payload['game_ts'] ?? 0),
            'event_type' => trim(strval($payload['event_type'] ?? 'plugin_state')) ?: 'plugin_state',
            'state' => $state,
            'outcome' => $error === '' ? 'reported' : 'error',
            'reason' => $error,
            'context_snapshot' => ['runtime_serial' => $runtimeSerial, 'observation' => $observation],
        ]);
    }
    return ['ok' => true, 'status' => 200, 'session' => stobeAutonomyNormalizeSession($updated)];
}

function stobeAutonomyReadRequestPayload(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return is_array($_POST ?? null) ? $_POST : [];
}

function stobeAutonomySendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
