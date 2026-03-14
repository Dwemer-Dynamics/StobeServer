<?php

/**
 * Legacy logger shim for copied UI pages.
 */

require_once __DIR__ . DIRECTORY_SEPARATOR . 'server_logger.php';

if (!function_exists('minai_log')) {
    function minai_log(string $message, string $level = 'INFO'): void
    {
        stobeLog($level, $message);
    }
}

// Compatibility shim for legacy relationship system files that expect Logger::info/warn/error/debug.
if (!class_exists('Logger')) {
    class Logger
    {
        public static function info(string $message): void
        {
            stobeLogInfo($message);
        }

        public static function warn(string $message): void
        {
            stobeLogWarn($message);
        }

        public static function warning(string $message): void
        {
            stobeLogWarn($message);
        }

        public static function error(string $message): void
        {
            stobeLogError($message);
        }

        public static function debug(string $message): void
        {
            stobeLogDebug($message);
        }
    }
}
