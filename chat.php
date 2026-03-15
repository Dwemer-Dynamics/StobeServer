<?php

/**
 * JSON chat endpoint used by the in-game ChatMenu async pipeline.
 * Request:  { npc, player, message, mode, gamets, nearby, context, npcs }
 * Response: { ok, npc, text, actions, mode }
 */

$path = dirname(__FILE__) . DIRECTORY_SEPARATOR;
require($path . "lib/bootstrap.php");

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    return;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    return;
}

$targetNpc = trim(strval($payload['npc'] ?? ''));
$speaker = trim(strval($payload['player'] ?? getSetting('PLAYER_NAME', 'Drifter')));
$message = trim(strval($payload['message'] ?? ''));
$targetExtract = extractDialogueTarget($message);
$cleanedMessage = trim(strval($targetExtract['cleaned'] ?? ''));
if ($cleanedMessage !== '') {
    $message = $cleanedMessage;
}
$extractedTargetNpc = normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));
if ($targetNpc === '' && $extractedTargetNpc !== '') {
    $targetNpc = $extractedTargetNpc;
}
$sanitizeChatMessage = static function (string $value): string {
    $clean = sanitizeForKenshi(trim($value));
    if ($clean !== '' && function_exists('stobeSanitizeDialogueMessageForLog')) {
        $clean = stobeSanitizeDialogueMessageForLog($clean);
    }
    return trim($clean);
};
$message = $sanitizeChatMessage($message);
$mode = strtolower(trim(strval($payload['mode'] ?? 'talk')));
$gamets = intval($payload['gamets'] ?? 0);
$nearby = is_array($payload['nearby'] ?? null) ? $payload['nearby'] : [];
$context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
$npcs = is_array($payload['npcs'] ?? null) ? $payload['npcs'] : [];

if (function_exists('stobeHandlePotentialGametsRollback')) {
    stobeHandlePotentialGametsRollback($gamets, 'chat_json');
}

if ($targetNpc === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing npc or message']);
    return;
}

$allowedModes = ['talk', 'shout', 'whisper', 'autochat', 'cheat'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'talk';
}

$ingestRows = [];
$ingestSource = 'chat_payload';
if (count($context) > 0) {
    $contextSnapshot = $context;
    if (!array_key_exists('name', $contextSnapshot) || trim(strval($contextSnapshot['name'])) === '') {
        $contextSnapshot['name'] = $targetNpc;
    }
    $ingestRows[] = [
        'kind' => 'context_target',
        'entry' => $contextSnapshot,
    ];
}
foreach ($nearby as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $ingestRows[] = [
        'kind' => 'nearby',
        'entry' => $entry,
    ];
}

$ingestAttempted = 0;
$ingestStored = 0;
$ingestRenamed = 0;
$ingestRenamedStored = 0;
$ingestRenamedFailed = 0;
foreach ($ingestRows as $row) {
    $entry = is_array($row['entry'] ?? null) ? $row['entry'] : [];
    $entryName = normalizeParticipantNameToken(strval($entry['name'] ?? ''));
    if ($entryName === '') {
        continue;
    }
    $entry['name'] = $entryName;
    $ingestAttempted++;
    $isRenamed = strpos($entryName, '[') !== false;
    if ($isRenamed) {
        $ingestRenamed++;
    }

    $storedSnapshot = storeNpcSnapshot($entry, max(0, $gamets));
    if ($storedSnapshot) {
        $ingestStored++;
        if ($isRenamed) {
            $ingestRenamedStored++;
        }
        continue;
    }

    if ($isRenamed) {
        $ingestRenamedFailed++;
        stobeLogImport('chat payload renamed snapshot ingest failed', [
            'name' => $entryName,
            'storage_id' => normalizeStorageIdToken($entry['storage_id'] ?? ''),
            'kind' => strval($row['kind'] ?? ''),
            'source' => $ingestSource,
            'keys' => array_keys($entry),
            'has_medical' => is_array($entry['medical'] ?? null) && count($entry['medical']) > 0,
            'has_stats' => is_array($entry['stats'] ?? null) && count($entry['stats']) > 0,
        ], 'WARN');
    }
}

if ($ingestAttempted > 0) {
    stobeLogImport('chat payload snapshot ingest summary', [
        'source' => $ingestSource,
        'attempted' => $ingestAttempted,
        'stored' => $ingestStored,
        'renamed' => $ingestRenamed,
        'renamed_stored' => $ingestRenamedStored,
        'renamed_failed' => $ingestRenamedFailed,
    ], $ingestRenamedFailed > 0 ? 'WARN' : 'DEBUG');
}

