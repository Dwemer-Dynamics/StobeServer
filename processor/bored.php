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
$pushCandidate = static function (string $candidate) use (&$candidateNames, &$seen): void {
    $name = normalizeParticipantNameToken($candidate);
    if ($name === '') {
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
    if ($playerName !== '' && strcasecmp($candidateName, $playerName) === 0) {
        // Player may be a valid listener target, but never auto-bootstrap as NPC speaker.
        continue;
    }
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

$baseBoredChance = getNpcProfileIntegerSetting(
    is_array($speakerData) ? $speakerData : [],
    ['BORED_EVENT_CHANCE', 'BORED_EVENT'],
    'BORED_EVENT_CHANCE',
    50,
    0,
    100
);
$roll = 0;
$effectiveBoredChance = $baseBoredChance;

$listener = '';
$dialogueData = parseDialogueEventData($eventData);
$suggestedTarget = normalizeParticipantNameToken(strval($dialogueData['target'] ?? ''));
if ($suggestedTarget !== '' &&
    strcasecmp($suggestedTarget, $speakerNpc) !== 0) {
    $listener = $suggestedTarget;
}
$micDecision = null;
if ($listener === '') {
    // Build listener pool with NPC data for MIC scoring
    $listenerPool = [];
    foreach ($candidateNames as $candidateName) {
        if (strcasecmp($candidateName, $speakerNpc) === 0) {
            continue;
        }
        $candData = getNpcData($candidateName);
        if ($candData) {
            $listenerPool[] = $candData;
        } else {
            $listenerPool[] = ['name' => $candidateName];
        }
    }
    if (count($listenerPool) > 0) {
        $micDecision = stobeBuildMicDecision(
            is_array($speakerData) ? $speakerData : [],
            $listenerPool,
            $playerName,
            'bored'
        );
        // Use top MIC candidate if scoring is available, otherwise random fallback
        if (!empty($micDecision['target_candidates'])) {
            $listener = strval($micDecision['target_candidates'][0]['name'] ?? '');
        }
        if ($listener === '') {
            $allNames = array_column($listenerPool, 'name');
            $listener = $allNames[array_rand($allNames)] ?? '';
        }
    }
}
if ($listener === '') {
    stobeLogInfo('Bored event skipped: no eligible listener', [
        'speaker' => $speakerNpc,
        'candidate_count' => count($candidateNames),
    ]);
    echo "ok";
    return;
}

// --- Configurable Bored Frequency Gate ---
// Frequency is now tunable via general_settings:
// - BORED_DECISION_FREQUENCY_MULTIPLIER (float, default 1.5)
// - BORED_DECISION_URGENCY_BONUS_MAX (int 0-100, default 25)
// Effective chance = base profile chance * multiplier + urgency bonus.
$micUrgency = intval($micDecision['urgency'] ?? 20);
$freqMultiplierRaw = getSettingFloat('BORED_DECISION_FREQUENCY_MULTIPLIER', 1.5);
$freqMultiplier = max(0.1, min(5.0, $freqMultiplierRaw));
$urgencyBonusMaxRaw = getSettingInt('BORED_DECISION_URGENCY_BONUS_MAX', 25);
$urgencyBonusMax = max(0, min(100, $urgencyBonusMaxRaw));
$urgencyBonus = intval(round(($micUrgency / 100.0) * $urgencyBonusMax));
$effectiveBoredChance = intval(round(($baseBoredChance * $freqMultiplier) + $urgencyBonus));
$effectiveBoredChance = max(0, min(100, $effectiveBoredChance));

$roll = mt_rand(0, 99);
if (!$forceDirectorMode && $roll >= $effectiveBoredChance) {
    stobeLogInfo('Bored event skipped: chance gate', [
        'speaker' => $speakerNpc,
        'roll' => $roll,
        'base_chance' => $baseBoredChance,
        'effective_chance' => $effectiveBoredChance,
        'frequency_multiplier' => $freqMultiplier,
        'mic_urgency' => $micUrgency,
        'urgency_bonus' => $urgencyBonus,
    ]);
    echo "ok";
    return;
}

// --- Conversation Floor Gate ---
// Prevents multiple NPCs from speaking simultaneously in the same scene.
// Urgency (from MIC) determines who wins when the floor is occupied.
// A minimum hold time after each speech also ensures the previous speaker's
// response is written to the event log before the next NPC builds context.
$sceneKey = stobeComputeSceneKey($candidateNames);
$micTopic   = strval($micDecision['intent_topic'] ?? 'general');
$floorGranted = $forceDirectorMode
    ? true  // director mode bypasses floor entirely
    : stobeAcquireConversationFloor($sceneKey, $speakerNpc, $micUrgency, $micTopic);
if (!$floorGranted) {
    $currentFloor = stobeGetConversationFloor($sceneKey);
    stobeLogInfo('Bored event skipped: floor occupied by higher/equal urgency speaker', [
        'speaker'         => $speakerNpc,
        'speaker_urgency' => $micUrgency,
        'floor_holder'    => strval($currentFloor['speaker'] ?? '?'),
        'floor_urgency'   => intval($currentFloor['urgency'] ?? 0),
        'floor_topic'     => strval($currentFloor['topic'] ?? '?'),
        'scene_key'       => $sceneKey,
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
// Context freshness: re-fetch event history right before building the prompt.
// This ensures that if another NPC just spoke (and released the floor), their
// line is visible in the context before this NPC generates their own speech.
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
$prmkBlock = stobeBuildPrmkContextBlock(
    is_array($speakerData) ? $speakerData : [],
    is_array(($speakerData['metadata'] ?? null)) ? $speakerData['metadata'] : [],
    'bored',
    strval(max(0, intval(floatval(trim(getConfOpt('PLAYER_CATS', '0'))))))
);
if ($prmkBlock !== '') {
    $systemPrompt .= "\n\n" . $prmkBlock;
}
if ($micDecision !== null) {
    $micBlock = stobeMicBuildPromptBlock($micDecision);
    if ($micBlock !== '') {
        $systemPrompt .= "\n\n" . $micBlock;
    }
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
    if (!$forceDirectorMode) {
        stobeReleaseConversationFloor($sceneKey, $speakerNpc);
    }
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
    if (!$forceDirectorMode) {
        stobeReleaseConversationFloor($sceneKey, $speakerNpc);
    }
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

// Release the conversation floor now that the response has been stored.
// The floor will remain in a cooldown state for FLOOR_MIN_HOLD_SECONDS (8 s)
// so the next NPC to attempt speech will see a fresh event log.
if (!$forceDirectorMode) {
    stobeReleaseConversationFloor($sceneKey, $speakerNpc);
}

stobeLogInfo('Bored event response generated', [
    'speaker' => $speakerNpc,
    'listener' => $listener,
    'force_director_mode' => $forceDirectorMode,
    'roll' => $roll,
    'base_chance' => $baseBoredChance,
    'effective_chance' => $effectiveBoredChance,
    'mic_urgency' => $micUrgency,
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
