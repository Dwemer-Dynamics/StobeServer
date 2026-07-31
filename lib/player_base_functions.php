<?php

function stobeNormalizePlayerBaseDetails(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        $value = [];
    }

    $clipInt = static fn (mixed $candidate): int => max(0, min(1000000000, intval($candidate)));
    $securityInput = is_array($value['security'] ?? null) ? $value['security'] : [];
    $infrastructureInput = is_array($value['infrastructure'] ?? null) ? $value['infrastructure'] : [];
    $suppliesInput = is_array($value['supplies'] ?? null) ? $value['supplies'] : [];
    $productionInput = is_array($value['production'] ?? null) ? $value['production'] : [];
    $farmsInput = is_array($value['farms'] ?? null) ? $value['farms'] : [];
    $alarmState = strtolower(trim(strval($securityInput['alarm_state'] ?? 'none')));
    if (!in_array($alarmState, ['none', 'intruder', 'escape', 'attack'], true)) {
        $alarmState = 'none';
    }

    return [
        'available' => coerceBoolean($value['available'] ?? false),
        'scan_truncated' => coerceBoolean($value['scan_truncated'] ?? false),
        'security' => [
            'alarm_state' => $alarmState,
            'hostiles_inside' => $clipInt($securityInput['hostiles_inside'] ?? 0),
            'gates_total' => $clipInt($securityInput['gates_total'] ?? 0),
            'damaged_defenses' => $clipInt($securityInput['damaged_defenses'] ?? 0),
            'destroyed_defenses' => $clipInt($securityInput['destroyed_defenses'] ?? 0),
            'turrets_total' => $clipInt($securityInput['turrets_total'] ?? 0),
            'turrets_manned' => $clipInt($securityInput['turrets_manned'] ?? 0),
            'turrets_unpowered' => $clipInt($securityInput['turrets_unpowered'] ?? 0),
        ],
        'infrastructure' => [
            'total' => $clipInt($infrastructureInput['total'] ?? 0),
            'storage' => $clipInt($infrastructureInput['storage'] ?? 0),
            'production' => $clipInt($infrastructureInput['production'] ?? 0),
            'farms' => $clipInt($infrastructureInput['farms'] ?? 0),
            'research' => $clipInt($infrastructureInput['research'] ?? 0),
            'generators' => $clipInt($infrastructureInput['generators'] ?? 0),
            'batteries' => $clipInt($infrastructureInput['batteries'] ?? 0),
            'beds' => $clipInt($infrastructureInput['beds'] ?? 0),
            'cages' => $clipInt($infrastructureInput['cages'] ?? 0),
            'damaged' => $clipInt($infrastructureInput['damaged'] ?? 0),
            'destroyed' => $clipInt($infrastructureInput['destroyed'] ?? 0),
            'broken' => $clipInt($infrastructureInput['broken'] ?? 0),
            'unpowered' => $clipInt($infrastructureInput['unpowered'] ?? 0),
        ],
        'supplies' => [
            'food' => $clipInt($suppliesInput['food'] ?? 0),
            'medicine' => $clipInt($suppliesInput['medicine'] ?? 0),
            'building_materials' => $clipInt($suppliesInput['building_materials'] ?? 0),
            'iron_plates' => $clipInt($suppliesInput['iron_plates'] ?? 0),
            'fuel' => $clipInt($suppliesInput['fuel'] ?? 0),
            'water' => $clipInt($suppliesInput['water'] ?? 0),
            'ammunition' => $clipInt($suppliesInput['ammunition'] ?? 0),
        ],
        'production' => [
            'total' => $clipInt($productionInput['total'] ?? 0),
            'active' => $clipInt($productionInput['active'] ?? 0),
            'input_blocked' => $clipInt($productionInput['input_blocked'] ?? 0),
            'output_blocked' => $clipInt($productionInput['output_blocked'] ?? 0),
            'unpowered' => $clipInt($productionInput['unpowered'] ?? 0),
            'staffed' => $clipInt($productionInput['staffed'] ?? 0),
        ],
        'farms' => [
            'total' => $clipInt($farmsInput['total'] ?? 0),
            'active' => $clipInt($farmsInput['active'] ?? 0),
            'needs_water' => $clipInt($farmsInput['needs_water'] ?? 0),
            'output_full' => $clipInt($farmsInput['output_full'] ?? 0),
            'unpowered' => $clipInt($farmsInput['unpowered'] ?? 0),
            'staffed' => $clipInt($farmsInput['staffed'] ?? 0),
        ],
    ];
}

