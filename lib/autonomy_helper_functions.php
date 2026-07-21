<?php

/**
 * Autonomy control plane and correlated decision ledger.
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
            planner_mode TEXT NOT NULL DEFAULT 'llm',
            planner_connector_id INT,
            planner_status TEXT NOT NULL DEFAULT 'idle',
            planner_failure_count INT NOT NULL DEFAULT 0,
            planner_backoff_seconds INT NOT NULL DEFAULT 0,
            last_prompt_hash TEXT NOT NULL DEFAULT '',
            last_response_hash TEXT NOT NULL DEFAULT '',
            last_request_latency_ms INT NOT NULL DEFAULT 0,
            planner_prompt_tokens BIGINT NOT NULL DEFAULT 0,
            planner_completion_tokens BIGINT NOT NULL DEFAULT 0,
            planner_decision_count BIGINT NOT NULL DEFAULT 0,
            last_allowlist JSONB NOT NULL DEFAULT '[]'::jsonb,
            last_planner_context_hash TEXT NOT NULL DEFAULT '',
            last_observation TEXT NOT NULL DEFAULT '',
            last_error TEXT NOT NULL DEFAULT '',
            last_plugin_seen_at TIMESTAMP,
            last_plugin_seen_local_ts BIGINT NOT NULL DEFAULT 0,
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
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS last_plugin_seen_local_ts BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_mode TEXT NOT NULL DEFAULT 'llm'",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_connector_id INT",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_status TEXT NOT NULL DEFAULT 'idle'",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_failure_count INT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_backoff_seconds INT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS last_prompt_hash TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS last_response_hash TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS last_request_latency_ms INT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_prompt_tokens BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_completion_tokens BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS planner_decision_count BIGINT NOT NULL DEFAULT 0",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS last_allowlist JSONB NOT NULL DEFAULT '[]'::jsonb",
        "ALTER TABLE autonomy_session ADD COLUMN IF NOT EXISTS last_planner_context_hash TEXT NOT NULL DEFAULT ''",
        "CREATE TABLE IF NOT EXISTS autonomy_decision (
            decision_id TEXT PRIMARY KEY,
            session_id SMALLINT NOT NULL DEFAULT 1,
            control_revision BIGINT NOT NULL,
            npc_id INT NOT NULL,
            npc_storage_id TEXT NOT NULL,
            runtime_serial BIGINT NOT NULL DEFAULT 0,
            command TEXT NOT NULL,
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
            command TEXT NOT NULL CHECK (command IN (
                'IDLE', 'TRAVEL_LOCATION', 'MOVE_NEARBY', 'FLEE',
                'FIRST_AID', 'REST', 'ATTACK', 'TAKE_ITEM', 'EQUIP_ITEM',
                'KNOCKOUT', 'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
                'BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT'
            )),
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
        "CREATE TABLE IF NOT EXISTS autonomy_economy_snapshot (
            id BIGSERIAL PRIMARY KEY,
            game_ts BIGINT NOT NULL DEFAULT 0,
            local_ts BIGINT NOT NULL DEFAULT EXTRACT(EPOCH FROM NOW())::BIGINT,
            x DOUBLE PRECISION NOT NULL DEFAULT 0,
            y DOUBLE PRECISION NOT NULL DEFAULT 0,
            z DOUBLE PRECISION NOT NULL DEFAULT 0,
            location_zone_id BIGINT,
            location_name TEXT NOT NULL DEFAULT '',
            trader_runtime_serial BIGINT NOT NULL,
            trader_name TEXT NOT NULL,
            trader_cats INT NOT NULL DEFAULT 0,
            inventory_hash TEXT NOT NULL,
            inventory JSONB NOT NULL DEFAULT '[]'::jsonb,
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )",
        "CREATE UNIQUE INDEX IF NOT EXISTS idx_autonomy_economy_snapshot_unique
            ON autonomy_economy_snapshot (
                trader_runtime_serial, game_ts, inventory_hash
            )",
        "CREATE INDEX IF NOT EXISTS idx_autonomy_economy_snapshot_trader
            ON autonomy_economy_snapshot (trader_runtime_serial, created_at DESC)",
        "CREATE INDEX IF NOT EXISTS idx_autonomy_economy_snapshot_location
            ON autonomy_economy_snapshot (location_zone_id, created_at DESC)",
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
    $lastPluginTs = max(0, intval($row['last_plugin_seen_local_ts'] ?? 0));
    $lastPluginAge = time() - $lastPluginTs;
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
        'planner_mode' => strtolower(trim(strval($row['planner_mode'] ?? 'llm'))) === 'pilot' ? 'pilot' : 'llm',
        'planner_connector_id' => max(0, intval($row['planner_connector_id'] ?? 0)),
        'planner_status' => trim(strval($row['planner_status'] ?? 'idle')),
        'planner_failure_count' => max(0, intval($row['planner_failure_count'] ?? 0)),
        'planner_backoff_seconds' => max(0, intval($row['planner_backoff_seconds'] ?? 0)),
        'last_prompt_hash' => trim(strval($row['last_prompt_hash'] ?? '')),
        'last_response_hash' => trim(strval($row['last_response_hash'] ?? '')),
        'last_request_latency_ms' => intval($row['last_request_latency_ms'] ?? 0),
        'planner_prompt_tokens' => intval($row['planner_prompt_tokens'] ?? 0),
        'planner_completion_tokens' => intval($row['planner_completion_tokens'] ?? 0),
        'planner_decision_count' => intval($row['planner_decision_count'] ?? 0),
        'last_allowlist' => stobeAutonomyDecodeJsonColumn($row['last_allowlist'] ?? '[]'),
        'last_planner_context_hash' => trim(strval($row['last_planner_context_hash'] ?? '')),
        'active_decision_id' => trim(strval($row['active_decision_id'] ?? '')),
        'last_decision_local_ts' => intval($row['last_decision_local_ts'] ?? 0),
        'next_decision_local_ts' => intval($row['next_decision_local_ts'] ?? 0),
        'active_elapsed_ms' => intval($row['active_elapsed_ms'] ?? 0),
        'last_observation' => strval($row['last_observation'] ?? ''),
        'last_error' => strval($row['last_error'] ?? ''),
        'last_plugin_seen_at' => $lastPluginRaw,
        'last_plugin_seen_ts' => $lastPluginTs,
        'plugin_online' => $lastPluginTs > 0 && $lastPluginAge >= -2 && $lastPluginAge <= 8,
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
    if (!in_array($action, [
        'enqueue_idle', 'enqueue_travel', 'enqueue_move_nearby',
        'enqueue_flee', 'enqueue_first_aid', 'enqueue_rest',
        'enqueue_attack', 'enqueue_take_item', 'enqueue_equip_item',
        'enqueue_knockout', 'enqueue_kill', 'enqueue_remove_limb',
        'enqueue_cut_horns',
        'enqueue_buy_item', 'enqueue_sell_item', 'enqueue_work_resource',
        'enqueue_prospect',
        'cancel_pending',
    ], true)) {
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
        } elseif ($action === 'enqueue_move_nearby') {
            $command = 'MOVE_NEARBY';
            $direction = strtoupper(trim(strval($payload['direction'] ?? 'E')));
            if (!in_array($direction, ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW'], true) ||
                !is_numeric($payload['distance'] ?? null)) {
                $db->exec('ROLLBACK');
                return ['ok' => false, 'status' => 422, 'error' => 'invalid_move_nearby'];
            }
            $arguments = [
                'direction' => $direction,
                'distance' => max(10.0, min(80.0, floatval($payload['distance']))),
            ];
        } elseif ($action === 'enqueue_flee') {
            $command = 'FLEE';
            $arguments = [];
        } elseif ($action === 'enqueue_first_aid') {
            $command = 'FIRST_AID';
            $arguments = ['target' => strval($session['npc_name'] ?? '')];
        } elseif ($action === 'enqueue_rest') {
            $command = 'REST';
            $arguments = [];
        } elseif ($action === 'enqueue_attack') {
            $command = 'ATTACK';
            $arguments = ['target' => trim(strval($payload['target'] ?? ''))];
        } elseif ($action === 'enqueue_take_item') {
            $command = 'TAKE_ITEM';
            $arguments = [
                'target' => trim(strval($payload['target'] ?? '')),
                'item' => trim(strval($payload['item'] ?? '')),
                'amount' => max(1, min(20, intval($payload['amount'] ?? 1))),
            ];
        } elseif ($action === 'enqueue_equip_item') {
            $command = 'EQUIP_ITEM';
            $arguments = ['item' => trim(strval($payload['item'] ?? ''))];
        } elseif ($action === 'enqueue_knockout') {
            $command = 'KNOCKOUT';
            $arguments = ['target' => trim(strval($payload['target'] ?? ''))];
        } elseif ($action === 'enqueue_kill') {
            $command = 'KILL';
            $arguments = ['target' => trim(strval($payload['target'] ?? ''))];
        } elseif ($action === 'enqueue_remove_limb') {
            $command = 'REMOVE_LIMB';
            $arguments = [
                'target' => trim(strval($payload['target'] ?? '')),
                'limb' => strtoupper(trim(strval($payload['limb'] ?? ''))),
            ];
        } elseif ($action === 'enqueue_cut_horns') {
            $command = 'CUT_HORNS';
            $arguments = ['target' => trim(strval($payload['target'] ?? ''))];
        } elseif ($action === 'enqueue_buy_item') {
            $command = 'BUY_ITEM';
            $arguments = [
                'target' => trim(strval($payload['target'] ?? '')),
                'item' => trim(strval($payload['item'] ?? '')),
                'amount' => 1,
                'max_total_price' => max(1, min(10000000, intval($payload['max_total_price'] ?? 5000))),
            ];
        } elseif ($action === 'enqueue_sell_item') {
            $command = 'SELL_ITEM';
            $arguments = [
                'target' => trim(strval($payload['target'] ?? '')),
                'item' => trim(strval($payload['item'] ?? '')),
                'amount' => 1,
                'min_total_price' => max(0, min(10000000, intval($payload['min_total_price'] ?? 1))),
            ];
        } elseif ($action === 'enqueue_work_resource') {
            $command = 'WORK_RESOURCE';
            $arguments = ['resource' => trim(strval($payload['resource'] ?? ''))];
        } elseif ($action === 'enqueue_prospect') {
            $command = 'PROSPECT';
            $arguments = ['resource' => trim(strval($payload['resource'] ?? ''))];
        }

        if (in_array($command, [
            'ATTACK', 'TAKE_ITEM', 'KNOCKOUT', 'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
            'BUY_ITEM', 'SELL_ITEM',
        ], true) && trim(strval($arguments['target'] ?? '')) === '') {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 422, 'error' => 'pilot_target_required'];
        }
        if (in_array($command, ['TAKE_ITEM', 'EQUIP_ITEM', 'BUY_ITEM', 'SELL_ITEM'], true) &&
            trim(strval($arguments['item'] ?? '')) === '') {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 422, 'error' => 'pilot_item_required'];
        }
        if (in_array($command, ['WORK_RESOURCE', 'PROSPECT'], true) &&
            trim(strval($arguments['resource'] ?? '')) === '') {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 422, 'error' => 'pilot_resource_required'];
        }
        if ($command === 'REMOVE_LIMB' && !in_array(strval($arguments['limb'] ?? ''), [
            'LEFT_ARM', 'RIGHT_ARM', 'LEFT_LEG', 'RIGHT_LEG',
        ], true)) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 422, 'error' => 'pilot_limb_invalid'];
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
        $db->exec("UPDATE autonomy_session SET planner_mode = 'pilot', planner_status = 'pilot', updated_at = NOW() WHERE id = 1");
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
        'runtime_serial' => max(0, intval($payload['runtime_serial'] ?? 0)),
        'snapshot_sequence' => $snapshotSequence,
        'snapshot_local_ts' => $snapshotLocalTs,
        'game_ts' => max(0, intval($payload['game_ts'] ?? 0)),
        'position' => ['x' => $x, 'y' => $y, 'z' => $z],
        'status' => [
            'player_character' => stobeAutonomyBool($payload['status']['player_character'] ?? false),
            'dead' => stobeAutonomyBool($payload['status']['dead'] ?? false),
            'unconscious' => stobeAutonomyBool($payload['status']['unconscious'] ?? false),
            'can_take_orders' => stobeAutonomyBool($payload['status']['can_take_orders'] ?? false),
            'has_player_orders' => stobeAutonomyBool($payload['status']['has_player_orders'] ?? false),
            'carrying' => stobeAutonomyBool($payload['status']['carrying'] ?? false),
            'carried_serial' => max(0, intval($payload['status']['carried_serial'] ?? 0)),
            'in_bed' => stobeAutonomyBool($payload['status']['in_bed'] ?? false),
            'fully_rested' => stobeAutonomyBool($payload['status']['fully_rested'] ?? true),
            'probably_dying' => stobeAutonomyBool($payload['status']['probably_dying'] ?? false),
            'rest_bed_available' => stobeAutonomyBool($payload['status']['rest_bed_available'] ?? false),
            'in_combat' => stobeAutonomyBool($payload['status']['in_combat'] ?? false),
        ],
        'economy' => stobeAutonomyNormalizeEconomy($payload['economy'] ?? []),
        'health' => stobeAutonomyNormalizeHealth($payload['health'] ?? []),
        'order' => is_array($payload['order'] ?? null) ? array_intersect_key($payload['order'], array_flip(['count', 'task', 'subject_serial'])) : [],
        'movement' => is_array($payload['movement'] ?? null) ? array_intersect_key($payload['movement'], array_flip(['moving', 'path_failed'])) : [],
        'nearby_actors' => stobeAutonomyNormalizeNearbyActors($payload['nearby_actors'] ?? []),
        'inventory_items' => stobeAutonomyNormalizeInventoryItems($payload['inventory_items'] ?? []),
        'nearby_resources' => stobeAutonomyNormalizeNearbyResources($payload['nearby_resources'] ?? []),
        'context_hash' => $contextHash,
    ];
}

function stobeAutonomyDecisionProtocolPhase(string $command): int
{
    $command = strtoupper(trim($command));
    if (in_array($command, [
        'BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT',
    ], true)) {
        return 6;
    }
    if (in_array($command, [
        'ATTACK', 'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT',
        'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
    ], true)) {
        return 5;
    }
    return in_array($command, ['MOVE_NEARBY', 'FLEE', 'FIRST_AID', 'REST'], true) ? 4 : 2;
}

function stobeAutonomyNormalizeEconomy(mixed $value): array
{
    $economy = is_array($value) ? $value : [];
    return [
        'cats' => max(0, min(1000000000, intval($economy['cats'] ?? 0))),
        'inventory_item_count' => max(0, min(100000, intval($economy['inventory_item_count'] ?? 0))),
    ];
}

function stobeAutonomyNormalizeInventoryItems(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach (array_slice($value, 0, 120) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim(strval($item['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            continue;
        }
        $result[] = [
            'name' => $name,
            'count' => max(0, min(10000, intval($item['count'] ?? 0))),
            'buy_value_each' => max(0, min(10000000, intval($item['buy_value_each'] ?? 0))),
            'sell_value_each' => max(0, min(10000000, intval($item['sell_value_each'] ?? 0))),
        ];
    }
    return $result;
}

function stobeAutonomyNormalizeNearbyResources(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach (array_slice($value, 0, 40) as $resource) {
        if (!is_array($resource)) {
            continue;
        }
        $name = trim(strval($resource['name'] ?? ''));
        $serial = max(0, intval($resource['runtime_serial'] ?? 0));
        $distance = is_numeric($resource['distance'] ?? null) ? floatval($resource['distance']) : -1.0;
        if ($name === '' || mb_strlen($name) > 160 || $serial <= 0 ||
            !is_finite($distance) || $distance < 0 || $distance > 250.0) {
            continue;
        }
        $result[] = [
            'name' => $name,
            'runtime_serial' => $serial,
            'distance' => $distance,
            'natural' => stobeAutonomyBool($resource['natural'] ?? false),
            'usable' => stobeAutonomyBool($resource['usable'] ?? false),
            'task' => intval($resource['task'] ?? 0),
            'x' => is_numeric($resource['x'] ?? null) ? floatval($resource['x']) : 0.0,
            'y' => is_numeric($resource['y'] ?? null) ? floatval($resource['y']) : 0.0,
            'z' => is_numeric($resource['z'] ?? null) ? floatval($resource['z']) : 0.0,
        ];
    }
    return $result;
}

function stobeAutonomyNormalizeHealth(mixed $value): array
{
    $health = is_array($value) ? $value : [];
    $result = [];
    foreach (['overall', 'blood', 'max_blood', 'bleed_rate', 'first_aid_need', 'robotic_aid_need'] as $field) {
        $number = is_numeric($health[$field] ?? null) ? floatval($health[$field]) : 0.0;
        $result[$field] = is_finite($number) ? max(-100000.0, min(100000.0, $number)) : 0.0;
    }
    return $result;
}

function stobeAutonomyNormalizeNearbyActors(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $result = [];
    foreach (array_slice($value, 0, 40) as $actor) {
        if (!is_array($actor)) {
            continue;
        }
        $name = trim(strval($actor['name'] ?? ''));
        $serial = max(0, intval($actor['runtime_serial'] ?? 0));
        $distance = is_numeric($actor['distance'] ?? null) ? floatval($actor['distance']) : -1.0;
        if ($name === '' || mb_strlen($name) > 120 || $serial <= 0 || !is_finite($distance) || $distance < 0 || $distance > 250.0) {
            continue;
        }
        $result[] = [
            'name' => $name,
            'runtime_serial' => $serial,
            'distance' => $distance,
            'player_character' => stobeAutonomyBool($actor['player_character'] ?? false),
            'trader' => stobeAutonomyBool($actor['trader'] ?? false),
            'cats' => max(0, min(1000000000, intval($actor['cats'] ?? 0))),
            'dead' => stobeAutonomyBool($actor['dead'] ?? false),
            'unconscious' => stobeAutonomyBool($actor['unconscious'] ?? false),
            'hostile' => stobeAutonomyBool($actor['hostile'] ?? false),
            'x' => is_numeric($actor['x'] ?? null) ? floatval($actor['x']) : 0.0,
            'y' => is_numeric($actor['y'] ?? null) ? floatval($actor['y']) : 0.0,
            'z' => is_numeric($actor['z'] ?? null) ? floatval($actor['z']) : 0.0,
            'overall_health' => is_numeric($actor['overall_health'] ?? null) ? floatval($actor['overall_health']) : 0.0,
            'bleed_rate' => is_numeric($actor['bleed_rate'] ?? null) ? floatval($actor['bleed_rate']) : 0.0,
            'first_aid_need' => is_numeric($actor['first_aid_need'] ?? null) ? floatval($actor['first_aid_need']) : 0.0,
            'robotic_aid_need' => is_numeric($actor['robotic_aid_need'] ?? null) ? floatval($actor['robotic_aid_need']) : 0.0,
            'trader_items' => stobeAutonomyNormalizeInventoryItems($actor['trader_items'] ?? []),
        ];
    }
    return $result;
}

function stobeAutonomyPersistEconomySnapshot(array $snapshot): void
{
    $position = is_array($snapshot['position'] ?? null) ? $snapshot['position'] : [];
    $locations = stobeAutonomyListVisitedLocations(200);
    $nearest = false;
    $nearestDistance = PHP_FLOAT_MAX;
    foreach ($locations as $location) {
        if (!isset($location['x'], $location['y'], $location['z'])) {
            continue;
        }
        $dx = floatval($position['x'] ?? 0) - floatval($location['x']);
        $dy = floatval($position['y'] ?? 0) - floatval($location['y']);
        $dz = floatval($position['z'] ?? 0) - floatval($location['z']);
        $distance = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
        if ($distance < $nearestDistance) {
            $nearestDistance = $distance;
            $nearest = $location;
        }
    }
    $locationId = $nearest && $nearestDistance <= 750.0 ? intval($nearest['id'] ?? 0) : 0;
    $locationName = '';
    if ($locationId > 0) {
        $locationName = trim(strval($nearest['city_name'] ?? '')) !== ''
            ? strval($nearest['city_name']) : strval($nearest['zone_name'] ?? '');
    }
    foreach (($snapshot['nearby_actors'] ?? []) as $actor) {
        if (!is_array($actor) || !stobeAutonomyBool($actor['trader'] ?? false)) {
            continue;
        }
        $items = stobeAutonomyNormalizeInventoryItems($actor['trader_items'] ?? []);
        if (count($items) === 0) {
            continue;
        }
        $serial = max(0, intval($actor['runtime_serial'] ?? 0));
        $name = trim(strval($actor['name'] ?? ''));
        if ($serial <= 0 || $name === '') {
            continue;
        }
        $inventoryJson = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $inventoryHash = hash('sha256', strval($inventoryJson));
        $recent = $GLOBALS['db']->fetchOne(
            "SELECT inventory_hash, local_ts FROM autonomy_economy_snapshot
             WHERE trader_runtime_serial = $1 ORDER BY created_at DESC LIMIT 1",
            [$serial]
        );
        if ($recent && strval($recent['inventory_hash'] ?? '') === $inventoryHash &&
            time() - intval($recent['local_ts'] ?? 0) < 300) {
            continue;
        }
        $GLOBALS['db']->exec(
            "INSERT INTO autonomy_economy_snapshot (
                game_ts, local_ts, x, y, z, location_zone_id, location_name,
                trader_runtime_serial, trader_name, trader_cats,
                inventory_hash, inventory
             ) VALUES ($1, $2, $3, $4, $5, NULLIF($6, 0), $7, $8, $9, $10, $11, $12::jsonb)
             ON CONFLICT DO NOTHING",
            [
                intval($snapshot['game_ts'] ?? 0), time(),
                floatval($position['x'] ?? 0), floatval($position['y'] ?? 0),
                floatval($position['z'] ?? 0), $locationId, $locationName,
                $serial, $name, intval($actor['cats'] ?? 0),
                $inventoryHash, $inventoryJson,
            ]
        );
    }
}

function stobeAutonomyEconomySummary(int $limit = 12): array
{
    $rows = $GLOBALS['db']->fetchAll(
        "SELECT trader_runtime_serial, trader_name, trader_cats, location_name,
                game_ts, local_ts, inventory
         FROM autonomy_economy_snapshot
         ORDER BY created_at DESC LIMIT 120"
    );
    $byTrader = [];
    foreach ($rows as $row) {
        $serial = intval($row['trader_runtime_serial'] ?? 0);
        if ($serial <= 0 || count($byTrader[$serial] ?? []) >= 2) {
            continue;
        }
        $row['inventory'] = stobeAutonomyDecodeJsonColumn($row['inventory'] ?? '[]');
        $byTrader[$serial][] = $row;
    }
    $markets = [];
    foreach ($byTrader as $history) {
        $latest = $history[0];
        $previous = $history[1] ?? null;
        $previousItems = [];
        $previousItemNames = [];
        foreach (($previous['inventory'] ?? []) as $item) {
            $name = strval($item['name'] ?? '');
            $key = strtolower($name);
            $previousItems[$key] = intval($item['count'] ?? 0);
            $previousItemNames[$key] = $name;
        }
        $shortages = [];
        $surpluses = [];
        $latestItems = [];
        foreach (($latest['inventory'] ?? []) as $item) {
            $name = strval($item['name'] ?? '');
            $key = strtolower($name);
            $count = intval($item['count'] ?? 0);
            $latestItems[$key] = true;
            $old = $previousItems[$key] ?? $count;
            if ($count < $old) {
                $shortages[] = ['item' => $name, 'count' => $count, 'change' => $count - $old];
            } elseif ($count > $old) {
                $surpluses[] = ['item' => $name, 'count' => $count, 'change' => $count - $old];
            }
        }
        foreach ($previousItems as $key => $old) {
            if ($old > 0 && !isset($latestItems[$key])) {
                $shortages[] = [
                    'item' => strval($previousItemNames[$key] ?? $key),
                    'count' => 0,
                    'change' => -$old,
                ];
            }
        }
        $markets[] = [
            'trader' => strval($latest['trader_name'] ?? ''),
            'location' => strval($latest['location_name'] ?? ''),
            'cats' => intval($latest['trader_cats'] ?? 0),
            'observed_game_ts' => intval($latest['game_ts'] ?? 0),
            'shortages' => array_slice($shortages, 0, 8),
            'surpluses' => array_slice($surpluses, 0, 8),
        ];
        if (count($markets) >= max(1, min(30, $limit))) {
            break;
        }
    }
    return $markets;
}

function stobeAutonomyPlannerNoDecision(array $session, string $reason): array
{
    return [
        'ok' => true,
        'status' => 200,
        'phase' => 5,
        'session' => $session,
        'decision' => null,
        'action' => null,
        'reason' => $reason,
    ];
}

function stobeAutonomyApplyPlannerTick(array $payload, array $snapshot): array
{
    $db = $GLOBALS['db'];
    $session = stobeAutonomyGetSession();
    $revision = intval($payload['control_revision'] ?? -1);
    $npcId = intval($payload['npc_id'] ?? 0);
    $storageId = trim(strval($payload['npc_storage_id'] ?? ''));
    if ($revision !== intval($session['control_revision'])) {
        return ['ok' => false, 'status' => 409, 'error' => 'stale_control_revision', 'session' => $session];
    }
    if ($npcId !== intval($session['npc_id']) || $storageId !== $session['npc_storage_id']) {
        return ['ok' => false, 'status' => 409, 'error' => 'npc_identity_mismatch', 'session' => $session];
    }
    if (!$session['enabled'] || !in_array($session['plugin_state'], ['OBSERVING', 'COOLDOWN', 'DECIDING'], true)) {
        return stobeAutonomyPlannerNoDecision($session, 'controller_not_observing');
    }
    $open = $db->fetchOne(
        "SELECT * FROM autonomy_decision
         WHERE session_id = 1 AND status IN ('ISSUED', 'DISPATCHED')
         ORDER BY issued_at DESC LIMIT 1"
    );
    if ($open) {
        $decision = stobeAutonomyNormalizeDecision($open);
        return ['ok' => true, 'status' => 200,
            'phase' => stobeAutonomyDecisionProtocolPhase(strval($decision['command'] ?? '')),
            'session' => $session,
            'decision' => $decision, 'action' => $decision];
    }
    if (intval($session['next_decision_local_ts']) > time()) {
        return stobeAutonomyPlannerNoDecision($session, 'decision_cooldown');
    }

    $policy = stobeAutonomyPlannerPolicy($session);
    $hourly = $db->fetchOne(
        "SELECT COUNT(*) AS total FROM autonomy_event
         WHERE event_type IN ('planner_response', 'decision_issued')
           AND prompt_hash <> '' AND created_at >= NOW() - INTERVAL '1 hour'"
    );
    if (intval($hourly['total'] ?? 0) >= intval($policy['max_decisions_per_hour'])) {
        $db->exec(
            "UPDATE autonomy_session SET planner_status = 'rate_limited',
                    next_decision_local_ts = $1, updated_at = NOW() WHERE id = 1",
            [time() + 60]
        );
        return stobeAutonomyPlannerNoDecision(stobeAutonomyGetSession(), 'planner_hourly_limit');
    }

    $npc = getNpcById($npcId);
    if (!$npc) {
        return ['ok' => false, 'status' => 409, 'error' => 'selected_npc_invalid', 'session' => $session];
    }
    $allowlist = stobeAutonomyPlannerBuildAllowlist($session, $snapshot, $npc);
    $db->exec(
        "UPDATE autonomy_session SET last_allowlist = $1::jsonb,
                last_planner_context_hash = $2, planner_status = 'deciding', updated_at = NOW()
         WHERE id = 1 AND control_revision = $3",
        [json_encode($allowlist, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $snapshot['context_hash'], $revision]
    );
    if (count($allowlist) === 0) {
        $db->exec(
            "UPDATE autonomy_session SET planner_status = 'no_available_actions',
                    next_decision_local_ts = $1, updated_at = NOW() WHERE id = 1",
            [time() + intval($policy['minimum_interval_seconds'])]
        );
        return stobeAutonomyPlannerNoDecision(stobeAutonomyGetSession(), 'no_available_actions');
    }
    $context = stobeAutonomyPlannerContext($session, $snapshot, $allowlist);
    $auditContext = stobeAutonomyPlannerAuditContext($session, $snapshot, $allowlist);
    $GLOBALS['stobe_autonomy_npc_name'] = strval($npc['name'] ?? '');
    try {
        $planner = stobeAutonomyPlannerCall($context, $npc, $session);
        $proposal = stobeAutonomyPlannerNormalizeProposal($planner['decoded'], $allowlist);
    } catch (Throwable $exception) {
        $reason = mb_substr(trim($exception->getMessage()), 0, 500);
        $failureCount = max(1, intval($session['planner_failure_count'] ?? 0) + 1);
        $backoffSeconds = stobeAutonomyPlannerBackoffSeconds(
            $failureCount,
            intval($policy['minimum_interval_seconds'])
        );
        $db->exec(
            "UPDATE autonomy_session SET planner_status = 'error', last_error = $1,
                    planner_failure_count = $2, planner_backoff_seconds = $3,
                    next_decision_local_ts = $4, updated_at = NOW() WHERE id = 1
                    AND control_revision = $5",
            [$reason, $failureCount, $backoffSeconds, time() + $backoffSeconds, $revision]
        );
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'event_key' => 'planner-error:' . $revision . ':' . intval($snapshot['snapshot_sequence']),
            'game_ts' => intval($snapshot['game_ts']),
            'event_type' => 'planner_error',
            'state' => 'OBSERVING',
            'outcome' => 'no_action',
            'reason' => $reason,
            'context_snapshot' => $auditContext + [
                'failure_count' => $failureCount,
                'backoff_seconds' => $backoffSeconds,
            ],
        ]);
        return stobeAutonomyPlannerNoDecision(stobeAutonomyGetSession(), $reason);
    }

    $nextDecisionTs = time() + intval($policy['minimum_interval_seconds']);
    if ($proposal['decision'] === null) {
        $db->exec(
            "UPDATE autonomy_session SET current_goal = $1::jsonb,
                    planner_status = 'waiting', last_prompt_hash = $2,
                    last_response_hash = $3, last_request_latency_ms = $4,
                    planner_prompt_tokens = planner_prompt_tokens + $5,
                    planner_completion_tokens = planner_completion_tokens + $6,
                    planner_decision_count = planner_decision_count + 1,
                    planner_failure_count = 0, planner_backoff_seconds = 0,
                    last_decision_local_ts = EXTRACT(EPOCH FROM NOW())::BIGINT,
                    next_decision_local_ts = $7, last_error = '', updated_at = NOW()
             WHERE id = 1 AND control_revision = $8",
            [json_encode($proposal['goal'], JSON_UNESCAPED_SLASHES), $planner['prompt_hash'],
                $planner['response_hash'], $planner['latency_ms'], $planner['prompt_tokens'],
                $planner['completion_tokens'], $nextDecisionTs, $revision]
        );
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'event_key' => 'planner:' . $revision . ':' . intval($snapshot['snapshot_sequence']),
            'game_ts' => intval($snapshot['game_ts']),
            'event_type' => 'planner_response',
            'state' => 'OBSERVING',
            'goal' => $proposal['goal'],
            'outcome' => 'wait',
            'reason' => $proposal['reason'],
            'context_snapshot' => $auditContext,
            'prompt_hash' => $planner['prompt_hash'],
            'response_hash' => $planner['response_hash'],
            'request_latency_ms' => $planner['latency_ms'],
        ]);
        return stobeAutonomyPlannerNoDecision(stobeAutonomyGetSession(), 'planner_wait');
    }

    $command = strval($proposal['decision']['command']);
    $arguments = $proposal['decision']['arguments'];
    $duplicate = false;
    if (stobeAutonomyPlannerShouldSuppressCompletedDuplicate($command)) {
        $duplicate = $db->fetchOne(
            "SELECT decision_id FROM autonomy_decision
             WHERE session_id = 1 AND control_revision = $1 AND command = $2
               AND status = 'COMPLETED' AND arguments = $3::jsonb
             ORDER BY terminal_at DESC LIMIT 1",
            [$revision, $command, json_encode($arguments, JSON_UNESCAPED_SLASHES)]
        );
    }
    if ($duplicate) {
        $reason = 'Identical completed action suppressed: ' . $command;
        $db->exec(
            "UPDATE autonomy_session SET current_goal = $1::jsonb,
                    planner_status = 'duplicate_suppressed', last_prompt_hash = $2,
                    last_response_hash = $3, last_request_latency_ms = $4,
                    planner_prompt_tokens = planner_prompt_tokens + $5,
                    planner_completion_tokens = planner_completion_tokens + $6,
                    planner_decision_count = planner_decision_count + 1,
                    planner_failure_count = 0, planner_backoff_seconds = 0,
                    last_decision_local_ts = EXTRACT(EPOCH FROM NOW())::BIGINT,
                    next_decision_local_ts = $7, last_error = '', updated_at = NOW()
             WHERE id = 1 AND control_revision = $8",
            [json_encode($proposal['goal'], JSON_UNESCAPED_SLASHES), $planner['prompt_hash'],
                $planner['response_hash'], $planner['latency_ms'], $planner['prompt_tokens'],
                $planner['completion_tokens'], $nextDecisionTs, $revision]
        );
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'event_key' => 'planner-duplicate:' . $revision . ':' . intval($snapshot['snapshot_sequence']),
            'game_ts' => intval($snapshot['game_ts']),
            'event_type' => 'planner_response',
            'state' => 'OBSERVING',
            'goal' => $proposal['goal'],
            'command' => $command,
            'arguments' => $arguments,
            'outcome' => 'duplicate_suppressed',
            'reason' => $reason,
            'context_snapshot' => $auditContext + [
                'duplicate_decision_id' => strval($duplicate['decision_id'] ?? ''),
            ],
            'prompt_hash' => $planner['prompt_hash'],
            'response_hash' => $planner['response_hash'],
            'request_latency_ms' => $planner['latency_ms'],
        ]);
        return stobeAutonomyPlannerNoDecision(stobeAutonomyGetSession(), 'duplicate_action_suppressed');
    }
    $db->exec('BEGIN');
    try {
        $locked = stobeAutonomyGetSession(true);
        if (intval($locked['control_revision']) !== $revision || !$locked['enabled'] ||
            intval($locked['npc_id']) !== $npcId || $locked['npc_storage_id'] !== $storageId) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 409, 'error' => 'planner_context_became_stale', 'session' => $locked];
        }
        $open = $db->fetchOne(
            "SELECT decision_id FROM autonomy_decision
             WHERE session_id = 1 AND status IN ('ISSUED', 'DISPATCHED') LIMIT 1 FOR UPDATE"
        );
        if ($open) {
            $db->exec('ROLLBACK');
            return stobeAutonomyPlannerNoDecision(stobeAutonomyGetSession(), 'decision_already_open');
        }
        $decisionId = stobeAutonomyUuid4();
        $now = time();
        $actionSeconds = match ($command) {
            'TRAVEL_LOCATION' => 900,
            'REST' => 600,
            'FIRST_AID' => 180,
            'FLEE' => 90,
            'MOVE_NEARBY' => 60,
            'IDLE' => 20,
            default => 120,
        };
        $decisionRow = $db->fetchOne(
            "INSERT INTO autonomy_decision (
                decision_id, session_id, control_revision, npc_id,
                npc_storage_id, runtime_serial, command, arguments,
                context_hash, context_game_ts, status,
                dispatch_deadline_at, action_deadline_at
             ) VALUES ($1, 1, $2, $3, $4, $5, $6, $7::jsonb, $8, $9,
                       'ISSUED', $10::timestamp, $11::timestamp) RETURNING *",
            [$decisionId, $revision, $npcId, $storageId, max(0, intval($payload['runtime_serial'] ?? 0)),
                $command, json_encode($arguments, JSON_UNESCAPED_SLASHES),
                $snapshot['context_hash'], $snapshot['game_ts'],
                gmdate('Y-m-d H:i:s', $now + 10), gmdate('Y-m-d H:i:s', $now + $actionSeconds)]
        );
        if (!$decisionRow) {
            throw new RuntimeException('planner_decision_insert_failed: ' . $db->GetLastError());
        }
        $currentAction = ['decision_id' => $decisionId, 'command' => $command,
            'arguments' => $arguments, 'status' => 'ISSUED', 'reason' => $proposal['reason']];
        $db->exec(
            "UPDATE autonomy_session SET active_decision_id = $1,
                    current_goal = $2::jsonb, current_action = $3::jsonb,
                    planner_status = 'action_issued', last_prompt_hash = $4,
                    last_response_hash = $5, last_request_latency_ms = $6,
                    planner_prompt_tokens = planner_prompt_tokens + $7,
                    planner_completion_tokens = planner_completion_tokens + $8,
                    planner_decision_count = planner_decision_count + 1,
                    planner_failure_count = 0, planner_backoff_seconds = 0,
                    last_decision_local_ts = EXTRACT(EPOCH FROM NOW())::BIGINT,
                    next_decision_local_ts = $9, active_elapsed_ms = 0,
                    last_error = '', updated_at = NOW() WHERE id = 1",
            [$decisionId, json_encode($proposal['goal'], JSON_UNESCAPED_SLASHES),
                json_encode($currentAction, JSON_UNESCAPED_SLASHES), $planner['prompt_hash'],
                $planner['response_hash'], $planner['latency_ms'], $planner['prompt_tokens'],
                $planner['completion_tokens'], $nextDecisionTs]
        );
        stobeAutonomyRecordEvent([
            'control_revision' => $revision,
            'decision_id' => $decisionId,
            'event_key' => 'decision:' . $decisionId . ':issued',
            'game_ts' => intval($snapshot['game_ts']),
            'event_type' => 'decision_issued',
            'state' => 'ACTION_QUEUED',
            'goal' => $proposal['goal'],
            'command' => $command,
            'arguments' => $arguments,
            'outcome' => 'issued',
            'reason' => $proposal['reason'],
            'context_snapshot' => $auditContext,
            'prompt_hash' => $planner['prompt_hash'],
            'response_hash' => $planner['response_hash'],
            'request_latency_ms' => $planner['latency_ms'],
        ]);
        $db->exec('COMMIT');
        $decision = stobeAutonomyNormalizeDecision($decisionRow);
        return ['ok' => true, 'status' => 200,
            'phase' => stobeAutonomyDecisionProtocolPhase($command),
            'session' => stobeAutonomyGetSession(), 'decision' => $decision, 'action' => $decision];
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
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
    stobeAutonomyPersistEconomySnapshot($snapshot);
    $modeSession = stobeAutonomyGetSession();
    if (strval($modeSession['planner_mode'] ?? 'llm') === 'llm') {
        return stobeAutonomyApplyPlannerTick($payload, $snapshot);
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
            return ['ok' => true, 'status' => 200,
                'phase' => stobeAutonomyDecisionProtocolPhase(strval($decision['command'] ?? '')),
                'session' => $session,
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
        if (!in_array($command, [
            'IDLE', 'TRAVEL_LOCATION', 'MOVE_NEARBY', 'FLEE',
            'FIRST_AID', 'REST', 'ATTACK', 'TAKE_ITEM', 'EQUIP_ITEM',
            'KNOCKOUT', 'KILL', 'REMOVE_LIMB', 'CUT_HORNS',
            'BUY_ITEM', 'SELL_ITEM', 'WORK_RESOURCE', 'PROSPECT',
        ], true)) {
            throw new RuntimeException('Pilot step contains an unsupported command.');
        }
        if ($command === 'TRAVEL_LOCATION') {
            foreach (['x', 'y', 'z'] as $coordinateName) {
                if (stobeAutonomyCoordinate($arguments[$coordinateName] ?? null) === false) {
                    throw new RuntimeException('Pilot travel step contains invalid coordinates.');
                }
            }
        }
        if (stobeAutonomyDecisionProtocolPhase($command) >= 4) {
            $npc = getNpcById($npcId);
            if (!$npc) {
                throw new RuntimeException('Pilot NPC no longer exists.');
            }
            $allowlist = stobeAutonomyPlannerBuildAllowlist($session, $snapshot, $npc);
            try {
                $proposal = stobeAutonomyPlannerNormalizeProposal([
                    'goal' => ['summary' => 'Manual autonomy smoke test', 'status' => 'active'],
                    'decision' => ['command' => $command] + $arguments,
                    'reason' => 'Queued from the deterministic pilot UI.',
                ], $allowlist);
                $arguments = $proposal['decision']['arguments'];
            } catch (InvalidArgumentException $exception) {
                $db->exec('COMMIT');
                return [
                    'ok' => true,
                    'status' => 200,
                    'phase' => stobeAutonomyDecisionProtocolPhase($command),
                    'session' => $session,
                    'decision' => null,
                    'action' => null,
                    'reason' => 'pilot_precondition_not_met',
                    'detail' => $exception->getMessage(),
                ];
            }
        }

        $decisionId = stobeAutonomyUuid4();
        $now = time();
        $dispatchDeadline = gmdate('Y-m-d H:i:s', $now + 10);
        $actionSeconds = match ($command) {
            'IDLE' => 20,
            'MOVE_NEARBY' => 60,
            'FLEE' => 90,
            'FIRST_AID' => 180,
            'REST' => 600,
            'ATTACK' => 180,
            'TAKE_ITEM', 'EQUIP_ITEM', 'KNOCKOUT', 'KILL',
            'REMOVE_LIMB', 'CUT_HORNS', 'BUY_ITEM', 'SELL_ITEM' => 30,
            'WORK_RESOURCE' => 90,
            'PROSPECT' => 45,
            default => 900,
        };
        $actionDeadline = gmdate('Y-m-d H:i:s', $now + $actionSeconds);
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
        return ['ok' => true, 'status' => 200,
            'phase' => stobeAutonomyDecisionProtocolPhase($command),
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
        $terminalNextDecisionTs = intval($session['next_decision_local_ts'] ?? 0);
        if ($terminal) {
            if (strval($session['planner_mode'] ?? 'llm') === 'llm') {
                $policy = stobeAutonomyPlannerPolicy($session);
                $minimumNextDecisionTs = intval($session['last_decision_local_ts'] ?? 0)
                    + intval($policy['minimum_interval_seconds']);
                $terminalNextDecisionTs = max(time(), $terminalNextDecisionTs, $minimumNextDecisionTs);
            } else {
                $terminalNextDecisionTs = time() + 2;
            }
        }
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
                    last_plugin_seen_at = NOW(),
                    last_plugin_seen_local_ts = EXTRACT(EPOCH FROM clock_timestamp())::BIGINT,
                    updated_at = NOW()
             WHERE id = 1",
            [
                $pluginState, $revision, $runtimeSerial,
                json_encode($currentAction, JSON_UNESCAPED_SLASHES),
                $terminal, $decisionId, $activeElapsedMs,
                $terminalNextDecisionTs,
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
        $plannerMode = strtolower(trim(strval($payload['planner_mode'] ?? ($session['planner_mode'] ?? 'llm')))) === 'pilot'
            ? 'pilot'
            : 'llm';
        $plannerConnectorId = array_key_exists('planner_connector_id', $payload)
            ? max(0, intval($payload['planner_connector_id']))
            : max(0, intval($session['planner_connector_id'] ?? 0));
        if ($plannerConnectorId > 0 && !getLlmConnectorById($plannerConnectorId)) {
            $db->exec('ROLLBACK');
            return ['ok' => false, 'status' => 422, 'error' => 'planner_connector_invalid'];
        }

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
                  long_term_directive = $9, planner_mode = $10,
                  planner_connector_id = NULLIF($11, 0),
                  planner_status = 'idle', planner_failure_count = 0,
                  planner_backoff_seconds = 0, active_decision_id = NULL,
                 current_action = '{}'::jsonb, active_elapsed_ms = 0,
                 next_decision_local_ts = 0,
                 last_error = '', updated_at = NOW()
             WHERE id = 1 RETURNING *",
            [
                $npcId, $npcStorageId, $npcName, $enabled, $desiredState, $revision,
                $stopMode, json_encode($policy, JSON_UNESCAPED_SLASHES), $directive, $plannerMode,
                $plannerConnectorId,
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
             last_plugin_seen_at = NOW(),
             last_plugin_seen_local_ts = EXTRACT(EPOCH FROM clock_timestamp())::BIGINT,
             updated_at = NOW()
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