$participantIdentities = extractParticipantIdentities([
    'people' => $payload['people'] ?? [],
    'profile' => $targetNpc,
    'speaker' => $speaker,
    'npcs' => $npcs,
    'nearby' => $nearby,
]);
$ensureResult = ensureNpcProfilesFromParticipantIdentities($participantIdentities);

stobeLogInfo('JSON chat request received', [
    'target_npc' => $targetNpc,
    'speaker' => $speaker,
    'mode' => $mode,
    'gamets' => $gamets,
    'nearby_count' => count($nearby),
    'npcs_count' => count($npcs),
    'participants_count' => intval($ensureResult['parsed'] ?? 0),
    'profiles_created' => intval($ensureResult['created'] ?? 0),
    'profiles_updated' => intval($ensureResult['updated'] ?? 0),
]);

$npcData = getNpcData($targetNpc);
if (!$npcData) {
    storeNpcProfile($targetNpc, []);
    $npcData = getNpcData($targetNpc);
    stobeLogInfo('NPC profile JIT-created via chat.php', ['target_npc' => $targetNpc]);
} elseif (npcNeedsBootstrap($npcData)) {
    storeNpcProfile($targetNpc, []);
    $npcData = getNpcData($targetNpc) ?: $npcData;
    stobeLogInfo('NPC profile baseline refreshed via chat.php', ['target_npc' => $targetNpc]);
}

if (!$npcData) {
    $npcData = [
        'name' => $targetNpc,
        'race' => 'Unknown',
        'faction' => '',
        'gender' => '',
    ];
}

$canonicalTargetNpc = normalizeParticipantNameToken(strval($npcData['name'] ?? ''));
if ($canonicalTargetNpc !== '' && strcasecmp($canonicalTargetNpc, $targetNpc) !== 0) {
    stobeLogInfo('JSON chat target remapped to canonical NPC name', [
        'requested_target_npc' => $targetNpc,
        'resolved_target_npc' => $canonicalTargetNpc,
    ]);
    $targetNpc = $canonicalTargetNpc;
}

$contextHistory = getNpcProfileIntegerSetting(
    $npcData,
    ['CONTEXT_HISTORY'],
    '',
    50,
    10,
    250
);
$eventHistory = DataEventLog($contextHistory, $targetNpc);
$historyLines = [];
foreach (array_reverse($eventHistory) as $row) {
    $historyType = $row['type'] ?? 'event';
    $historyData = $row['data'] ?? '';
    if ($historyData !== '') {
        $historyLines[] = '[' . $historyType . '] ' . $historyData;
    }
}
$historyText = implode("\n", $historyLines);
$historyMessages = stobeBuildRecentContextMessages($eventHistory, intval($gamets));

$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/openaijson.php');

if ($mode === 'autochat') {
    $rewriteResult = rewriteSpeakerMessageForAutochat(
        $speaker,
        $targetNpc,
        $npcData,
        $message,
        $historyText
    );
    if (boolval($rewriteResult['rewritten'] ?? false)) {
        $rewrittenMessage = $sanitizeChatMessage(trim(strval($rewriteResult['message'] ?? $message)));
        if ($rewrittenMessage !== '') {
            $message = $rewrittenMessage;
        }
        stobeLogInfo('JSON autochat rewrite generated', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'rewritten_length' => strlen($message),
        ]);
    } else {
        stobeLogInfo('JSON autochat rewrite fallback', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'reason' => strval($rewriteResult['error'] ?? 'unknown'),
        ]);
    }
} elseif ($mode === 'cheat') {
    stobeLogInfo('JSON cheat mode active', [
        'speaker' => $speaker,
        'target_npc' => $targetNpc,
        'message_length' => strlen($message),
    ]);
}

$eventType = 'inputtext';
$message = $sanitizeChatMessage($message);
$eventData = $speaker . ': ' . $message . ' (talking to: ' . $targetNpc . ')';
storeEvent($eventType, time(), $gamets, $eventData);

$systemPrompt = stobeBuildGameTimePromptBlock($gamets)
    . "\n\n"
    . buildSystemPrompt($targetNpc, $npcData, $speaker, $message, true, 'chat', intval($gamets));
