<?php

/**
 * StobeServer bootstrap.
 * Canonical runtime bootstrap (moved from lib/bootstrap.php).
 *
 * Loads shared libraries, initializes DB handle, and exposes a few
 * compatibility shims used by legacy Herika-style pages.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
$GLOBALS['ENGINE_PATH'] = $enginePath;

if (!isset($GLOBALS['DBDRIVER']) || !is_string($GLOBALS['DBDRIVER']) || $GLOBALS['DBDRIVER'] === '') {
    $GLOBALS['DBDRIVER'] = 'postgresql';
}

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'postgresql.class.php');
if (!isset($GLOBALS['db']) || !($GLOBALS['db'] instanceof sql)) {
    $GLOBALS['db'] = new sql();
}

require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'narrator.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'settings.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'server_logger.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'background_processor.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-pockettts.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-xtts.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-chatterbox.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-cartesia.php');
require_once($enginePath . 'tts' . DIRECTORY_SEPARATOR . 'tts-inworld.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'data_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'memory_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'chat_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'diary_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'middleterm_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'dynamic_profile_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'narrator_helper_functions.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_schema.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_storage.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_snapshot.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'playthrough_rollback.php');

if (!function_exists('extract_assignments')) {
    function extract_assignments(string $filePath): array
    {
        // Stobe does not use Herika conf assignment parsing; keep compat API.
        return [];
    }
}

if (!function_exists('convert_gamets2skyrim_long_date2')) {
    function convert_gamets2skyrim_long_date2(mixed $gamets): string
    {
        return stobeGametsDateLabel($gamets);
    }
}

if (!function_exists('gamets2str_format_gregorian_date')) {
    function gamets2str_format_gregorian_date(mixed $gamets, string $format = 'Y-m-d H:i'): string
    {
        $ts = stobeGametsNormalize($gamets);
        if ($ts <= 0) {
            return '';
        }
        return gmdate($format, $ts);
    }
}

