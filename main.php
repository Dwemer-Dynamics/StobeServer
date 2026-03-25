<?php

/**
 * StobeServer - Main Entry Point
 * 
 * Kenshi AI NPC backend. Receives game events from the Stobe DLL plugin,
 * processes them through LLM/TTS pipelines, and returns NPC responses.
 *
 * All configuration loaded from general_settings table. No conf.php.
 */

error_reporting(E_ALL);

@define("MAXIMUM_SENTENCE_SIZE", 125);
@define("MINIMUM_SENTENCE_SIZE", 75);

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

$GLOBALS["AVOID_TTS_CACHE"] = true;
$GLOBALS["SEMAPHORES_TIMEOUT"] = 300;

// Parse incoming request
if (php_sapi_name() === "cli") {
    $playerName = getSetting('PLAYER_NAME', 'Drifter');
    $receivedData = "inputtext|" . time() . "|0|{$playerName}: {$argv[1]}";
} else {
    if (strpos($_SERVER["QUERY_STRING"], "&") === false) {
        $receivedData = mb_scrub(base64_decode(substr($_SERVER["QUERY_STRING"], 5)));
    } else {
        $receivedData = mb_scrub(base64_decode(substr($_SERVER["QUERY_STRING"], 5, strpos($_SERVER["QUERY_STRING"], "&") - 5)));
    }
}

while (ob_get_length() && ob_end_clean());
ignore_user_abort(true);
set_time_limit(1200);

$GLOBALS["runid"] = uniqid("run_", false);

if (!function_exists('stobeAppendSttLog')) {
    function stobeAppendSttLog(array $payload): void
    {
        $logPath = function_exists('stobeGetLogPath')
            ? stobeGetLogPath('stt.log')
            : (dirname(__FILE__) . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'stt.log');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (!is_string($json) || $json === '') {
            return;
        }
        $line = '[' . gmdate('Y-m-d H:i:s') . '] [INFO] stt_input | ' . $json . PHP_EOL;
        @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
    }
}

// Parse event: type|timestamp|gamets|data
$gameRequest = explode("|", $receivedData);
$eventType = $gameRequest[0] ?? '';
$timestamp = $gameRequest[1] ?? time();
$gamets = $gameRequest[2] ?? 0;
$eventData = $gameRequest[3] ?? '';

if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback(intval($gamets), strval($eventType));
}

$incomingPeople = trim((string)($_GET['people'] ?? ''));
if ($incomingPeople === '' &&
    ($eventType === 'inputtext' || $eventType === 'inputtext_s')) {
    // Keep people IDs authoritative from client payloads; do not synthesize "|player".
    $incomingPeople = '[]';
}

$GLOBALS["CACHE_PEOPLE"] = $incomingPeople;

$speakerName = '';
if ($eventType === 'inputtext' || $eventType === 'inputtext_s') {
    $speakerParts = explode(': ', $eventData, 2);
    $speakerName = trim((string)($speakerParts[0] ?? ''));
}

$jitEligibleTypes = ['inputtext', 'inputtext_s', 'chat', 'rechat', 'bored', 'infonpc'];
if (in_array($eventType, $jitEligibleTypes, true)) {
    $participantIdentities = extractParticipantIdentities([
        'people' => $incomingPeople,
        'profile' => trim((string)($_GET['profile'] ?? '')),
        'speaker' => $speakerName,
    ]);
    if (count($participantIdentities) > 0) {
        $ensureResult = ensureNpcProfilesFromParticipantIdentities($participantIdentities);
        stobeLogDebug('Participant JIT ensure (stream)', [
            'event_type' => $eventType,
            'participants' => intval($ensureResult['parsed'] ?? 0),
            'created' => intval($ensureResult['created'] ?? 0),
            'updated' => intval($ensureResult['updated'] ?? 0),
        ]);
    }
}

$eventDataPreview = $eventData;
if (strlen($eventDataPreview) > 220) {
    $eventDataPreview = substr($eventDataPreview, 0, 220) . '...';
}

stobeLogInfo('Game event received', [
    'event_type' => $eventType,
    'timestamp' => intval($timestamp),
    'gamets' => intval($gamets),
    'profile' => $_GET['profile'] ?? '',
    'people' => $incomingPeople,
    'data_preview' => $eventDataPreview,
]);

if ($eventType === 'inputtext_s') {
    $speaker = '';
    $text = trim(strval($eventData));
    $speakerParts = explode(': ', $text, 2);
    if (count($speakerParts) === 2) {
        $speaker = trim(strval($speakerParts[0]));
        $text = trim(strval($speakerParts[1]));
    }

    stobeAppendSttLog([
        'request_id' => strval($GLOBALS['__stobe_request_id'] ?? ''),
        'event_type' => $eventType,
        'speaker' => $speaker,
        'text' => $text,
        'gamets' => intval($gamets),
        'profile' => strval($_GET['profile'] ?? ''),
    ]);
}

// Auto-diary is intentionally disabled for now.
// Manual in-game "Write Diary" button (event_type=diary) is the only trigger.
// if (function_exists('stobeMaybeTriggerAutoDiaryOnNewDay')) {
//     stobeMaybeTriggerAutoDiaryOnNewDay(
//         strval($eventType),
//         intval($timestamp),
//         intval($gamets),
//         strval($eventData)
//     );
// }

// Route event to appropriate processor
try {
    switch ($eventType) {
        case 'inputtext':
        case 'inputtext_s':
            require_once($path . "processor/chat.php");
            break;

        case 'rechat':
            require_once($path . "processor/rechat.php");
            break;

        case 'location':
            require_once($path . "processor/location.php");
            break;

        case 'infoloc':
            require_once($path . "processor/infoloc.php");
            break;

        case 'infonpc':
            require_once($path . "processor/infonpc.php");
            break;

        case 'infoaction':
            require_once($path . "processor/infoaction.php");
            break;

        case 'lockpicked':
        case 'lockpiked':
            require_once($path . "processor/lockpicked.php");
            break;

        case 'context':
            require_once($path . "processor/context.php");
            break;

        case 'bored':
            require_once($path . "processor/bored.php");
            break;

        case 'diary':
            require_once($path . "processor/diary.php");
            break;

        case 'init':
            require_once($path . "processor/init.php");
            break;

        case 'combat_start':
        case 'combat_end':
        case 'knockout':
        case 'recovered':
        case 'limb_loss':
        case 'death':
        case 'slavery':
        case 'item_pickup':
            require_once($path . "processor/combat.php");
            break;

        default:
            storeEvent($eventType, $timestamp, $gamets, $eventData);
            stobeLogWarn('Unhandled event type stored only', ['event_type' => $eventType]);
            echo "ok";
            break;
    }

    if (function_exists('stobeMaybeRunMiddleTermCycle')) {
        stobeMaybeRunMiddleTermCycle(
            strval($eventType),
            intval($timestamp),
            intval($gamets),
            strval($eventData)
        );
    }
    if (function_exists('stobeMaybeRunRegularMemoryCycle')) {
        stobeMaybeRunRegularMemoryCycle(
            strval($eventType),
            intval($timestamp),
            intval($gamets),
            strval($eventData)
        );
    }
    if (function_exists('stobeMaybeRunDynamicProfileCycle')) {
        stobeMaybeRunDynamicProfileCycle(
            strval($eventType),
            intval($timestamp),
            intval($gamets),
            strval($eventData)
        );
    }
} catch (Throwable $exception) {
    stobeLogException($exception, 'Event routing failed', [
        'event_type' => $eventType,
        'gamets' => intval($gamets),
    ]);
    http_response_code(500);
    echo "error";
}