$nearbyPlayerAlliesPrompt = buildNearbyPlayerAlliesPrompt($nearby, $speaker);
if ($nearbyPlayerAlliesPrompt !== '') {
    $systemPrompt .= "\n\n" . $nearbyPlayerAlliesPrompt;
}
$deliveryStyleInstruction = '';
if ($mode === 'whisper') {
    $deliveryStyleInstruction = 'The player is whispering. Respond in a quiet, discreet tone.';
} elseif ($mode === 'shout') {
    $deliveryStyleInstruction = 'The player is shouting. Respond with urgency and stronger emotional intensity.';
} elseif ($mode === 'autochat') {
    $deliveryStyleInstruction = 'The player triggered a bored-event automatic chat. Keep responses brief and natural for overheard conversation.';
}
if ($deliveryStyleInstruction !== '') {
    $systemPrompt .= "\n\n<speech_mode>\n"
        . "  <mode>" . stobePromptXmlEscape($mode) . "</mode>\n"
        . "  <instruction>" . stobePromptXmlEscape($deliveryStyleInstruction) . "</instruction>\n"
        . "</speech_mode>";
}
$userMessage = "<player_input>\n"
    . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
    . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
    . "  <mode>" . stobePromptXmlEscape($mode) . "</mode>\n"
    . "  <text>" . stobePromptXmlEscape($message) . "</text>\n"
    . "</player_input>";
if ($mode === 'cheat') {
    $priorityInstruction = "PRIORITY INSTRUCTION - {$targetNpc} must do this, even if it breaks character roleplay: {$message}";
    $systemPrompt .= "\n\n<cheatmode>\n"
        . "  <priority_instruction>" . stobePromptXmlEscape($priorityInstruction) . "</priority_instruction>\n"
        . "</cheatmode>";
    $userMessage = "<cheat_request>\n"
        . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <request>" . stobePromptXmlEscape($message) . "</request>\n"
        . "</cheat_request>";
}
if (!empty($nearby)) {
    $nearbyJson = json_encode($nearby, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($nearbyJson) && $nearbyJson !== '') {
        $systemPrompt .= "\n\n<nearby_context_json>"
            . stobePromptXmlEscape($nearbyJson)
            . "</nearby_context_json>";
    }
}
if (!empty($context)) {
    $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (is_string($contextJson) && $contextJson !== '') {
        $systemPrompt .= "\n\n<detailed_context_json>"
            . stobePromptXmlEscape($contextJson)
            . "</detailed_context_json>";
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
    'content' => $userMessage,
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildTurnGuidanceUserPrompt($targetNpc, $speaker),
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildOutputContractUserPrompt($targetNpc, $mode === 'cheat'),
];

$llmConfig = getLlmConfigForNpc($npcData);

$responseText = '';
$structuredParsed = false;
if ($llmConfig['api_key'] === '') {
    $responseText = 'No OpenRouter API key configured yet.';
    stobeLogWarn('JSON chat LLM call skipped because API key is missing', ['target_npc' => $targetNpc]);
} else {
    $rawResponse = callLLM($messages, $llmConfig, [
        'npc_name' => $targetNpc,
        'event_type' => 'chat',
        'speaker' => $speaker,
        'mode' => $mode,
        'response_format' => ['type' => 'json_object'],
    ]);
    if ($rawResponse === false || trim($rawResponse) === '') {
        $responseText = '...';
        stobeLogWarn('JSON chat LLM returned empty response', [
            'target_npc' => $targetNpc,
            'model' => $llmConfig['model'] ?? '',
        ]);
    } else {
        $structured = stobeParseStructuredDialogueResponse($rawResponse, 'chat');
        $structuredParsed = boolval($structured['is_structured'] ?? false);
        $responseText = sanitizeForKenshi(trim(strval($structured['message'] ?? '')));
        $structuredAction = trim(strval($structured['action_tag'] ?? ''));
        if ($structuredAction !== '') {
            $actions = [$structuredAction];
        } else {
            $actions = [];
        }
    }
}

$actionExtraction = extractAndNormalizeActionTags($responseText, 'chat');
$cleanText = sanitizeForKenshi(trim(strval($actionExtraction['text'] ?? $responseText)));
$inlineActions = is_array($actionExtraction['actions'] ?? null) ? $actionExtraction['actions'] : [];
if (!isset($actions) || !is_array($actions)) {
    $actions = [];
}
foreach ($inlineActions as $inlineAction) {
    if (!in_array($inlineAction, $actions, true)) {
        $actions[] = $inlineAction;
    }
}
if ($cleanText === '' && count($actions) === 0) {
    $cleanText = '...';
}

$storedText = $cleanText;
if ($storedText === '' && count($actions) > 0) {
    $storedText = '[action issued]';
} elseif ($storedText === '') {
    $storedText = '...';
}

storeActionEvents($targetNpc, $actions, $gamets, $speaker, 'chat');
storeEvent('chat', time(), $gamets, $targetNpc . ': ' . $storedText);

stobeLogInfo('JSON chat response generated', [
    'target_npc' => $targetNpc,
    'mode' => $mode,
    'response_length' => strlen($cleanText),
    'structured_json' => $structuredParsed,
    'actions_count' => count($actions),
]);

echo json_encode(
    [
        'ok' => true,
        'npc' => $targetNpc,
        'text' => $cleanText,
        'actions' => $actions,
        'mode' => $mode,
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

