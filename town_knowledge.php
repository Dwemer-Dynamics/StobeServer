<?php

/**
 * Stores the complete set of settlements currently known by the player.
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . 'lib/bootstrap.php');

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['towns']) || !is_array($input['towns'])) {
    stobeLogWarn('town_knowledge rejected: invalid payload');
    http_response_code(400);
    echo json_encode(['error' => 'Invalid town knowledge payload']);
    exit;
}

$gamets = max(0, intval($input['game_ts'] ?? 0));
if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($gamets, 'town_knowledge');
}

$result = stobeStoreTownKnowledgeSnapshot($input['towns'], $gamets);
stobeLogInfo('town knowledge stored', [
    'gamets' => $gamets,
    'stored' => intval($result['stored'] ?? 0),
    'removed' => intval($result['removed'] ?? 0),
    'rejected' => intval($result['rejected'] ?? 0),
]);

echo json_encode(['status' => 'ok'] + $result);