/**
 * Normalizes player-base telemetry shared by NPC context and presence ingest.
 */
function stobeNormalizePlayerBaseSnapshot(mixed $value, bool $stampObservation = false): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($value)) {
        return [];
    }

    $inside = coerceBoolean($value['inside'] ?? false);
    $observedGameTs = max(0, intval($value['observed_game_ts'] ?? ($value['game_ts'] ?? 0)));
    $normalized = [
        'inside' => $inside,
        'observed_game_ts' => $observedGameTs,
    ];
    if ($stampObservation) {
        $normalized['server_observed_at'] = time();
    } elseif (isset($value['server_observed_at'])) {
        $normalized['server_observed_at'] = max(0, intval($value['server_observed_at']));
    }
    if (!$inside) {
        return $normalized;
    }

    $clipText = static function (mixed $candidate, int $limit): string {
        $text = trim(strval($candidate));
        return strlen($text) > $limit ? substr($text, 0, $limit) : $text;
    };
    $clipFloat = static function (mixed $candidate): float {
        if (!is_numeric($candidate)) {
            return 0.0;
        }
        $value = floatval($candidate);
        if (!is_finite($value)) {
            return 0.0;
        }
        return max(0.0, min(1000000000.0, $value));
    };

    $baseId = $clipText($value['base_id'] ?? '', 255);
    if ($baseId === '') {
        return [
            'inside' => false,
            'observed_game_ts' => $observedGameTs,
        ] + ($stampObservation ? ['server_observed_at' => time()] : []);
    }

    $normalized += [
        'base_id' => $baseId,
        'name' => $clipText($value['name'] ?? 'Player Base', 255),
        'power_generated' => $clipFloat($value['power_generated'] ?? 0),
        'power_required' => $clipFloat($value['power_required'] ?? 0),
        'battery_charge' => $clipFloat($value['battery_charge'] ?? 0),
        'battery_capacity' => $clipFloat($value['battery_capacity'] ?? 0),
        'battery_drain' => $clipFloat($value['battery_drain'] ?? 0),
        'battery_charging' => $clipFloat($value['battery_charging'] ?? 0),
        'battery_mode' => coerceBoolean($value['battery_mode'] ?? false),
        'has_spare_power' => coerceBoolean($value['has_spare_power'] ?? false),
        'members_inside' => max(0, min(1000, intval($value['members_inside'] ?? 0))),
        'has_gates' => coerceBoolean($value['has_gates'] ?? false),
        'gates_closed' => coerceBoolean($value['gates_closed'] ?? false),
        'details' => stobeNormalizePlayerBaseDetails($value['details'] ?? []),
    ];
    if ($normalized['name'] === '') {
        $normalized['name'] = 'Player Base';
    }
    return $normalized;
}

function stobePlayerBaseExecOrThrow(object $db, string $query, array $params = []): void
{
    if ($db->exec($query, $params) !== false) {
        return;
    }
    $error = method_exists($db, 'GetLastError') ? trim(strval($db->GetLastError())) : '';
    throw new RuntimeException($error !== '' ? $error : 'Player base database write failed');
}

/**
 * Stores every encountered base separately while tracking one current selection.
 */
