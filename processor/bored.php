<?php

/**
 * Bored processor - spontaneous nearby NPC dialogue trigger.
 */

storeEvent($eventType, $timestamp, $gamets, $eventData);

$campaign = 'Default';
$requestMode = strtolower(trim(strval($_GET['mode'] ?? '')));
$forceDirectorMode = ($requestMode === 'director');
$playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
$incomingProfile = normalizeParticipantNameToken(trim(strval($_GET['profile'] ?? '')));
$peopleRaw = strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? ''));

$participants = extractParticipantNames([
    'people' => $peopleRaw,
    'profile' => $incomingProfile,
]);

$candidateNames = [];
$seen = [];
$pushCandidate = static function (string $candidate) use (&$candidateNames, &$seen, $playerName): void {
    $name = normalizeParticipantNameToken($candidate);
    if ($name === '') {
        return;
    }
    if ($playerName !== '' && strcasecmp($name, $playerName) === 0) {
        return;
    }
    $key = strtolower($name);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $candidateNames[] = $name;
};

if ($incomingProfile !== '') {
    $pushCandidate($incomingProfile);
}
if (count($participants) > 1) {
    shuffle($participants);
}
foreach ($participants as $participant) {
    $pushCandidate(strval($participant));
}

if (count($candidateNames) === 0) {
    stobeLogInfo('Bored event skipped: no NPC candidates', [
        'event_type' => $eventType,
        'profile' => $incomingProfile,
        'people' => $peopleRaw,
    ]);
    echo "ok";
    return;
}

$speakerNpc = '';
$speakerData = false;
foreach ($candidateNames as $candidateName) {
    $candidateData = getNpcData($candidateName);
    if (!$candidateData) {
        storeNpcProfile($candidateName, []);
        $candidateData = getNpcData($candidateName);
    } elseif (npcNeedsBootstrap($candidateData)) {
        storeNpcProfile($candidateName, []);
        $candidateData = getNpcData($candidateName) ?: $candidateData;
    }
    if (!$candidateData) {
        continue;
    }
    $speakerNpc = $candidateName;
    $speakerData = $candidateData;
    break;
}

if ($speakerNpc === '' || !$speakerData) {
    stobeLogInfo('Bored event skipped: speaker profile unavailable', [
        'candidate_count' => count($candidateNames),
    ]);
    echo "ok";
    return;
}

$boredChance = getNpcProfileIntegerSetting(
    is_array($speakerData) ? $speakerData : [],
    ['BORED_EVENT_CHANCE', 'BORED_EVENT'],
    'BORED_EVENT_CHANCE',
    50,
    0,
    100
);
$roll = mt_rand(0, 99);
if (!$forceDirectorMode && $roll >= $boredChance) {
    stobeLogInfo('Bored event skipped: chance gate', [
        'speaker' => $speakerNpc,
        'roll' => $roll,
        'chance' => $boredChance,
    ]);
    echo "ok";
    return;
}

$listener = '';
$dialogueData = parseDialogueEventData($eventData);
$suggestedTarget = normalizeParticipantNameToken(strval($dialogueData['target'] ?? ''));
if ($suggestedTarget !== '' &&
    strcasecmp($suggestedTarget, $speakerNpc) !== 0 &&
    ($forceDirectorMode || $playerName === '' || strcasecmp($suggestedTarget, $playerName) !== 0)) {
    $listener = $suggestedTarget;
}
if ($listener === '') {
    $listeners = [];
    foreach ($candidateNames as $candidateName) {
        if (strcasecmp($candidateName, $speakerNpc) === 0) {
            continue;
        }
        $listeners[] = $candidateName;
    }
    if (count($listeners) > 0) {
        $listener = $listeners[array_rand($listeners)];
    }
}
if ($listener === '') {
    stobeLogInfo('Bored event skipped: no eligible NPC listener', [
        'speaker' => $speakerNpc,
        'candidate_count' => count($candidateNames),
    ]);
    echo "ok";
    return;
}

$cuePool = [
    'comment on the current location',
    'remark on the weather or atmosphere',
    'share a practical survival thought',
    'mention a rumor from nearby settlements',
    'reflect on recent dangers in the area',
    'make a quick comment about local factions',
    'talk about work, trade, or supplies',
    'share a short personal observation',
];
$cue = $cuePool[array_rand($cuePool)];

