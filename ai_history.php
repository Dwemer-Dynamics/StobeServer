<?php

/**
 * Read-only recent event browser for the in-game STOBE menu.
 *
 * POST JSON:
 *   {"filter":"default|all|dialogue|actions|travel|combat|trade","limit":60}
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");
require_once($path . "lib/event_filter_functions.php");
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

$filter = strtolower(trim(strval($payload['filter'] ?? 'default')));
$limit = max(10, min(100, intval($payload['limit'] ?? 60)));
$whereByFilter = [
    'all' => "TRUE",
    'dialogue' => "LOWER(type) IN ('inputtext', 'inputtext_s', 'outputtext', 'outputtext_s', 'npc_say', 'player_say', 'dialogue')",
    'actions' => "LOWER(type) IN ('infoaction', 'action', 'npc_action', 'roleplay_action')",
    'travel' => "LOWER(type) IN ('location', 'locationchange', 'location_change', 'travel', 'travel_location')",
    'combat' => "LOWER(type) IN ('combat', 'combatstart', 'combatend', 'death', 'limblost', 'limb_loss', 'knockout', 'healed', 'healing')",
    'trade' => "LOWER(type) IN ('trade', 'buy', 'sell', 'purchase', 'sale', 'item_transfer')",
];
if ($filter !== 'default' && !isset($whereByFilter[$filter])) {
    $filter = 'default';
}

$db = $GLOBALS['db'];
$queryParams = [];
if ($filter === 'default') {
    $hiddenTypes = stobeEventsAllHiddenTypes(stobeEventsPersistedHiddenTypes());
    $placeholders = [];
    foreach ($hiddenTypes as $index => $hiddenType) {
        $placeholders[] = '$' . ($index + 1);
        $queryParams[] = $hiddenType;
    }
    $where = empty($placeholders)
        ? 'TRUE'
        : 'type NOT IN (' . implode(', ', $placeholders) . ')';
} else {
    $where = $whereByFilter[$filter];
}
$rows = $db->fetchAll(
    "SELECT rowid, type, data, people, location, gamets, localts
     FROM eventlog
     WHERE " . $where . "
     ORDER BY gamets DESC, COALESCE(ts, localts) DESC, rowid DESC
     LIMIT " . strval($limit),
    $queryParams
);

$lines = [];
if (count($rows) === 0) {
    $lines[] = 'No matching events have been recorded.';
} else {
    foreach ($rows as $row) {
        $type = trim(strval($row['type'] ?? 'event'));
        if ($type === '') {
            $type = 'event';
        }
        $lines[] = strtoupper(str_replace('_', ' ', $type))
            . ' - ' . stobeGametsDateLabel(intval($row['gamets'] ?? 0));

        $people = trim(strval($row['people'] ?? ''));
        if ($people !== '') {
            if (function_exists('stobeRenderNarratorDisplayText')) {
                $people = stobeRenderNarratorDisplayText($people);
            }
            $lines[] = 'People: ' . $people;
        }
        $location = trim(strval($row['location'] ?? ''));
        if ($location !== '') {
            $lines[] = 'Location: ' . $location;
        }

        $data = trim(strval($row['data'] ?? ''));
        if ($data !== '') {
            if (function_exists('stobeRenderNarratorDisplayText')) {
                $data = stobeRenderNarratorDisplayText($data);
            }
            if (strlen($data) > 2000) {
                $data = substr($data, 0, 1997) . '...';
            }
            $lines[] = $data;
        }
        $lines[] = '';
    }
}

echo json_encode(
    [
        'ok' => true,
        'filter' => $filter,
        'count' => count($rows),
        'text' => rtrim(implode("\n", $lines)),
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);
