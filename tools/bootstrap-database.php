<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php';

if (!stobeDatabaseEncodingIsSupported()) {
    fwrite(STDERR, stobeDatabaseEncodingError() . "\n");
    exit(1);
}

require_once $root . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php';

$db = $GLOBALS['db'] ?? null;
if (!($db instanceof sql)) {
    fwrite(STDERR, "Stobe database bootstrap failed: no database connection was created.\n");
    exit(1);
}

$requiredTables = [
    'eventlog',
    'general_settings',
    'conf_opts',
    'core_npc',
    'core_profiles',
    'prompts',
    'database_versioning',
];

foreach ($requiredTables as $table) {
    $row = $db->fetchOne('SELECT to_regclass($1) AS relation', ['public.' . $table]);
    if (!is_array($row) || trim(strval($row['relation'] ?? '')) === '') {
        fwrite(STDERR, "Stobe database bootstrap failed: missing public.{$table}.\n");
        exit(1);
    }
}

$versionRow = $db->fetchOne('SELECT count(*) AS count FROM public.database_versioning');
$versionCount = intval(is_array($versionRow) ? ($versionRow['count'] ?? 0) : 0);

echo "Stobe database bootstrap complete.\n";
echo "database encoding: " . stobeDatabaseEncoding($db) . "\n";
echo "database_versioning rows: {$versionCount}\n";
echo "schema verification: complete\n";
