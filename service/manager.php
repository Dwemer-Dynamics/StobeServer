<?php

/**
 * Stobe background manager tick.
 *
 * Runs periodic non-interactive cycles that should not block
 * foreground request latency.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$enginePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');

/**
 * Fetch latest known in-game timestamp from eventlog.
 */
function stobeBackgroundLatestGamets(): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return 0;
    }

    try {
        $row = $db->fetchOne("SELECT COALESCE(MAX(gamets), 0) AS max_gamets FROM eventlog");
        return intval($row['max_gamets'] ?? 0);
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Background manager gamets lookup failed');
        return 0;
    }
}

$tickEventType = 'chat';
$tickTimestamp = time();
$tickGamets = stobeBackgroundLatestGamets();
$tickPayload = '[background_processor_tick]';

setConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_TS', strval($tickTimestamp), true);
setConfOpt('BACKGROUND_PROCESSOR_LAST_TICK_GAMETS', strval($tickGamets), true);

if (function_exists('stobePlayer2HealthTick')) {
    stobePlayer2HealthTick($tickTimestamp);
}

if ($tickGamets <= 0) {
    stobeLogDebug('Background manager skipped: no gamets yet');
    exit(0);
}

try {
    if (function_exists('stobeRunMiddleTermDaemonEntrypoint')) {
        stobeRunMiddleTermDaemonEntrypoint($tickTimestamp, $tickGamets, $tickPayload);
    } elseif (function_exists('stobeMaybeRunMiddleTermCycle')) {
        stobeMaybeRunMiddleTermCycle($tickEventType, $tickTimestamp, $tickGamets, $tickPayload);
    }
    if (function_exists('stobeMaybeRunRegularMemoryCycle')) {
        stobeMaybeRunRegularMemoryCycle($tickEventType, $tickTimestamp, $tickGamets, $tickPayload);
    }
    if (function_exists('stobeMaybeRunDynamicProfileCycle')) {
        stobeMaybeRunDynamicProfileCycle($tickEventType, $tickTimestamp, $tickGamets, $tickPayload);
    }
    if (function_exists('stobeMaybeRunAutoDiaryCycle')) {
        stobeMaybeRunAutoDiaryCycle($tickTimestamp, $tickGamets);
    }
    setConfOpt('BACKGROUND_PROCESSOR_LAST_SUCCESS_TS', strval(time()), true);
} catch (Throwable $exception) {
    setConfOpt('BACKGROUND_PROCESSOR_LAST_ERROR_TS', strval(time()), true);
    stobeLogException($exception, 'Background manager tick failed', [
        'gamets' => $tickGamets,
        'event_type' => $tickEventType,
    ]);
    exit(1);
}

exit(0);
