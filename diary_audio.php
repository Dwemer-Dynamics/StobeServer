<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once(__DIR__ . '/lib/bootstrap.php');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$payload = json_decode(strval(file_get_contents('php://input')), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$rowId = intval($payload['rowid'] ?? 0);
if ($rowId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_diary_entry']);
    exit;
}

$entry = $GLOBALS['db']->fetchOne(
    "SELECT rowid, content, people
     FROM diarylog
     WHERE rowid = $1
     LIMIT 1",
    [$rowId]
);
if (!$entry) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'diary_entry_not_found']);
    exit;
}

$content = trim(html_entity_decode(strip_tags(strval($entry['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
$author = normalizeParticipantNameToken(trim(strval($entry['people'] ?? ''), " \t\n\r\0\x0B|"));
if ($content === '' || $author === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $content === '' ? 'empty_diary_entry' : 'missing_diary_author']);
    exit;
}

$lockPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stobe-diary-audio-' . md5(strval($rowId)) . '.lock';
$lockHandle = fopen($lockPath, 'c');
if ($lockHandle === false) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'diary_audio_busy']);
    exit;
}
if (!flock($lockHandle, LOCK_EX)) {
    fclose($lockHandle);
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'diary_audio_busy']);
    exit;
}

$tts = [];
$synthesisError = null;
try {
    $npcData = stobeResolveNpcDataForTts($author);
    $tts = stobeSynthesizeTtsLine($author, $content, $npcData);
} catch (Throwable $exception) {
    $synthesisError = $exception;
} finally {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

if ($synthesisError !== null) {
    http_response_code(503);
    stobeLogException($synthesisError, 'Diary audio synthesis failed');
    echo json_encode(['ok' => false, 'error' => 'tts_unavailable']);
    exit;
}

$hash = trim(strval($tts['hash'] ?? ''));
if (preg_match('/^[a-f0-9]{32}$/i', $hash) !== 1) {
    http_response_code(503);
    stobeLogWarn('Diary audio synthesis failed', [
        'rowid' => $rowId,
        'npc_name' => $author,
    ]);
    echo json_encode(['ok' => false, 'error' => 'tts_unavailable']);
    exit;
}

$scriptPath = str_replace('\\', '/', strval($_SERVER['SCRIPT_NAME'] ?? ''));
$webRoot = rtrim(str_replace('\\', '/', dirname($scriptPath)), '/.');
$audioUrl = $webRoot . '/soundcache/' . rawurlencode($hash . '.wav');
$displayAuthor = function_exists('stobeIsNarratorName') && stobeIsNarratorName($author)
    ? stobeNarratorRoleplayName()
    : $author;

stobeLogInfo('Diary audio prepared', [
    'rowid' => $rowId,
    'npc_name' => $author,
    'hash' => $hash,
    'cached' => !empty($tts['cached']),
]);

echo json_encode([
    'ok' => true,
    'rowid' => $rowId,
    'author' => $displayAuthor,
    'hash' => $hash,
    'audio_url' => $audioUrl,
    'duration_ms' => intval($tts['duration_ms'] ?? 0),
    'cached' => !empty($tts['cached']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
