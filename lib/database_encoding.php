<?php

declare(strict_types=1);

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
            . 'Run sudo bash /var/www/html/StobeServer/tools/migrate-stobe-db-utf8-wsl.sh.';
    }
}
