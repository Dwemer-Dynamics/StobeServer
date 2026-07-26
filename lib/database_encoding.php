<?php

declare(strict_types=1);

if (!function_exists('stobeDatabaseRepairCommand')) {
    function stobeDatabaseRepairCommand(): string
    {
        return 'sudo php /var/www/html/StobeServer/debug/run_db_updates.php';
    }
}

if (!function_exists('stobeDatabaseEncoding')) {
    function stobeDatabaseEncoding($db = null): string
    {
        if ($db === null) {
            $db = $GLOBALS['db'] ?? null;
        }
        if (!is_object($db) || !method_exists($db, 'fetchOne')) {
            return '';
        }

        try {
            $row = $db->fetchOne('SHOW server_encoding');
            return strtoupper(trim(strval(is_array($row) ? ($row['server_encoding'] ?? '') : '')));
        } catch (Throwable) {
            return '';
        }
    }
}

if (!function_exists('stobeDatabaseEncodingIsSupported')) {
    function stobeDatabaseEncodingIsSupported($db = null): bool
    {
        return stobeDatabaseEncoding($db) === 'UTF8';
    }
}

if (!function_exists('stobeDatabaseEncodingError')) {
    function stobeDatabaseEncodingError($db = null): string
    {
        $encoding = stobeDatabaseEncoding($db);
        $label = $encoding !== '' ? $encoding : 'unknown encoding';

        return "Stobe database uses {$label}; UTF8 is required for NPC metadata and JSON data. "
            . 'Run ' . stobeDatabaseRepairCommand() . '.';
    }
}

if (!function_exists('stobeDatabaseSchemaReadiness')) {
    function stobeDatabaseSchemaReadiness($db = null): array
    {
        if ($db === null) {
            $db = $GLOBALS['db'] ?? null;
        }
        if (!is_object($db) || !method_exists($db, 'fetchOne')) {
            return [
                'ready' => false,
                'missing' => ['database connection'],
            ];
        }

        $requiredColumns = [
            'bio_random.is_enabled',
            'bio_random_custom.is_enabled',
            'bio_unique.is_enabled',
            'bio_unique_custom.is_enabled',
            'rename_token_global.is_enabled',
            'rename_token_global_custom.is_enabled',
        ];
        $requiredRelations = [
            'database_versioning',
            'combined_bio_random',
            'combined_bio_unique',
            'combined_rename_token_global',
        ];

        try {
            $rows = [];
            if (method_exists($db, 'fetchAll')) {
                $rows = $db->fetchAll(
                    "SELECT table_name, column_name
                     FROM information_schema.columns
                     WHERE table_schema = 'public'
                       AND (table_name || '.' || column_name) = ANY($1::text[])",
                    ['{' . implode(',', $requiredColumns) . '}']
                );
            }
            $presentColumns = [];
            foreach ($rows as $row) {
                $key = strval($row['table_name'] ?? '') . '.' . strval($row['column_name'] ?? '');
                if ($key !== '.') {
                    $presentColumns[$key] = true;
                }
            }

            $missing = [];
            foreach ($requiredColumns as $requiredColumn) {
                if (!isset($presentColumns[$requiredColumn])) {
                    $missing[] = $requiredColumn;
                }
            }
            foreach ($requiredRelations as $relation) {
                $row = $db->fetchOne('SELECT to_regclass($1) AS relation', ['public.' . $relation]);
                if (!is_array($row) || trim(strval($row['relation'] ?? '')) === '') {
                    $missing[] = $relation;
                }
            }

            return [
                'ready' => count($missing) === 0,
                'missing' => $missing,
            ];
        } catch (Throwable) {
            return [
                'ready' => false,
                'missing' => ['schema inspection failed'],
            ];
        }
    }
}

if (!function_exists('stobeDatabaseReadiness')) {
    function stobeDatabaseReadiness($db = null): array
    {
        if ($db === null) {
            $db = $GLOBALS['db'] ?? null;
        }
        $encoding = stobeDatabaseEncoding($db);
        $encodingSupported = $encoding === 'UTF8';
        $schema = $encodingSupported
            ? stobeDatabaseSchemaReadiness($db)
            : ['ready' => false, 'missing' => ['UTF8 database encoding']];

        return [
            'ready' => $encodingSupported && boolval($schema['ready'] ?? false),
            'encoding' => $encoding,
            'encoding_supported' => $encodingSupported,
            'schema_ready' => boolval($schema['ready'] ?? false),
            'missing' => array_values($schema['missing'] ?? []),
            'repair_command' => stobeDatabaseRepairCommand(),
        ];
    }
}

if (!function_exists('stobeDatabaseReadinessError')) {
    function stobeDatabaseReadinessError($db = null): string
    {
        $readiness = stobeDatabaseReadiness($db);
        if (!boolval($readiness['encoding_supported'] ?? false)) {
            return stobeDatabaseEncodingError($db);
        }

        $missing = array_values($readiness['missing'] ?? []);
        $details = count($missing) > 0 ? (' Missing: ' . implode(', ', $missing) . '.') : '';
        return 'Stobe database updates are incomplete.' . $details
            . ' Run ' . stobeDatabaseRepairCommand() . '.';
    }
}

if (!function_exists('stobeRequireDatabaseReady')) {
    function stobeRequireDatabaseReady($db = null): void
    {
        $readiness = stobeDatabaseReadiness($db);
        if (boolval($readiness['ready'] ?? false)) {
            return;
        }

        $message = stobeDatabaseReadinessError($db);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(78);
        }

        http_response_code(503);
        header('Cache-Control: no-store');
        header('Retry-After: 60');

        $script = str_replace('\\', '/', strval($_SERVER['SCRIPT_NAME'] ?? ''));
        $accept = strtolower(strval($_SERVER['HTTP_ACCEPT'] ?? ''));
        $wantsHtml = str_contains($script, '/ui/')
            || basename($script) === 'index.php'
            || str_contains($accept, 'text/html');

        if (!$wantsHtml) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'status' => 'database_upgrade_required',
                'error' => $message,
                'database' => $readiness,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
            exit;
        }

        header('Content-Type: text/html; charset=utf-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeCommand = htmlspecialchars(stobeDatabaseRepairCommand(), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Stobe database upgrade required</title>'
            . '<style>body{margin:0;background:#171717;color:#eee;font:16px/1.5 Georgia,serif}'
            . 'main{max-width:760px;margin:8vh auto;padding:32px;background:#232323;border:1px solid #65563a}'
            . 'h1{margin-top:0;color:#e4b95f}code{display:block;padding:14px;background:#111;border:1px solid #444;'
            . 'overflow-wrap:anywhere;color:#f2d38d}</style></head><body><main>'
            . '<h1>Database upgrade required</h1><p>' . $safeMessage . '</p>'
            . '<p>Run this command in the DwemerDistro WSL terminal, then reload StobeServer:</p>'
            . '<code>' . $safeCommand . '</code></main></body></html>';
        exit;
    }
}
