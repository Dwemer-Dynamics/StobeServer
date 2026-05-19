<?php

/**
 * Init processor - records game-load events and optionally triggers narrator welcome.
 */

storeEvent($eventType, $timestamp, $gamets, $eventData);

$narrator = stobeGetNarrator();
if (!$narrator) {
    stobeLogWarn('Init processed without narrator instance');
    echo "ok";
    return;
}

if (!$narrator->getBool('enabled', true) || !$narrator->getBool('welcome_enabled', false)) {
    stobeLogDebug('Init processed without narrator welcome', [
        'narrator_enabled' => $narrator->getBool('enabled', true) ? 'true' : 'false',
        'welcome_enabled' => $narrator->getBool('welcome_enabled', false) ? 'true' : 'false',
        'gamets' => intval($gamets),
    ]);
    echo "ok";
    return;
}

$cooldownMinutes = max(0, intval($narrator->getInt('welcome_cooldown', 10)));
$cooldownSeconds = $cooldownMinutes * 60;
$nowTs = time();
$lastWelcomeTs = intval(getConfOpt('last_narrator_welcome', '0'));

if ($cooldownSeconds > 0 && $lastWelcomeTs > 0) {
    $secondsSinceLast = $nowTs - $lastWelcomeTs;
    if ($secondsSinceLast < $cooldownSeconds) {
        stobeLogInfo('Narrator welcome skipped due to cooldown', [
            'seconds_since_last' => intval($secondsSinceLast),
            'cooldown_seconds' => intval($cooldownSeconds),
            'gamets' => intval($gamets),
        ]);
        echo "ok";
        return;
    }
}

$narratorName = stobeNarratorName();
$speaker = '';
$eventParts = explode(': ', strval($eventData), 2);
if (count($eventParts) === 2) {
    $speaker = normalizeParticipantNameToken(strval($eventParts[0] ?? ''));
}
if ($speaker === '' || strcasecmp($speaker, 'Unknown') === 0) {
    $speaker = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
}
if ($speaker === '' || strcasecmp($speaker, 'Unknown') === 0) {
    $peopleRaw = trim(strval($_GET['people'] ?? ($GLOBALS['CACHE_PEOPLE'] ?? '')));
    if ($peopleRaw !== '') {
        $decodedPeople = json_decode($peopleRaw, true);
        if (is_array($decodedPeople)) {
            foreach ($decodedPeople as $candidate) {
                $candidateName = normalizeParticipantNameToken(strval($candidate));
                if ($candidateName === '' || strcasecmp($candidateName, $narratorName) === 0) {
                    continue;
                }
                $speaker = $candidateName;
                break;
            }
        }
    }
}
if ($speaker === '') {
    $speaker = 'Drifter';
}
$speakerData = getNpcData($speaker);
if (!is_array($speakerData)) {
    $speakerData = [];
}

$speakerPeopleScope = $speaker . '|' . $narratorName;
$priorPeopleScope = strval($GLOBALS['CACHE_PEOPLE'] ?? '');
$GLOBALS['CACHE_PEOPLE'] = $speakerPeopleScope;

$welcomeTriggerData = 'Narrator welcome message triggered on game load';
storeEvent('narrator_welcome', intval($timestamp), intval($gamets), $welcomeTriggerData, 'complete');
setConfOpt('last_narrator_welcome', strval($nowTs));

$narratorData = stobeBuildNarratorNpcData();
$contextLimit = getNpcProfileIntegerSetting(
    $narratorData,
    ['CONTEXT_HISTORY'],
    '',
    80,
    10,
    250
);
$contextHistory = DataEventLog($contextLimit);
if (function_exists('stobeFilterNarratorPromptContextRows')) {
    $contextHistory = stobeFilterNarratorPromptContextRows($contextHistory);
}
$historyMessages = stobeBuildRecentContextMessages($contextHistory, intval($gamets));

$welcomeInstruction = 'Give a brief 2-3 sentence recap of recent events and welcome the speaker back to their journey.';
$systemPrompt = stobeBuildGameTimePromptBlock(intval($gamets), $speakerData)
    . "\n\n"
    . buildSystemPrompt(
        $narratorName,
        $narratorData,
        $speaker,
        $welcomeInstruction,
        false,
        'narrator_welcome',
        intval($gamets)
    )
    . "\n\n<speech_mode>\n"
    . "  <mode>narrator</mode>\n"
    . "  <instruction>You are The Narrator delivering a private welcome on game load. Address only the speaker and do not emit action tags.</instruction>\n"
    . "</speech_mode>";

$userContent = "<narrator_welcome_event>\n"
    . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
    . "  <target>" . stobePromptXmlEscape($narratorName) . "</target>\n"
    . "  <trigger>game_load</trigger>\n"
    . "  <instruction>" . stobePromptXmlEscape($welcomeInstruction) . "</instruction>\n"
    . "</narrator_welcome_event>";

$messages = [
    [
        'role' => 'system',
        'content' => $systemPrompt,
    ],
];
foreach ($historyMessages as $historyMessage) {
    $messages[] = $historyMessage;
}
$messages[] = [
    'role' => 'user',
    'content' => $userContent,
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildTurnGuidanceUserPrompt($narratorName, $speaker),
];
$messages[] = [
    'role' => 'user',
    'content' => 'Output contract: return narrator dialogue text only. Do not include action tags.',
];

$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');

$llmConfig = getLlmConfigForNpc($narratorData);
$responseText = '';

try {
    if ($llmConfig['api_key'] === '') {
        stobeLogWarn('Narrator welcome fallback: missing API key');
    } else {
        $rawResponse = stobeCallLLM(
            $messages,
            $llmConfig,
            [
                'npc_name' => $narratorName,
                'event_type' => 'narrator_welcome',
                'speaker' => $speaker,
            ]
        );

        if (is_string($rawResponse) && trim($rawResponse) !== '') {
            $structured = stobeParseStructuredDialogueResponse($rawResponse, 'chat');
            $candidate = trim(strval($structured['message'] ?? ''));
            if ($candidate === '') {
                $candidate = trim(strval($rawResponse));
            }
            $responseText = stobeStripParentheticalDialogueText(
                sanitizeForKenshi($candidate)
            );
        }
    }
} catch (Throwable $exception) {
    stobeLogException($exception, 'Narrator welcome generation failed');
}

if ($responseText === '') {
    $responseText = 'Welcome back. The wastes have not slowed down while you were away.';
}

streamResponse($narratorName, 'ScriptQueue', $responseText, $narratorData, [], 'chat', $speaker, intval($gamets));

$GLOBALS['CACHE_PEOPLE'] = $priorPeopleScope;
echo "ok";
