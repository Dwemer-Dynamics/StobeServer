<?php

/**
 * Autonomy control plane and deterministic Phase 2 decision ledger.
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
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS active_decision_id TEXT",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS last_decision_local_ts BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS next_decision_local_ts BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS active_elapsed_ms BIGINT NOT NULL DEFAULT 0",
        "CREATE TABLE IF NOT EXISTS autonomy_decision (
            decision_id TEXT PRIMARY KEY,
            session_id SMALLINT NOT NULL DEFAULT 1,
            control_revision BIGINT NOT NULL,
            npc_id INT NOT NULL,
            npc_storage_id TEXT NOT NULL,
            runtime_serial BIGINT NOT NULL DEFAULT 0,
            command TEXT NOT NULL CHECK (command IN ('IDLE', 'TRAVEL_LOCATION')),
            arguments JSONB NOT NULL DEFAULT '{}'::jsonb,
            context_hash TEXT NOT NULL DEFAULT '',
            context_game_ts BIGINT NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'ISSUED' CHECK (status IN (
                'ISSUED', 'DISPATCHED', 'COMPLETED', 'FAILED',
                'INTERRUPTED', 'TIMED_OUT', 'CANCELLED'
            )),
            issued_at TIMESTAMP NOT NULL DEFAULT NOW(),
            dispatch_deadline_at TIMESTAMP NOT NULL,
            action_deadline_at TIMESTAMP NOT NULL,
            terminal_at TIMESTAMP,
            outcome_reason TEXT NOT NULL DEFAULT '',
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_autonomy_decision_one_open
            ON autonomy_decision (session_id)
            WHERE status IN ('ISSUED', 'DISPATCHED')",
        "CREATE INDEX IF NOT EXISTS idx_autonomy_decision_revision
            ON autonomy_decision (control_revision DESC, issued_at DESC)",
        "CREATE TABLE IF NOT EXISTS autonomy_pilot_step (
            id BIGSERIAL PRIMARY KEY,
            session_id SMALLINT NOT NULL DEFAULT 1,
            control_revision BIGINT NOT NULL,
            command TEXT NOT NULL CHECK (command IN ('IDLE', 'TRAVEL_LOCATION')),
            arguments JSONB NOT NULL DEFAULT '{}'::jsonb,
            location_zone_id BIGINT,
            status TEXT NOT NULL DEFAULT 'PENDING' CHECK (status IN (
                'PENDING', 'CLAIMED', 'COMPLETED', 'CANCELLED'
            )),
            decision_id TEXT,
            created_at TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMP NOT NULL DEFAULT NOW()
        )",
        "CREATE INDEX IF NOT EXISTS idx_autonomy_pilot_step_pending
            ON autonomy_pilot_step (session_id, control_revision, id)
            WHERE status = 'PENDING'",
        "CREATE INDEX IF NOT EXISTS idx_autonomy_pilot_step_decision
            ON autonomy_pilot_step (decision_id)",
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
        'active_decision_id' => trim(strval($row['active_decision_id'] ?? '')),
        'last_decision_local_ts' => intval($row['last_decision_local_ts'] ?? 0),
        'next_decision_local_ts' => intval($row['next_decision_local_ts'] ?? 0),
        'active_elapsed_ms' => intval($row['active_elapsed_ms'] ?? 0),
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

function stobeAutonomyCoordinate(mixed $value): float|false
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return false;
    }
    $coordinate = floatval($value);
    return is_finite($coordinate) && abs($coordinate) <= 10000000.0 ? $coordinate : false;
}

function stobeAutonomyNormalizeLocation(array $row): array|false
{
    $x = stobeAutonomyCoordinate($row['x'] ?? null);
    $y = stobeAutonomyCoordinate($row['y'] ?? null);
    $z = stobeAutonomyCoordinate($row['z'] ?? null);
    if ($x === false || $y === false || $z === false) {
        return false;
    }
    return [
        'id' => intval($row['id'] ?? 0),
        'zone_name' => trim(strval($row['zone_name'] ?? '')),
        'city_name' => trim(strval($row['city_name'] ?? '')),
        'x' => $x,
        'y' => $y,
        'z' => $z,
        'last_game_ts' => intval($row['last_game_ts'] ?? 0),
        'last_seen_ts' => intval($row['last_seen_ts'] ?? 0),
    ];
}

function stobeAutonomyListVisitedLocations(int $limit = 500): array
{
    stobeAutonomyEnsureSchema();
    $safeLimit = max(1, min(1000, $limit));
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT id, zone_name, city_name, x, y, z, last_game_ts, last_seen_ts
         FROM location_zones
         WHERE x IS NOT NULL AND y IS NOT NULL AND z IS NOT NULL
         ORDER BY last_seen_ts DESC, id DESC LIMIT " . $safeLimit
    );
    $result = [];
    foreach ($rows as $row) {
        $normalized = stobeAutonomyNormalizeLocation($row);
        if ($normalized && $normalized['id'] > 0) {
            $result[] = $normalized;
        }
    }
    return $result;
}

function stobeAutonomyGetVisitedLocation(int $locationId): array|false
{
    if ($locationId <= 0) {
        return false;
    }
    $row = $GLOBALS['db']->fetchOne(
        "SELECT id, zone_name, city_name, x, y, z, last_game_ts, last_seen_ts
         FROM location_zones WHERE id = $1 LIMIT 1",
        [$locationId]
    );
    return $row ? stobeAutonomyNormalizeLocation($row) : false;
}

function stobeAutonomyNormalizeDecision(array $row): array
{
    $arguments = stobeAutonomyDecodeJsonColumn($row['arguments'] ?? '{}');
    return [
        'decision_id' => trim(strval($row['decision_id'] ?? '')),
        'control_revision' => intval($row['control_revision'] ?? 0),
        'npc_id' => intval($row['npc_id'] ?? 0),
        'npc_storage_id' => trim(strval($row['npc_storage_id'] ?? '')),
        'runtime_serial' => intval($row['runtime_serial'] ?? 0),
        'command' => strtoupper(trim(strval($row['command'] ?? ''))),
        'arguments' => $arguments,
        'context_hash' => trim(strval($row['context_hash'] ?? '')),
        'context_game_ts' => intval($row['context_game_ts'] ?? 0),
        'status' => strtoupper(trim(strval($row['status'] ?? ''))),
        'dispatch_deadline_at' => strval($row['dispatch_deadline_at'] ?? ''),
        'dispatch_deadline_ts' => intval(strtotime(strval($row['dispatch_deadline_at'] ?? '') . ' UTC') ?: 0),
        'action_deadline_at' => strval($row['action_deadline_at'] ?? ''),
        'action_deadline_ts' => intval(strtotime(strval($row['action_deadline_at'] ?? '') . ' UTC') ?: 0),
        'outcome_reason' => strval($row['outcome_reason'] ?? ''),
        'issued_at' => strval($row['issued_at'] ?? ''),
        'terminal_at' => strval($row['terminal_at'] ?? ''),
        'updated_at' => strval($row['updated_at'] ?? ''),
    ];
}

function stobeAutonomyListDecisions(int $limit = 30): array
{
    stobeAutonomyEnsureSchema();
    $safeLimit = max(1, min(200, $limit));
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT * FROM autonomy_decision WHERE session_id = 1
         ORDER BY issued_at DESC, decision_id DESC LIMIT " . $safeLimit
    );
    return array_map('stobeAutonomyNormalizeDecision', $rows);
}

function stobeAutonomyListPilotSteps(int $limit = 50): array
{
    stobeAutonomyEnsureSchema();
    $safeLimit = max(1, min(200, $limit));
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT id, control_revision, command, arguments, location_zone_id,
                status, decision_id, created_at, updated_at
         FROM autonomy_pilot_step WHERE session_id = 1
         ORDER BY id DESC LIMIT " . $safeLimit
    );
    foreach ($rows as &$row) {
        $row['id'] = intval($row['id'] ?? 0);
        $row['control_revision'] = intval($row['control_revision'] ?? 0);
        $row['location_zone_id'] = intval($row['location_zone_id'] ?? 0);
        $row['arguments'] = stobeAutonomyDecodeJsonColumn($row['arguments'] ?? '{}');
    }
    unset($row);
    return $rows;
}

function stobeAutonomyUuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' .
        substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
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
        "SELECT id, control_revision, decision_id, event_type, state, outcome, reason,
                command, arguments, local_ts, game_ts, created_at
         FROM autonomy_event WHERE session_id = 1
         ORDER BY id DESC LIMIT " . $safeLimit
    );
    foreach ($rows as &$row) {
        $row['id'] = intval($row['id'] ?? 0);
        $row['control_revision'] = intval($row['control_revision'] ?? 0);
        $row['local_ts'] = intval($row['local_ts'] ?? 0);
        $row['game_ts'] = intval($row['game_ts'] ?? 0);
        $row['arguments'] = stobeAutonomyDecodeJsonColumn($row['arguments'] ?? '{}');
    }
    unset($row);
    return $rows;
}

function stobeAutonomyApplyPilotControl(string $action, array $payload): array
{
    stobeAutonomyEnsureSchema();
    $action = strtolower(trim($action));
    if (!in_array($action, ['enqueue_idle', 'enqueue_travel', 'cancel_pending'], true)) {
        return ['ok' => false, 'status' => 400, 'error' => 'invalid_pilot_action'];
    }

    $db = $GLOBALS['db'];
    $db->exec('BEGIN');
    try {
        $session = stobeAutonomyGetSession(true);
        $revision = intval($payload['control_revision'] ?? -1);
        if ($revision !== intval($session['control_revision'])) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'stale_control_revision', 'session' => $session];
        }

        if ($action === 'cancel_pending') {
            $db->exec(
                "UPDATE autonomy_pilot_step SET status = 'CANCELLED', updated_at = NOW()
                 WHERE session_id = 1 AND control_revision = $1 AND status = 'PENDING'",
                [$revision]
            );
            $db->exec('COMMIT');
            return ['ok' => true, 'status' => 200, 'steps' => stobeAutonomyListPilotSteps()];
        }

        if (!$session['enabled']) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 422, 'error' => 'autonomy_not_enabled'];
        }
        if (!$session['plugin_online'] || intval($session['plugin_control_revision']) !== $revision) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'plugin_not_ready', 'session' => $session];
        }
        if (in_array($session['plugin_state'], ['PAUSED_USER', 'PAUSED_UNSAFE', 'ERROR', 'DISABLED'], true)) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'plugin_not_runnable', 'session' => $session];
        }

        $command = 'IDLE';
        $locationId = 0;
        $arguments = ['duration_ms' => 1500];
        if ($action === 'enqueue_travel') {
            $command = 'TRAVEL_LOCATION';
            $locationId = intval($payload['location_zone_id'] ?? 0);
            $location = stobeAutonomyGetVisitedLocation($locationId);
            if (!$location) {
                $db->exec('ROLLBACK');
                return ['ok' => false, 'status' => 422, 'error' => 'location_not_visited'];
            }
            $arguments = [
                'location_zone_id' => $location['id'],
                'zone_name' => $location['zone_name'],
                'city_name' => $location['city_name'],
                'x' => $location['x'],
                'y' => $location['y'],
                'z' => $location['z'],
                'arrival_radius' => 8.0,
            ];
        }

        $step = $db->fetchOne(
            "INSERT INTO autonomy_pilot_step (
                session_id, control_revision, command, arguments,
                location_zone_id, status
             ) VALUES (1, $1, $2, $3::jsonb, NULLIF($4, 0), 'PENDING')
             RETURNING *",
            [$revision, $command, json_encode($arguments, JSON_UNESCAPED_SLASHES), $locationId]
        );
        if (!$step) {
            throw new RuntimeException('Failed to enqueue autonomy pilot step: ' . $db->GetLastError());
        }
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'event_key' => 'pilot:' . $revision . ':' . intval($step['id']),
            'event_type' => 'pilot_queued',
            'state' => $session['plugin_state'],
            'command' => $command,
            'arguments' => $arguments,
            'outcome' => 'queued',
        ]);
        $db->exec('COMMIT');
        return ['ok' => true, 'status' => 200, 'steps' => stobeAutonomyListPilotSteps()];
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function stobeAutonomyTickSnapshot(array $payload): array|false
{
    $snapshotLocalTs = intval($payload['snapshot_local_ts'] ?? 0);
    $snapshotSequence = intval($payload['snapshot_sequence'] ?? 0);
    $contextHash = trim(strval($payload['context_hash'] ?? ''));
    if ($snapshotLocalTs <= 0 || abs(time() - $snapshotLocalTs) > 15 ||
        $snapshotSequence <= 0 || $contextHash === '' || strlen($contextHash) > 128 ||
        intval($payload['runtime_serial'] ?? 0) <= 0) {
        return false;
    }
    $position = is_array($payload['position'] ?? null) ? $payload['position'] : [];
    $x = stobeAutonomyCoordinate($position['x'] ?? null);
    $y = stobeAutonomyCoordinate($position['y'] ?? null);
    $z = stobeAutonomyCoordinate($position['z'] ?? null);
    if ($x === false || $y === false || $z === false) {
        return false;
    }
    return [
        'snapshot_sequence' => $snapshotSequence,
        'snapshot_local_ts' => $snapshotLocalTs,
        'game_ts' => max(0, intval($payload['game_ts'] ?? 0)),
        'position' => ['x' => $x, 'y' => $y, 'z' => $z],
        'context_hash' => $contextHash,
    ];
}

function stobeAutonomyApplyTick(array $payload): array
{
    stobeAutonomyEnsureSchema();
    $stateReport = stobeAutonomyApplyPluginReport($payload);
    if (!boolval($stateReport['ok'] ?? false)) {
        return $stateReport;
    }
    $snapshot = stobeAutonomyTickSnapshot($payload);
    if (!$snapshot) {
        return ['ok' => false, 'status' => 422, 'error' => 'invalid_or_stale_snapshot'];
    }

    $db = $GLOBALS['db'];
    $db->exec('BEGIN');
    try {
        $session = stobeAutonomyGetSession(true);
        $revision = intval($payload['control_revision'] ?? -1);
        $npcId = intval($payload['npc_id'] ?? 0);
        $storageId = trim(strval($payload['npc_storage_id'] ?? ''));
        if ($revision !== intval($session['control_revision'])) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'stale_control_revision', 'session' => $session];
        }
        if ($npcId !== intval($session['npc_id']) || $storageId !== $session['npc_storage_id']) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'npc_identity_mismatch', 'session' => $session];
        }
        if (!$session['enabled'] || !in_array($session['plugin_state'], ['OBSERVING', 'COOLDOWN', 'DECIDING'], true)) {
            $db->exec('COMMIT');
            return ['ok' => true, 'status' => 200, 'phase' => 2, 'session' => $session,
                'decision' => null, 'action' => null, 'reason' => 'controller_not_observing'];
        }

        $open = $db->fetchOne(
            "SELECT * FROM autonomy_decision
             WHERE session_id = 1 AND status IN ('ISSUED', 'DISPATCHED')
             ORDER BY issued_at DESC LIMIT 1 FOR UPDATE"
        );
        if ($open) {
            $db->exec('COMMIT');
            $decision = stobeAutonomyNormalizeDecision($open);
            return ['ok' => true, 'status' => 200, 'phase' => 2, 'session' => $session,
                'decision' => $decision, 'action' => $decision];
        }
        if (intval($session['next_decision_local_ts']) > time()) {
            $db->exec('COMMIT');
            return ['ok' => true, 'status' => 200, 'phase' => 2, 'session' => $session,
                'decision' => null, 'action' => null, 'reason' => 'decision_cooldown'];
        }

        $step = $db->fetchOne(
            "SELECT * FROM autonomy_pilot_step
             WHERE session_id = 1 AND control_revision = $1 AND status = 'PENDING'
             ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED",
            [$revision]
        );
        if (!$step) {
            $db->exec('COMMIT');
            return ['ok' => true, 'status' => 200, 'phase' => 2, 'session' => $session,
                'decision' => null, 'action' => null, 'reason' => 'pilot_queue_empty'];
        }

        $command = strtoupper(trim(strval($step['command'] ?? '')));
        $arguments = stobeAutonomyDecodeJsonColumn($step['arguments'] ?? '{}');
        if (!in_array($command, ['IDLE', 'TRAVEL_LOCATION'], true)) {
            throw new RuntimeException('Pilot step contains an unsupported command.');
        }
        if ($command === 'TRAVEL_LOCATION') {
            foreach (['x', 'y', 'z'] as $coordinateName) {
                if (stobeAutonomyCoordinate($arguments[$coordinateName] ?? null) === false) {
                    throw new RuntimeException('Pilot travel step contains invalid coordinates.');
                }
            }
        }

        $decisionId = stobeAutonomyUuid4();
        $now = time();
        $dispatchDeadline = gmdate('Y-m-d H:i:s', $now + 10);
        $actionDeadline = gmdate('Y-m-d H:i:s', $now + ($command === 'IDLE' ? 20 : 900));
        $runtimeSerial = max(0, intval($payload['runtime_serial'] ?? 0));
        $decisionRow = $db->fetchOne(
            "INSERT INTO autonomy_decision (
                decision_id, session_id, control_revision, npc_id,
                npc_storage_id, runtime_serial, command, arguments,
                context_hash, context_game_ts, status,
                dispatch_deadline_at, action_deadline_at
             ) VALUES ($1, 1, $2, $3, $4, $5, $6, $7::jsonb, $8, $9,
                       'ISSUED', $10::timestamp, $11::timestamp)
             RETURNING *",
            [
                $decisionId, $revision, $npcId, $storageId, $runtimeSerial,
                $command, json_encode($arguments, JSON_UNESCAPED_SLASHES),
                $snapshot['context_hash'], $snapshot['game_ts'],
                $dispatchDeadline, $actionDeadline,
            ]
        );
        if (!$decisionRow) {
            throw new RuntimeException('Failed to create autonomy decision: ' . $db->GetLastError());
        }
        $db->exec(
            "UPDATE autonomy_pilot_step SET status = 'CLAIMED', decision_id = $1,
                    updated_at = NOW() WHERE id = $2 AND status = 'PENDING'",
            [$decisionId, intval($step['id'])]
        );
        $currentAction = [
            'decision_id' => $decisionId,
            'command' => $command,
            'arguments' => $arguments,
            'status' => 'ISSUED',
        ];
        $db->exec(
            "UPDATE autonomy_session SET active_decision_id = $1,
                    current_action = $2::jsonb,
                    last_decision_local_ts = EXTRACT(EPOCH FROM NOW())::BIGINT,
                    active_elapsed_ms = 0, updated_at = NOW()
             WHERE id = 1",
            [$decisionId, json_encode($currentAction, JSON_UNESCAPED_SLASHES)]
        );
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'decision_id' => $decisionId,
            'event_key' => 'decision:' . $decisionId . ':issued',
            'game_ts' => $snapshot['game_ts'],
            'event_type' => 'decision_issued',
            'state' => 'ACTION_QUEUED',
            'command' => $command,
            'arguments' => $arguments,
            'outcome' => 'issued',
            'context_snapshot' => $snapshot,
        ]);
        $db->exec('COMMIT');
        $decision = stobeAutonomyNormalizeDecision($decisionRow);
        return ['ok' => true, 'status' => 200, 'phase' => 2,
            'session' => stobeAutonomyGetSession(), 'decision' => $decision, 'action' => $decision];
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function stobeAutonomyApplyActionObservation(array $payload): array
{
    stobeAutonomyEnsureSchema();
    $decisionId = trim(strval($payload['decision_id'] ?? ''));
    $eventKey = trim(strval($payload['event_key'] ?? ''));
    $outcome = strtoupper(trim(strval($payload['outcome'] ?? '')));
    $allowed = ['DISPATCHED', 'COMPLETED', 'FAILED', 'INTERRUPTED', 'TIMED_OUT', 'CANCELLED'];
    if ($decisionId === '' || $eventKey === '' || !in_array($outcome, $allowed, true)) {
        return ['ok' => false, 'status' => 422, 'error' => 'invalid_action_observation'];
    }

    $db = $GLOBALS['db'];
    $db->exec('BEGIN');
    try {
        $existingEvent = $db->fetchOne('SELECT id FROM autonomy_event WHERE event_key = $1 LIMIT 1', [$eventKey]);
        if ($existingEvent) {
            $db->exec('COMMIT');
            return ['ok' => true, 'status' => 200, 'duplicate' => true,
                'session' => stobeAutonomyGetSession()];
        }
        $session = stobeAutonomyGetSession(true);
        $revision = intval($payload['control_revision'] ?? -1);
        $npcId = intval($payload['npc_id'] ?? 0);
        $storageId = trim(strval($payload['npc_storage_id'] ?? ''));
        if ($revision !== intval($session['control_revision'])) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'stale_control_revision', 'session' => $session];
        }
        if ($npcId !== intval($session['npc_id']) || $storageId !== $session['npc_storage_id']) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'npc_identity_mismatch', 'session' => $session];
        }
        $decisionRow = $db->fetchOne(
            'SELECT * FROM autonomy_decision WHERE decision_id = $1 FOR UPDATE',
            [$decisionId]
        );
        if (!$decisionRow || intval($decisionRow['control_revision'] ?? -1) !== $revision ||
            intval($decisionRow['npc_id'] ?? 0) !== $npcId ||
            strval($decisionRow['npc_storage_id'] ?? '') !== $storageId) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'decision_identity_mismatch'];
        }
        $currentStatus = strtoupper(strval($decisionRow['status'] ?? ''));
        $legal = ($outcome === 'DISPATCHED' && $currentStatus === 'ISSUED') ||
            ($outcome !== 'DISPATCHED' && in_array($currentStatus, ['ISSUED', 'DISPATCHED'], true));
        if (!$legal) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'illegal_decision_transition'];
        }

        $terminal = $outcome !== 'DISPATCHED';
        $reason = substr(trim(strval($payload['reason'] ?? '')), 0, 300);
        $runtimeSerial = max(0, intval($payload['runtime_serial'] ?? 0));
        $decisionRuntimeSerial = max(0, intval($decisionRow['runtime_serial'] ?? 0));
        if ($runtimeSerial <= 0 || ($decisionRuntimeSerial > 0 && $runtimeSerial !== $decisionRuntimeSerial)) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'runtime_serial_mismatch'];
        }
        $activeElapsedMs = max(0, intval($payload['active_elapsed_ms'] ?? 0));
        $db->exec(
            "UPDATE autonomy_decision SET status = $1, runtime_serial = $2,
                    outcome_reason = $3,
                    terminal_at = CASE WHEN $4 THEN NOW() ELSE terminal_at END,
                    updated_at = NOW()
             WHERE decision_id = $5",
            [$outcome, $runtimeSerial, $reason, $terminal, $decisionId]
        );

        $pluginState = 'EXECUTING';
        if ($terminal) {
            if ($outcome === 'INTERRUPTED') {
                $pluginState = $reason === 'manual_player_order_detected' ? 'PAUSED_USER' : 'PAUSED_UNSAFE';
            } elseif ($outcome === 'CANCELLED') {
                $pluginState = $session['enabled'] ? 'PAUSED_USER' : 'DISABLED';
            } else {
                $pluginState = $session['enabled'] ? 'COOLDOWN' : 'DISABLED';
            }
        }
        $error = in_array($outcome, ['FAILED', 'TIMED_OUT'], true) ? $reason : '';
        $currentAction = $terminal ? [] : [
            'decision_id' => $decisionId,
            'command' => strval($decisionRow['command'] ?? ''),
            'arguments' => stobeAutonomyDecodeJsonColumn($decisionRow['arguments'] ?? '{}'),
            'status' => $outcome,
        ];
        $db->exec(
            "UPDATE autonomy_session SET plugin_state = $1,
                    plugin_control_revision = $2, runtime_serial = $3,
                    current_action = $4::jsonb,
                    active_decision_id = CASE WHEN $5 THEN NULL ELSE $6 END,
                    active_elapsed_ms = $7,
                    next_decision_local_ts = CASE WHEN $5 THEN $8 ELSE next_decision_local_ts END,
                    last_observation = $9, last_error = $10,
                    last_plugin_seen_at = NOW(), updated_at = NOW()
             WHERE id = 1",
            [
                $pluginState, $revision, $runtimeSerial,
                json_encode($currentAction, JSON_UNESCAPED_SLASHES),
                $terminal, $decisionId, $activeElapsedMs,
                $terminal ? time() + 2 : intval($session['next_decision_local_ts']),
                $reason === '' ? strtolower($outcome) : $reason, $error,
            ]
        );
        if ($terminal) {
            $stepStatus = $outcome === 'COMPLETED' ? 'COMPLETED' : 'CANCELLED';
            $db->exec(
                'UPDATE autonomy_pilot_step SET status = $1, updated_at = NOW() WHERE decision_id = $2',
                [$stepStatus, $decisionId]
            );
        }
        $context = is_array($payload['context_snapshot'] ?? null) ? $payload['context_snapshot'] : [];
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'decision_id' => $decisionId,
            'event_key' => $eventKey,
            'game_ts' => intval($payload['game_ts'] ?? 0),
            'event_type' => $terminal ? 'action_terminal' : 'action_dispatched',
            'state' => $pluginState,
            'command' => strval($decisionRow['command'] ?? ''),
            'arguments' => stobeAutonomyDecodeJsonColumn($decisionRow['arguments'] ?? '{}'),
            'outcome' => strtolower($outcome),
            'reason' => $reason,
            'context_snapshot' => $context,
        ]);
        $db->exec('COMMIT');
        $updatedDecision = $db->fetchOne('SELECT * FROM autonomy_decision WHERE decision_id = $1', [$decisionId]);
        return ['ok' => true, 'status' => 200, 'session' => stobeAutonomyGetSession(),
            'decision' => stobeAutonomyNormalizeDecision($updatedDecision ?: $decisionRow)];
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
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

        $oldRevision = intval($session['control_revision']);
        $db->exec(
            "UPDATE autonomy_decision SET status = 'CANCELLED',
                    outcome_reason = $1, terminal_at = NOW(), updated_at = NOW()
             WHERE session_id = 1 AND control_revision = $2
               AND status IN ('ISSUED', 'DISPATCHED')",
            ['control_' . $action, $oldRevision]
        );
        $db->exec(
            "UPDATE autonomy_pilot_step SET status = 'CANCELLED', updated_at = NOW()
             WHERE session_id = 1 AND control_revision = $1
               AND status IN ('PENDING', 'CLAIMED')",
            [$oldRevision]
        );

        $revision = intval($session['control_revision']) + 1;
        $updated = $db->fetchOne(
            "UPDATE autonomy_session
             SET npc_id = NULLIF($1, 0), npc_storage_id = $2, npc_name = $3,
                 enabled = $4, desired_state = $5, control_revision = $6,
                 stop_mode = $7, policy = $8::jsonb,
                 long_term_directive = $9, active_decision_id = NULL,
                 current_action = '{}'::jsonb, active_elapsed_ms = 0,
                 next_decision_local_ts = 0,
                 last_error = '', updated_at = NOW()
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
    if (strtolower(trim(strval($payload['report_type'] ?? ''))) === 'action' ||
        (trim(strval($payload['decision_id'] ?? '')) !== '' && trim(strval($payload['outcome'] ?? '')) !== '')) {
        return stobeAutonomyApplyActionObservation($payload);
    }
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
         WHERE id = 1
           AND control_revision = $6
           AND COALESCE(npc_id, 0) = $7
           AND npc_storage_id = $8
         RETURNING *",
        [
            $state, $revision, $runtimeSerial, $observation, $error,
            $revision, $reportedNpcId, $reportedStorageId,
        ]
    );
    if (!$updated) {
        $current = stobeAutonomyGetSession();
        if (intval($current['control_revision']) !== $revision) {
            return ['ok' => false, 'status' => 409, 'error' => 'stale_control_revision', 'session' => $current];
        }
        if (intval($current['npc_id']) !== $reportedNpcId ||
            strval($current['npc_storage_id']) !== $reportedStorageId) {
            return ['ok' => false, 'status' => 409, 'error' => 'npc_identity_mismatch', 'session' => $current];
        }
        throw new RuntimeException('Autonomy plugin report update failed: ' . $GLOBALS['db']->GetLastError());
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
