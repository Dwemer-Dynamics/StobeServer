<?php

/**
 * StobeServer Root Index
 * Redirects to UI dashboard or returns API status.
 */

$path = __DIR__ . DIRECTORY_SEPARATOR;
$GLOBALS["ENGINE_PATH"] = $path;
require_once($path . "lib/server_logger.php");
stobeConfigurePhpErrorLogging();
stobeRegisterErrorHandlers();
stobeLogRequestStart();

$shouldRunDbUpdates = (PHP_SAPI !== 'cli') && (count($_GET) === 0);
if ($shouldRunDbUpdates) {
    try {
        require_once($path . "lib/bootstrap.php");
        require_once($path . "debug/db_updates.php");
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Root index db update failed');
    }
}

if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false) {
    $quickstartRequired = false;
    try {
        $quickstartRequired = function_exists('stobeShouldRedirectToQuickstart')
            ? stobeShouldRedirectToQuickstart()
            : false;
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Quickstart redirect check failed at root index');
    }

    if ($quickstartRequired) {
        stobeLogInfo('Root index redirecting to quickstart');
        header('Location: ui/quickstart.php');
        exit;
    }

    stobeLogInfo('Root index redirecting to dashboard');
    header('Location: ui/home.php');
    exit;
}

stobeLogInfo('Root index JSON status requested');
header('Content-Type: application/json');
echo json_encode([
    'server' => 'StobeServer',
    'version' => '0.6.0',
    'game' => 'Kenshi',
    'status' => 'ok',
]);

