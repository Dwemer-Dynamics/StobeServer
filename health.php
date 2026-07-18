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
header('Cache-Control: no-store');

$response = [
    'ok' => false,
    'status' => 'unhealthy',
    'service' => 'StobeServer',
    'database' => false,
    'database_encoding' => '',
    'database_encoding_supported' => false,
    'timestamp' => time(),
];

try {
    require_once($path . 'lib' . DIRECTORY_SEPARATOR . 'postgresql.class.php');
    require_once($path . 'lib' . DIRECTORY_SEPARATOR . 'database_encoding.php');

    $db = new sql();
    $response['database'] = (bool)$db->query('SELECT 1');
    $response['database_encoding'] = $response['database'] ? stobeDatabaseEncoding($db) : '';
    $response['database_encoding_supported'] = $response['database']
        && stobeDatabaseEncodingIsSupported($db);
    $response['ok'] = $response['database'] && $response['database_encoding_supported'];
    $response['status'] = $response['ok'] ? 'ok' : 'degraded';
    if ($response['database'] && !$response['database_encoding_supported']) {
        $response['error'] = stobeDatabaseEncodingError($db);
    }
} catch (Throwable $e) {
    $response['error'] = $e->getMessage();
}

http_response_code($response['ok'] ? 200 : 503);
echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