$contextHistory = getNpcProfileIntegerSetting(
    is_array($speakerData) ? $speakerData : [],
    ['CONTEXT_HISTORY'],
    '',
    30,
    10,
    120
);
$eventHistory = DataEventLog($contextHistory, $speakerNpc, $campaign);
$eventHistory = stobeFilterNarratorRowsForContext($eventHistory, $speakerNpc, 'bored');
$historyLines = [];
foreach (array_reverse($eventHistory) as $row) {
    $line = stobeFormatEventHistoryLine($row, true);
    if ($line === '') {
        continue;
    }
    $historyLines[] = $line;
}
$historyText = implode("\n", $historyLines);
$historyMessages = stobeBuildRecentContextMessages($eventHistory, intval($gamets));
$memoryContextMessages = stobeBuildMemoryEventContextMessages(
    is_array($speakerData) ? $speakerData : [],
    $speakerNpc,
    $cue,
    intval($gamets)
);
if (count($memoryContextMessages) > 0) {
    $historyMessages = array_merge($historyMessages, $memoryContextMessages);
}

$systemPrompt = stobeBuildGameTimePromptBlock($gamets, is_array($speakerData) ? $speakerData : [])
    . "\n\n"
    . buildSystemPrompt($speakerNpc, is_array($speakerData) ? $speakerData : [], $listener, '', false, 'bored', intval($gamets));
$nearbyPartyPrompt = stobeBuildNearbyPlayerFactionPartyPrompt($speakerData, $speakerNpc);
if ($nearbyPartyPrompt !== '') {
    $systemPrompt .= "\n\n" . $nearbyPartyPrompt;
}
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
    'content' => "<bored_event_request>\n"
        . "  <speaker>" . stobePromptXmlEscape($speakerNpc) . "</speaker>\n"
        . "  <listener>" . stobePromptXmlEscape($listener) . "</listener>\n"
        . "  <instruction>Start a brief spontaneous conversation to the listener about the current situation.</instruction>\n"
        . "</bored_event_request>",
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildTurnGuidanceUserPrompt($speakerNpc, $listener),
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildOutputContractUserPrompt(
        $speakerNpc,
        false,
        false,
        npcIsInPlayerFaction($speakerData)
    ),
];

$llmConfig = getLlmConfigForNpc($speakerData);
$actionConfig = stobeBuildActionConfigForNpc('bored', $speakerData);
$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');

if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
    stobeLogWarn('Bored event skipped: missing API key', ['speaker' => $speakerNpc]);
    echo "ok";
    return;
}

$responseText = '';
$responseActions = [];
$alreadyStreamed = false;
$structuredJson = false;
$actionsStreamedInLlm = false;

$streamResult = stobeStreamDialogueViaLlm(
    $speakerNpc,
    $speakerData,
    $messages,
    $llmConfig,
    'bored',
    [
        'npc_name' => $speakerNpc,
        'event_type' => 'bored',
        'action_config' => $actionConfig,
        'response_format' => ['type' => 'json_object'],
    ]
);

if (boolval($streamResult['ok'] ?? false)) {
    $responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
    $responseActions = is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [];
    $alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
    $structuredJson = boolval($streamResult['structured_json'] ?? false);
    $actionsStreamedInLlm = boolval($streamResult['actions_streamed'] ?? false);
} else {
    stobeLogWarn('Bored event LLM stream failed', ['speaker' => $speakerNpc]);
    echo "ok";
    return;
}
$responseActions = stobeDedupeActionList($responseActions, 'bored', $actionConfig);
$relationshipEval = stobeEvaluateRelationshipsForTurn(
    $speakerNpc,
    $listener,
    $eventData,
    $responseText,
    $speakerData,
    'bored'
);
$responseText = stobeStripParentheticalDialogueText(
    sanitizeForKenshi(trim(strval($relationshipEval['clean_response'] ?? $responseText)))
);

if ($responseText === '' && count($responseActions) === 0) {
    echo "ok";
    return;
}

$responseTextForStore = $responseText;
if ($responseTextForStore === '' && count($responseActions) > 0) {
    $responseTextForStore = '[action issued]';
}

$chatEventData = $speakerNpc . ': ' . $responseTextForStore . ' (talking to: ' . $listener . ')';
storeActionEvents($speakerNpc, $responseActions, $gamets, $listener, 'bored');
storeEvent('chat', time(), $gamets, $chatEventData);

stobeLogInfo('Bored event response generated', [
    'speaker' => $speakerNpc,
    'listener' => $listener,
    'force_director_mode' => $forceDirectorMode,
    'roll' => $roll,
    'chance' => $boredChance,
    'response_length' => strlen($responseText),
    'structured_json' => $structuredJson,
    'actions_count' => count($responseActions),
    'actions' => $responseActions,
    'already_streamed' => $alreadyStreamed,
    'actions_streamed' => $actionsStreamedInLlm,
]);

if ($alreadyStreamed) {
    if (count($responseActions) > 0 && !$actionsStreamedInLlm) {
        streamResponse($speakerNpc, 'ScriptQueue', '', $speakerData, $responseActions);
    }
} else {
    streamResponse($speakerNpc, 'ScriptQueue', $responseText, $speakerData, $responseActions);
}
