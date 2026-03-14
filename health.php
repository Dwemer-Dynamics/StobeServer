<?php

/**
 * StobeServer health endpoint.
 *
 * Lightweight liveness check for launcher discovery and DLL connectivity status.
 */

$path = __DIR__ . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"] = $path;
require_once($path . "lib/server_logger.php");
stobeConfigurePhpErrorLogging();
stobeRegisterErrorHandlers();
stobeLogRequestStart();
stobeLogDebug('Health endpoint requested');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
http_response_code(200);

echo json_encode(
    [
        'ok' => true,
        'service' => 'StobeServer',
        'timestamp' => time(),
    ],
    JSON_UNESCAPED_SLASHES
);
