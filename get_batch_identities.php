<?php

/**
 * Batch identity endpoint for generic NPC rename decisions.
 *
 * Request body:
 * [
 *   {"serial": 123, "name": "Starving Bandit", "gender": "Male", "race": "Human", "faction": "Dust Bandits"},
 *   ...
 * ]
 *
 * Response body:
 * [
 *   {"serial": 123, "status": "rename", "new_name": "John [Starving Bandit]"},
 *   {"serial": 124, "status": "ok"}
 * ]
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'POST required']);
    return;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid batch format']);
    return;
}

$results = batchIdentityRenameDecisions($payload);
echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

