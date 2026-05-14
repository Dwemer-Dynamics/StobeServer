<?php

declare(strict_types=1);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . 'lib/bootstrap.php');

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    return;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode(strval($rawBody), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    return;
}

$updates = [];
if (is_array($payload['updates'] ?? null)) {
    $updates = $payload['updates'];
} elseif (
    isset($payload['utterance_id'])
    && isset($payload['delivery_state'])
) {
    $updates = [[
        'utterance_id' => strval($payload['utterance_id']),
        'delivery_state' => strval($payload['delivery_state']),
    ]];
}

$result = stobeApplySpeechDeliveryUpdates($updates);

echo json_encode([
    'ok' => true,
    'applied' => intval($result['applied'] ?? 0),
    'spoken' => intval($result['spoken'] ?? 0),
    'cancelled' => intval($result['cancelled'] ?? 0),
    'memory_rows' => intval($result['memory_rows'] ?? 0),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
