<?php

/**
 * StobeServer - Speech-to-Text Endpoint
 * 
 * Receives audio file uploads from Stobe DLL and transcribes via
 * configured STT service (Parakeet, Whisper, etc.).
 */

error_reporting(E_ALL);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    stobeLogWarn('stt rejected request method', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
    http_response_code(405);
    echo json_encode(["error" => "POST required"]);
    exit;
}

if (!isset($_FILES['file'])) {
    stobeLogWarn('stt rejected request: missing file payload');
    http_response_code(400);
    echo json_encode(["error" => "No audio file provided"]);
    exit;
}

stobeLogInfo('stt upload received', [
    'filename' => $_FILES['file']['name'] ?? '',
    'size' => intval($_FILES['file']['size'] ?? 0),
    'mime' => $_FILES['file']['type'] ?? '',
]);

// TODO: Route to configured STT connector
// Can reuse HerikaServer's STT connector pattern
echo json_encode(["text" => "", "status" => "not_implemented"]);

