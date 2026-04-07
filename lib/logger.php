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
        private static function shouldUseRelationshipLog(string $message): bool
        {
            return str_starts_with(ltrim($message), '[REL');
        }

        public static function info(string $message): void
        {
            if (self::shouldUseRelationshipLog($message)) {
                stobeLogRelationshipInfo($message);
                return;
            }
            stobeLogInfo($message);
        }

        public static function warn(string $message): void
        {
            if (self::shouldUseRelationshipLog($message)) {
                stobeLogRelationshipWarn($message);
                return;
            }
            stobeLogWarn($message);
        }

        public static function warning(string $message): void
        {
            self::warn($message);
        }

        public static function error(string $message): void
        {
            if (self::shouldUseRelationshipLog($message)) {
                stobeLogRelationshipError($message);
                return;
            }
            stobeLogError($message);
        }

        public static function debug(string $message): void
        {
            if (self::shouldUseRelationshipLog($message)) {
                stobeLogRelationshipDebug($message);
                return;
            }
            stobeLogDebug($message);
        }
    }
}
