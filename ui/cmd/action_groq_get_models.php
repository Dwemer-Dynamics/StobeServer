<?php
/**
 * Fetch available Groq models using selected API badge.
 */

$enginePath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "bootstrap.php");

header('Content-Type: application/json');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['error' => 'POST required']);
    exit;
}

$jsonDataInput = json_decode(file_get_contents("php://input"), true);
$apiBadgeId = intval($jsonDataInput['api_badge_id'] ?? 0);
if ($apiBadgeId <= 0) {
    echo json_encode(['error' => 'API badge ID required']);
    exit;
}

$badgeRow = getApiBadgeById($apiBadgeId);
if (!$badgeRow || trim(strval($badgeRow['api_key'] ?? '')) === '') {
    echo json_encode(['error' => 'API key not found for selected badge']);
    exit;
}
$apiKey = strval($badgeRow['api_key']);

$headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
];

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => implode("\r\n", $headers),
        'timeout' => 12,
    ],
]);

$response = @file_get_contents('https://api.groq.com/openai/v1/models', false, $context);
if ($response === false) {
    echo json_encode(['error' => 'Failed to fetch models from Groq API']);
    exit;
}

$json = json_decode($response, true);
if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
    echo json_encode(['error' => 'Invalid response from Groq API']);
    exit;
}

$result = [];
foreach ($json['data'] as $model) {
    $id = strval($model['id'] ?? '');
    if ($id === '') {
        continue;
    }
    $result[] = [
        'value' => $id,
        'label' => strval($model['owned_by'] ?? 'Groq'),
        'id' => $id,
        'owned_by' => strval($model['owned_by'] ?? 'Groq'),
        'context_window' => intval($model['context_window'] ?? 0),
    ];
}

usort($result, function ($a, $b) {
    return strcmp(strval($a['id'] ?? ''), strval($b['id'] ?? ''));
});

echo json_encode($result);
exit;

