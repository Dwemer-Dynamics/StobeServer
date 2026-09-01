<?php

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Return one generic JSON response without exposing provider details or credentials.
function stobeTtsPronunciationPreviewRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (strtoupper(strval($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    stobeTtsPronunciationPreviewRespond(['ok' => false, 'error' => 'Use POST to generate a preview.'], 405);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'use_strict_mode' => true,
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ]);
}
$now = microtime(true);
$recentRequests = array_values(array_filter(
    is_array($_SESSION['stobe_tts_pronunciation_previews'] ?? null)
        ? $_SESSION['stobe_tts_pronunciation_previews']
        : [],
    static fn($timestamp): bool => is_numeric($timestamp) && floatval($timestamp) > $now - 60
));
if (count($recentRequests) >= 30) {
    stobeTtsPronunciationPreviewRespond(['ok' => false, 'error' => 'Too many previews. Wait a moment and try again.'], 429);
}
$recentRequests[] = $now;
$_SESSION['stobe_tts_pronunciation_previews'] = $recentRequests;
session_write_close();

$enginePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once $enginePath . 'lib' . DIRECTORY_SEPARATOR . 'tts_pronunciation_preview.php';

$connectorId = intval($_POST['connector_id'] ?? 0);
$voice = trim(strval($_POST['voice'] ?? ''));
$text = trim(strval($_POST['text'] ?? ''));
$textLength = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
if ($connectorId <= 0 || $voice === '' || $text === '' || $textLength > 240) {
    stobeTtsPronunciationPreviewRespond(
        ['ok' => false, 'error' => 'Choose a connector and voice, and preview 240 characters or fewer.'],
        400
    );
}

$options = stobeTtsPronunciationPreviewOptions();
$availableConnectorIds = array_map(
    static fn(array $row): int => intval($row['id'] ?? 0),
    $options['connectors'] ?? []
);
if (!in_array($connectorId, $availableConnectorIds, true)
    || !in_array($voice, $options['voices'] ?? [], true)) {
    stobeTtsPronunciationPreviewRespond(
        ['ok' => false, 'error' => 'That connector or installed voice is no longer available.'],
        400
    );
}

$connector = getTtsConnectorById($connectorId);
if (!is_array($connector)) {
    stobeTtsPronunciationPreviewRespond(
        ['ok' => false, 'error' => 'The selected TTS connector could not be loaded.'],
        404
    );
}

try {
    $result = stobeSynthesizeTtsFromConnector($connector, $text, $voice);
    $hash = trim(strval($result['hash'] ?? ''));
    $cachePath = $enginePath . 'soundcache' . DIRECTORY_SEPARATOR . $hash . '.wav';
    if (preg_match('/^[a-f0-9]{32}$/i', $hash) !== 1
        || !is_file($cachePath)
        || intval(filesize($cachePath)) <= 44) {
        throw new RuntimeException('No preview audio was generated.');
    }

    $scriptPath = str_replace('\\', '/', strval($_SERVER['SCRIPT_NAME'] ?? ''));
    $uiPosition = strpos($scriptPath, '/ui/');
    $webRoot = $uiPosition !== false ? substr($scriptPath, 0, $uiPosition) : '';
    if ($webRoot === '/') {
        $webRoot = '';
    }

    stobeTtsPronunciationPreviewRespond([
        'ok' => true,
        'audio_url' => rtrim($webRoot, '/') . '/soundcache/' . rawurlencode($hash . '.wav') . '?ts=' . time(),
    ]);
} catch (Throwable $exception) {
    if (function_exists('stobeLogException')) {
        stobeLogException($exception, 'TTS pronunciation preview failed');
    }
    stobeTtsPronunciationPreviewRespond([
        'ok' => false,
        'error' => 'TTS preview could not be generated. Check the selected connector and voice.',
    ], 502);
}
