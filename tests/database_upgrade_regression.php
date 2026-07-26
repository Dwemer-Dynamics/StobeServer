<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/database_upgrade.php';

function databaseUpgradeFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function databaseUpgradeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        databaseUpgradeFail($message);
    }
}

$migrationScript = realpath(__DIR__ . '/../tools/migrate-stobe-db-utf8-wsl.sh');
databaseUpgradeAssert(is_string($migrationScript), 'migration script should exist');

$rootCommand = stobeDatabaseMigrationCommand($migrationScript, 0);
databaseUpgradeAssert($rootCommand === ['bash', $migrationScript], 'root should run migration directly');

putenv('STOBE_DB_ADMIN_USER');
$postgresCommand = stobeDatabaseMigrationCommand($migrationScript, 999, 'postgres');
databaseUpgradeAssert(
    $postgresCommand === ['bash', $migrationScript],
    'PostgreSQL service account should run migration directly'
);

$userCommand = stobeDatabaseMigrationCommand($migrationScript, 1000, 'www-data');
databaseUpgradeAssert(
    $userCommand === ['sudo', '-n', 'bash', $migrationScript],
    'unprivileged production user should require non-interactive sudo'
);

putenv('STOBE_DB_ADMIN_USER=stobe_admin');
$adminCommand = stobeDatabaseMigrationCommand($migrationScript, 1000, 'www-data');
databaseUpgradeAssert(
    $adminCommand === ['bash', $migrationScript],
    'explicit database administrator mode should not require OS sudo'
);
putenv('STOBE_DB_ADMIN_USER');

$db = new sql();
$readiness = stobeDatabaseReadiness($db);
databaseUpgradeAssert(boolval($readiness['ready'] ?? false), 'updated test database should be ready');
databaseUpgradeAssert(strval($readiness['encoding'] ?? '') === 'UTF8', 'updated test database should use UTF8');
databaseUpgradeAssert(boolval($readiness['schema_ready'] ?? false), 'required biography schema should exist');
databaseUpgradeAssert(
    str_contains(stobeDatabaseRepairCommand(), 'run_db_updates.php'),
    'repair command should use the automatic database update runner'
);

$db->exec('CREATE TEMP TABLE stobe_affected_rows_test (id INTEGER PRIMARY KEY, value TEXT)');
$db->exec("INSERT INTO stobe_affected_rows_test (id, value) VALUES (1, 'before')");
$updateResult = $db->exec(
    'UPDATE stobe_affected_rows_test SET value = $1 WHERE id = $2',
    ['after', 1]
);
databaseUpgradeAssert($db->affectedRows($updateResult) === 1, 'affectedRows should report one updated row');
$missingResult = $db->exec(
    'UPDATE stobe_affected_rows_test SET value = $1 WHERE id = $2',
    ['after', 999]
);
databaseUpgradeAssert($db->affectedRows($missingResult) === 0, 'affectedRows should report a no-op update');

echo "All database upgrade regression tests passed.\n";
