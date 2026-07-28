<?php

/**
 * Read-only world journal data for the in-game STOBE menu.
 *
 * POST JSON:
 *   {"action":"locations"}
 *   {"action":"location","id":123}
 *   {"action":"factions"}
 *   {"action":"faction","id":123}
 *   {"action":"events"}
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");
require_once($path . "lib/utils_game_timestamp.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    return;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    return;
}

$action = strtolower(trim(strval($payload['action'] ?? 'locations')));
$db = $GLOBALS['db'];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

function stobeWorldListToken(string $value): string {
    return trim(preg_replace('/[\x00-\x1F,\|]+/u', ' ', $value) ?? '');
}

function stobeWorldCoordinateToken(mixed $value): string {
    $formatted = number_format(floatval($value), 3, '.', '');
    $trimmed = rtrim(rtrim($formatted, '0'), '.');
    return $trimmed === '' || $trimmed === '-0' ? '0' : $trimmed;
}

function stobeWorldBoolValue(mixed $value): bool {
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim(strval($value))), ['1', 't', 'true', 'yes', 'on'], true);
}

if ($action === 'locations') {
    $rows = $db->fetchAll(
        "SELECT id, zone_name, city_name, last_game_ts
         FROM location_zones
         WHERE x IS NOT NULL
           AND y IS NOT NULL
           AND z IS NOT NULL
           AND metadata->>'knowledge_only' IS DISTINCT FROM 'true'
         ORDER BY last_game_ts DESC, LOWER(zone_name) ASC, id DESC
         LIMIT 250"
    );
    $entries = [];
    foreach ($rows as $row) {
        $label = stobeWorldListToken(strval($row['zone_name'] ?? ''));
        $city = stobeWorldListToken(strval($row['city_name'] ?? ''));
        if ($label === '') {
            $label = $city;
        } elseif ($city !== '' && strcasecmp($city, $label) !== 0) {
            $label .= ' - ' . $city;
        }
        if ($label !== '') {
            $entries[] = $label . '|' . strval(intval($row['id'] ?? 0));
        }
    }
    echo json_encode(['ok' => true, 'count' => count($entries), 'entries' => $entries], $jsonFlags);
    return;
}

if ($action === 'location') {
    $id = intval($payload['id'] ?? 0);
    $row = $id > 0 ? $db->fetchOne(
        "SELECT id, zone_name, city_name, x, y, z, first_game_ts, last_game_ts, metadata
         FROM location_zones
         WHERE id = $1
           AND x IS NOT NULL
           AND y IS NOT NULL
           AND z IS NOT NULL
           AND metadata->>'knowledge_only' IS DISTINCT FROM 'true'
         LIMIT 1",
        [$id]
    ) : false;
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Visited location not found']);
        return;
    }

    $zone = stobeWorldListToken(strval($row['zone_name'] ?? ''));
    $city = stobeWorldListToken(strval($row['city_name'] ?? ''));
    $label = $zone !== '' ? $zone : ($city !== '' ? $city : 'destination');
    $x = stobeWorldCoordinateToken($row['x'] ?? 0);
    $y = stobeWorldCoordinateToken($row['y'] ?? 0);
    $z = stobeWorldCoordinateToken($row['z'] ?? 0);

    $lines = ['Location: ' . $label];
    if ($city !== '' && strcasecmp($city, $label) !== 0) {
        $lines[] = 'City: ' . $city;
    }
    $lines[] = 'First visited: ' . stobeGametsDateLabel(intval($row['first_game_ts'] ?? 0));
    $lines[] = 'Last visited: ' . stobeGametsDateLabel(intval($row['last_game_ts'] ?? 0));
    $lines[] = '';
    $lines[] = 'Coordinates: ' . $x . ', ' . $y . ', ' . $z;
    $lines[] = '';
    $lines[] = 'Select a player-faction squad member in game, then choose Travel Here.';

    echo json_encode(
        [
            'ok' => true,
            'text' => implode("\n", $lines),
            'travel' => $x . ';' . $y . ';' . $z . ';' . $label,
        ],
        $jsonFlags
    );
    return;
}

if ($action === 'factions') {
    $rows = $db->fetchAll(
        "SELECT id, source_name, target_name, relation, alliance, war, coexists, game_ts
         FROM faction_relation_state
         ORDER BY game_ts DESC, updated_at DESC, id DESC
         LIMIT 250"
    );
    $entries = [];
    foreach ($rows as $row) {
        $source = stobeWorldListToken(strval($row['source_name'] ?? ''));
        $target = stobeWorldListToken(strval($row['target_name'] ?? ''));
        $label = trim($source . ' -> ' . $target);
        if ($source !== '' && $target !== '') {
            $entries[] = $label . '|' . strval(intval($row['id'] ?? 0));
        }
    }
    echo json_encode(['ok' => true, 'count' => count($entries), 'entries' => $entries], $jsonFlags);
    return;
}

if ($action === 'faction') {
    $id = intval($payload['id'] ?? 0);
    $row = $id > 0 ? $db->fetchOne(
        "SELECT source_name, target_name, relation, alliance, war, coexists, game_ts, updated_at
         FROM faction_relation_state
         WHERE id = $1
         LIMIT 1",
        [$id]
    ) : false;
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Faction relation not found']);
        return;
    }

    $lines = [
        'Source: ' . trim(strval($row['source_name'] ?? 'Unknown')),
        'Target: ' . trim(strval($row['target_name'] ?? 'Unknown')),
        'Relation: ' . strval(round(floatval($row['relation'] ?? 0), 2)),
        'Allied: ' . (stobeWorldBoolValue($row['alliance'] ?? false) ? 'Yes' : 'No'),
        'At war: ' . (stobeWorldBoolValue($row['war'] ?? false) ? 'Yes' : 'No'),
        'Coexists: ' . (stobeWorldBoolValue($row['coexists'] ?? false) ? 'Yes' : 'No'),
        'Observed: ' . stobeGametsDateLabel(intval($row['game_ts'] ?? 0)),
    ];
    echo json_encode(['ok' => true, 'text' => implode("\n", $lines)], $jsonFlags);
    return;
}

if ($action === 'events') {
    $rows = $db->fetchAll(
        "SELECT query_name, player_involvement, rule_category, entity_name, state_value, bool_value, game_ts
         FROM world_state
         ORDER BY game_ts DESC, id DESC
         LIMIT 80"
    );
    $lines = [];
    foreach ($rows as $row) {
        $query = trim(strval($row['query_name'] ?? 'World event'));
        if ($query === '') {
            $query = 'World event';
        }
        $lines[] = $query . ' - ' . stobeGametsDateLabel(intval($row['game_ts'] ?? 0));
        $entity = trim(strval($row['entity_name'] ?? ''));
        if ($entity !== '') {
            $lines[] = 'Entity: ' . $entity;
        }
        $category = trim(strval($row['rule_category'] ?? ''));
        if ($category !== '') {
            $lines[] = 'Category: ' . $category;
        }
        $value = trim(strval($row['state_value'] ?? ''));
        if ($value === '' && array_key_exists('bool_value', $row) && $row['bool_value'] !== null) {
            $value = !empty($row['bool_value']) ? 'true' : 'false';
        }
        if ($value !== '') {
            $lines[] = 'State: ' . $value;
        }
        if (stobeWorldBoolValue($row['player_involvement'] ?? false)) {
            $lines[] = 'Player involved: Yes';
        }
        $lines[] = '';
    }
    if (count($lines) === 0) {
        $lines[] = 'No world events have been recorded.';
    }
    echo json_encode(
        ['ok' => true, 'count' => count($rows), 'text' => rtrim(implode("\n", $lines))],
        $jsonFlags
    );
    return;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action']);
