<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Stobe database updates are CLI-only.\n";
    exit(1);
}

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR;

try {
    require_once($path . 'lib' . DIRECTORY_SEPARATOR . 'database_upgrade.php');
    $GLOBALS['STOBE_DATABASE_UPGRADE_IN_PROGRESS'] = true;
    $GLOBALS['db'] = stobePrepareDatabaseForUpdates();

    require_once($path . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');
    require_once($path . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php');

    unset($GLOBALS['STOBE_DATABASE_UPGRADE_IN_PROGRESS']);
    $readiness = stobeDatabaseReadiness($GLOBALS['db']);
    if (!boolval($readiness['ready'] ?? false)) {
        throw new RuntimeException(stobeDatabaseReadinessError($GLOBALS['db']));
    }

    echo "stobe db updates complete\n";
} catch (Throwable $exception) {
    unset($GLOBALS['STOBE_DATABASE_UPGRADE_IN_PROGRESS']);
    fwrite(STDERR, 'Stobe database update failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

