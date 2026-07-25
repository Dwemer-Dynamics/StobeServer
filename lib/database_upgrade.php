<?php

declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'postgresql.class.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'database_encoding.php';

if (!function_exists('stobeDatabaseMigrationCommand')) {
    function stobeDatabaseMigrationCommand(
        string $scriptPath,
        ?int $effectiveUserId = null,
        ?string $effectiveUserName = null
    ): array
    {
        if (!is_file($scriptPath)) {
            throw new RuntimeException('Stobe UTF8 migration script is missing: ' . $scriptPath);
        }

        if ($effectiveUserId === null && function_exists('posix_geteuid')) {
            $effectiveUserId = posix_geteuid();
        }
        if ($effectiveUserName === null
            && $effectiveUserId !== null
            && function_exists('posix_getpwuid')
        ) {
            $user = posix_getpwuid($effectiveUserId);
            $effectiveUserName = is_array($user) ? strval($user['name'] ?? '') : '';
        }

        if ($effectiveUserId === 0
            || $effectiveUserName === 'postgres'
            || trim(strval(getenv('STOBE_DB_ADMIN_USER') ?: '')) !== ''
        ) {
            return ['bash', $scriptPath];
        }

        return ['sudo', '-n', 'bash', $scriptPath];
    }
}

if (!function_exists('stobeRunDatabaseMigrationCommand')) {
    function stobeRunDatabaseMigrationCommand(array $command): int
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open is required to run the Stobe database migration.');
        }

        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start the Stobe database migration.');
        }

        return proc_close($process);
    }
}

if (!function_exists('stobePrepareDatabaseForUpdates')) {
    function stobePrepareDatabaseForUpdates(?callable $commandRunner = null): sql
    {
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('Automatic Stobe database repair is CLI-only.');
        }

        $db = new sql();
        $encoding = stobeDatabaseEncoding($db);
        if ($encoding === 'UTF8') {
            return $db;
        }
        if ($encoding !== 'SQL_ASCII') {
            $error = stobeDatabaseEncodingError($db);
            $db->close();
            throw new RuntimeException($error);
        }

        $db->close();
        $scriptPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tools'
            . DIRECTORY_SEPARATOR . 'migrate-stobe-db-utf8-wsl.sh';
        $command = stobeDatabaseMigrationCommand($scriptPath);

        fwrite(STDOUT, "Legacy SQL_ASCII Stobe database detected; starting verified UTF8 migration.\n");
        $exitCode = $commandRunner !== null
            ? intval($commandRunner($command))
            : stobeRunDatabaseMigrationCommand($command);
        if ($exitCode !== 0) {
            $displayCommand = implode(' ', array_map('escapeshellarg', $command));
            throw new RuntimeException(
                "Automatic Stobe database migration failed with exit code {$exitCode}. "
                . "Run {$displayCommand} and review its output."
            );
        }

        $db = new sql();
        if (!stobeDatabaseEncodingIsSupported($db)) {
            $error = stobeDatabaseEncodingError($db);
            $db->close();
            throw new RuntimeException('Stobe database migration completed without a usable UTF8 database. ' . $error);
        }

        fwrite(STDOUT, "Stobe database encoding repair completed; applying database updates.\n");
        return $db;
    }
}
