<?php
/**
 * CLI-safe DB update runner for StobeServer.
 * Loads bootstrap first so logging and DB handles are available.
 */

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($path . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');

if (!stobeDatabaseEncodingIsSupported()) {
    fwrite(STDERR, stobeDatabaseEncodingError() . "\n");
    exit(1);
}

require_once($path . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php');

echo "stobe db updates complete\n";