function stobeStorePlayerBaseState(array $payload): array
{
    $db = $GLOBALS['db'];
    $sessionId = substr(trim(strval($payload['session_id'] ?? '')), 0, 128);
    $observerSerial = max(0, intval($payload['observer_serial'] ?? 0));
    $observerName = substr(trim(strval($payload['observer_name'] ?? '')), 0, 255);
    $gameTs = max(0, intval($payload['game_ts'] ?? 0));
    $base = stobeNormalizePlayerBaseSnapshot($payload['player_base'] ?? [], false);
    $inside = boolval($base['inside'] ?? false);
    if ($sessionId === '') {
        throw new InvalidArgumentException('Missing session_id');
    }

    stobePlayerBaseExecOrThrow($db, 'BEGIN');
    try {
        $baseId = null;
        if ($inside) {
            $baseId = strval($base['base_id']);
            stobePlayerBaseExecOrThrow(
                $db,
                "INSERT INTO player_bases (
                    base_id, name, power_generated, power_required,
                    battery_charge, battery_capacity, battery_drain, battery_charging,
                    battery_mode, has_spare_power, members_inside,
                    has_gates, gates_closed, details, game_ts, last_seen_at
                 ) VALUES (
                    $1, $2, $3, $4, $5, $6, $7, $8,
                    $9::boolean, $10::boolean, $11,
                    $12::boolean, $13::boolean, $14::jsonb, $15, NOW()
                 )
                 ON CONFLICT (base_id) DO UPDATE SET
                    name = EXCLUDED.name,
                    power_generated = EXCLUDED.power_generated,
                    power_required = EXCLUDED.power_required,
                    battery_charge = EXCLUDED.battery_charge,
                    battery_capacity = EXCLUDED.battery_capacity,
                    battery_drain = EXCLUDED.battery_drain,
                    battery_charging = EXCLUDED.battery_charging,
                    battery_mode = EXCLUDED.battery_mode,
                    has_spare_power = EXCLUDED.has_spare_power,
                    members_inside = EXCLUDED.members_inside,
                    has_gates = EXCLUDED.has_gates,
                    gates_closed = EXCLUDED.gates_closed,
                    details = EXCLUDED.details,
                    game_ts = EXCLUDED.game_ts,
                    last_seen_at = NOW()",
                [
                    $baseId,
                    strval($base['name']),
                    strval($base['power_generated']),
                    strval($base['power_required']),
                    strval($base['battery_charge']),
                    strval($base['battery_capacity']),
                    strval($base['battery_drain']),
                    strval($base['battery_charging']),
                    boolval($base['battery_mode']) ? 'true' : 'false',
                    boolval($base['has_spare_power']) ? 'true' : 'false',
                    intval($base['members_inside']),
                    boolval($base['has_gates']) ? 'true' : 'false',
                    boolval($base['gates_closed']) ? 'true' : 'false',
                    json_encode($base['details'], JSON_UNESCAPED_SLASHES),
                    $gameTs,
                ]
            );
        }

        stobePlayerBaseExecOrThrow(
            $db,
            "INSERT INTO player_base_presence (
                scope_key, session_id, observer_serial, observer_name,
                inside, base_id, game_ts, observed_at
             ) VALUES ('selected_player', $1, $2, $3, $4::boolean, $5, $6, NOW())
             ON CONFLICT (scope_key) DO UPDATE SET
                session_id = EXCLUDED.session_id,
                observer_serial = EXCLUDED.observer_serial,
                observer_name = EXCLUDED.observer_name,
                inside = EXCLUDED.inside,
                base_id = EXCLUDED.base_id,
                game_ts = EXCLUDED.game_ts,
                observed_at = NOW()",
            [
                $sessionId,
                $observerSerial,
                $observerName,
                $inside ? 'true' : 'false',
                $inside ? $baseId : null,
                $gameTs,
            ]
        );
        stobePlayerBaseExecOrThrow($db, 'COMMIT');
    } catch (Throwable $exception) {
        try {
            $db->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $exception;
    }

    return [
        'inside' => $inside,
        'base_id' => $inside ? strval($base['base_id'] ?? '') : '',
    ];
}

/**
 * Returns current selected-character base state only while telemetry is fresh.
 */
function stobeGetCurrentPlayerBaseState(int $maxAgeSeconds = 30): array
{
    $maxAgeSeconds = max(5, min(300, $maxAgeSeconds));
    $row = $GLOBALS['db']->fetchOne(
        "SELECT
            p.session_id, p.observer_serial, p.observer_name, p.game_ts,
            EXTRACT(EPOCH FROM (NOW() - p.observed_at))::int AS age_seconds,
            b.base_id, b.name, b.power_generated, b.power_required,
            b.battery_charge, b.battery_capacity, b.battery_drain, b.battery_charging,
            b.battery_mode, b.has_spare_power, b.members_inside,
            b.has_gates, b.gates_closed, b.details
         FROM player_base_presence p
         INNER JOIN player_bases b ON b.base_id = p.base_id
         WHERE p.scope_key = 'selected_player'
           AND p.inside = TRUE
           AND p.observed_at >= NOW() - ($1::int * INTERVAL '1 second')
         LIMIT 1",
        [$maxAgeSeconds]
    );
    return is_array($row) ? $row : [];
}
