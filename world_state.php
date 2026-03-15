<?php

/**
 * StobeServer - World state ingest endpoint.
 *
 * Receives serialized WorldEventStateQuery payloads from Stobe.dll.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    stobeLogWarn('world_state rejected: invalid JSON payload');
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit;
}

$payload = $input;
if (isset($input['world_state']) && is_array($input['world_state'])) {
    $payload = $input['world_state'];
}
if (!is_array($payload)) {
    stobeLogWarn('world_state rejected: malformed world_state envelope');
    http_response_code(400);
    echo json_encode(["error" => "Malformed world_state payload"]);
    exit;
}

$gamets = intval($payload['game_ts'] ?? ($input['game_ts'] ?? 0));
if ($gamets < 0) {
    $gamets = 0;
}
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($gamets, 'world_state');
}

$inserted = storeWorldStateEntries($payload);
if ($inserted < 0) {
    stobeLogError('world_state failed to persist', [
        'gamets' => $gamets,
        'keys' => array_keys($payload),
    ]);
    http_response_code(500);
    echo json_encode(["error" => "Failed to store world state"]);
    exit;
}

$queries = $payload['queries'] ?? [];
$queryCount = is_array($queries) ? count($queries) : 0;

stobeLogInfo('world_state stored', [
    'gamets' => $gamets,
    'query_count' => $queryCount,
    'rows_inserted' => $inserted,
    'truncated' => boolval($payload['truncated'] ?? false),
]);

echo json_encode([
    "status" => "ok",
    "gamets" => $gamets,
    "query_count" => $queryCount,
    "rows_inserted" => intval($inserted),
]);
