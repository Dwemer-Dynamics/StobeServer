<?php

/**
 * Manual rename persistence endpoint.
 *
 * Request body:
 * {"old_name":"Starving Bandit","new_name":"John","context":{...}}
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
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload']);
    return;
}

$oldName = trim(strval($payload['old_name'] ?? ''));
$newName = trim(strval($payload['new_name'] ?? ''));
$storageId = normalizeStorageIdToken($payload['storage_id'] ?? '');

if ($oldName === '' || $newName === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing names']);
    return;
}

if ($storageId === '') {
    $context = $payload['context'] ?? null;
    if (is_array($context)) {
        $storageId = normalizeStorageIdToken(
            $context['storage_id'] ??
            ($context['id'] ?? ($context['handle'] ?? ''))
        );
    }
}

$result = persistManualRename($oldName, $newName, $storageId);
if (($result['status'] ?? 'error') !== 'ok') {
    http_response_code(400);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$context = $payload['context'] ?? null;
$contextStored = false;
if (is_array($context) && count($context) > 0) {
    $snapshot = $context;
    $snapshot['name'] = $newName;
    if ($storageId !== '' && (!isset($snapshot['storage_id']) || trim(strval($snapshot['storage_id'])) === '')) {
        $snapshot['storage_id'] = $storageId;
    }
    $renameGameTs = intval($payload['game_ts'] ?? ($snapshot['game_ts'] ?? 0));
    if ($renameGameTs < 0) {
        $renameGameTs = 0;
    }
    $contextStored = storeNpcSnapshot($snapshot, $renameGameTs);
    stobeLogImport('rename context snapshot hydrate', [
        'old_name' => $oldName,
        'new_name' => $newName,
        'storage_id' => normalizeStorageIdToken($snapshot['storage_id'] ?? ''),
        'stored' => $contextStored,
        'game_ts' => $renameGameTs,
        'race' => trim(strval($snapshot['race'] ?? '')),
        'faction' => trim(strval($snapshot['faction'] ?? '')),
        'gender' => trim(strval($snapshot['gender'] ?? '')),
        'equipment_len' => strlen(trim(strval($snapshot['equipment'] ?? ''))),
        'stats_count' => is_array($snapshot['stats'] ?? null) ? count($snapshot['stats']) : 0,
        'has_medical' => is_array($snapshot['medical'] ?? null) && count($snapshot['medical']) > 0,
        'context_keys' => array_keys($context),
    ], $contextStored ? 'DEBUG' : 'WARN');
}

$result['context_hydrated'] = $contextStored;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

