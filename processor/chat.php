<?php

/**
 * Chat processor - handles player dialogue input.
 */

if (!function_exists('stobeNormalizeManualChatActionKey')) {
    function stobeNormalizeManualChatActionKey(string $rawAction): string
    {
        $normalized = strtolower(trim($rawAction));
        $allowed = [
            'remove_limb_left_arm',
            'remove_limb_right_arm',
            'remove_limb_left_leg',
            'remove_limb_right_leg',
        ];
        if (!in_array($normalized, $allowed, true)) {
            return '';
        }
        return $normalized;
    }
}

if (!function_exists('stobeManualChatActionLimbToken')) {
    function stobeManualChatActionLimbToken(string $actionKey): string
    {
        return match (strtolower(trim($actionKey))) {
            'remove_limb_left_arm' => 'LEFT_ARM',
            'remove_limb_right_arm' => 'RIGHT_ARM',
            'remove_limb_left_leg' => 'LEFT_LEG',
            'remove_limb_right_leg' => 'RIGHT_LEG',
            default => '',
        };
    }
}

if (!function_exists('stobeManualChatActionLimbLabel')) {
    function stobeManualChatActionLimbLabel(string $actionKey): string
    {
        return match (strtolower(trim($actionKey))) {
            'remove_limb_left_arm' => 'left arm',
            'remove_limb_right_arm' => 'right arm',
            'remove_limb_left_leg' => 'left leg',
            'remove_limb_right_leg' => 'right leg',
            default => 'limb',
        };
    }
}

if (!function_exists('stobeManualActionTargetCannotSpeak')) {
    function stobeManualActionTargetCannotSpeak(array $npcData): bool
    {
        $cannotSpeakStates = ['dead', 'unconscious'];

        $characterState = strtolower(trim(strval($npcData['character_state'] ?? '')));
        if (in_array($characterState, $cannotSpeakStates, true)) {
            return true;
        }

        $metadataRaw = $npcData['metadata'] ?? [];
        $metadata = [];
        if (is_array($metadataRaw)) {
            $metadata = $metadataRaw;
        } elseif (is_string($metadataRaw) && trim($metadataRaw) !== '') {
            $decoded = json_decode($metadataRaw, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }
        if (is_array($metadata)) {
            $metaState = strtolower(trim(strval($metadata['character_state'] ?? '')));
            if (in_array($metaState, $cannotSpeakStates, true)) {
                return true;
            }
            $metaMedical = $metadata['medical'] ?? null;
            if (is_array($metaMedical) && !empty($metaMedical['is_unconscious'])) {
                return true;
            }
        }

        $extendedRaw = $npcData['extended_data'] ?? [];
        $extended = [];
        if (is_array($extendedRaw)) {
            $extended = $extendedRaw;
        } elseif (is_string($extendedRaw) && trim($extendedRaw) !== '') {
            $decoded = json_decode($extendedRaw, true);
            if (is_array($decoded)) {
                $extended = $decoded;
            }
        }
        if (is_array($extended)) {
            $extMedical = $extended['medical'] ?? null;
            if (is_array($extMedical) && !empty($extMedical['is_unconscious'])) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('stobeBuildManualActionPainFallback')) {
    function stobeBuildManualActionPainFallback(
        string $targetNpc,
        string $actorName,
        string $actionKey
    ): string {
        $safeTarget = trim($targetNpc) !== '' ? trim($targetNpc) : 'The target';
        $safeActor = trim($actorName) !== '' ? trim($actorName) : 'the attacker';
        $limbLabel = stobeManualChatActionLimbLabel($actionKey);
        $notice = $safeTarget . ' convulses in overwhelming pain as '
            . $safeActor . ' saws into their ' . $limbLabel . '.';
        return 'ROLEPLAY_ACTION@' . $notice;
    }
}

// $eventData, $eventType, $timestamp, $gamets are set by main.php.
$parts = explode(": ", $eventData, 2);
$speaker = $parts[0] ?? getSetting('PLAYER_NAME', 'Drifter');
$message = $parts[1] ?? $eventData;
$message = trim($message);
$targetExtract = extractDialogueTarget($message);
$cleanedMessage = trim(strval($targetExtract['cleaned'] ?? ''));
if ($cleanedMessage !== '') {
    $message = $cleanedMessage;
}

$messagePreview = $message;
if (strlen($messagePreview) > 180) {
    $messagePreview = substr($messagePreview, 0, 180) . '...';
}

$dialogueModeRaw = $_GET["mode"] ?? '';
$dialogueMode = strtolower(trim((string)$dialogueModeRaw));
$allowedDialogueModes = ['talk', 'whisper', 'shout', 'autochat', 'cheat'];
if (!in_array($dialogueMode, $allowedDialogueModes, true)) {
    $dialogueMode = 'talk';
}
$manualActionKey = stobeNormalizeManualChatActionKey(strval($_GET['manual_action'] ?? ''));
$manualActionActor = normalizeParticipantNameToken(strval($_GET['manual_action_actor'] ?? ''));
$manualActionTarget = normalizeParticipantNameToken(strval($_GET['manual_action_target'] ?? ''));
$manualActionActorSid = trim(strval($_GET['manual_action_actor_sid'] ?? ''));
$manualActionTargetSid = trim(strval($_GET['manual_action_target_sid'] ?? ''));
if ($manualActionActor === '') {
    $manualActionActor = normalizeParticipantNameToken(strval($speaker));
}
if ($manualActionActor === '') {
    $manualActionActor = trim(strval($speaker));
}
$manualActionActive = ($manualActionKey !== '');

$targetNpc = normalizeParticipantNameToken(strval($_GET["profile"] ?? ''));
$extractedTargetNpc = normalizeParticipantNameToken(strval($targetExtract['target'] ?? ''));
if ($targetNpc === '' && $extractedTargetNpc !== '') {
    $targetNpc = $extractedTargetNpc;
}
if ($targetNpc === '') {
    stobeLogWarn('Chat input rejected: missing target NPC', [
        'event_type' => $eventType,
        'speaker' => $speaker,
        'gamets' => intval($gamets),
        'data_preview' => substr($eventData, 0, 180),
    ]);
    echo "ok";
    return;
}
if ($manualActionTarget === '') {
    $manualActionTarget = $targetNpc;
}

stobeLogInfo('Chat input received', [
    'event_type' => $eventType,
    'speaker' => $speaker,
    'target_npc' => $targetNpc,
    'mode' => $dialogueMode,
    'manual_action' => $manualActionActive ? $manualActionKey : '',
    'manual_action_actor' => $manualActionActor,
    'manual_action_target' => $manualActionTarget,
    'manual_action_actor_sid' => $manualActionActorSid,
    'manual_action_target_sid' => $manualActionTargetSid,
    'gamets' => intval($gamets),
    'message_preview' => $messagePreview,
]);

$npcData = getNpcData($targetNpc);
if (!$npcData) {
    storeNpcProfile($targetNpc, []);
    $npcData = getNpcData($targetNpc);
    stobeLogInfo('NPC profile JIT-created', ['target_npc' => $targetNpc]);
} elseif (npcNeedsBootstrap($npcData)) {
    // Backfill older sparse rows created before profile defaults were added.
    storeNpcProfile($targetNpc, []);
    $npcData = getNpcData($targetNpc) ?: $npcData;
    stobeLogInfo('NPC profile baseline refreshed', ['target_npc' => $targetNpc]);
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
    stobeLogInfo('Chat target remapped to canonical NPC name', [
        'requested_target_npc' => $targetNpc,
        'resolved_target_npc' => $canonicalTargetNpc,
    ]);
    $targetNpc = $canonicalTargetNpc;
}
if ($manualActionActive) {
    if ($manualActionTarget === '') {
        $manualActionTarget = $targetNpc;
    }
    if ($manualActionTarget !== '' && strcasecmp($manualActionTarget, $targetNpc) !== 0) {
        stobeLogWarn('Manual action ignored: target mismatch', [
            'manual_action' => $manualActionKey,
            'manual_action_target' => $manualActionTarget,
            'chat_target_npc' => $targetNpc,
        ]);
        $manualActionKey = '';
        $manualActionActive = false;
    }
}
$manualActionLimbToken = stobeManualChatActionLimbToken($manualActionKey);
$manualActionLimbLabel = stobeManualChatActionLimbLabel($manualActionKey);

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
    $line = stobeFormatEventHistoryLine($row, true);
    if ($line !== '') {
        $historyLines[] = $line;
    }
}
$historyText = implode("\n", $historyLines);
$historyMessages = stobeBuildRecentContextMessages($eventHistory, intval($gamets));

$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');

$autochatRewriteApplied = false;
if ($dialogueMode === 'autochat') {
    $rewriteResult = rewriteSpeakerMessageForAutochat(
        $speaker,
        $targetNpc,
        $npcData,
        $message,
        $historyText
    );
    if (boolval($rewriteResult['rewritten'] ?? false)) {
        $message = trim(strval($rewriteResult['message'] ?? $message));
        $autochatRewriteApplied = true;
        stobeLogInfo('Autochat rewrite generated', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'rewritten_length' => strlen($message),
        ]);
    } else {
        stobeLogInfo('Autochat rewrite fallback', [
            'speaker' => $speaker,
            'target_npc' => $targetNpc,
            'model' => strval($rewriteResult['model'] ?? ''),
            'reason' => strval($rewriteResult['error'] ?? 'unknown'),
        ]);
    }
} elseif ($dialogueMode === 'cheat') {
    stobeLogInfo('Cheat mode active for chat request', [
        'speaker' => $speaker,
        'target_npc' => $targetNpc,
        'mode' => $dialogueMode,
        'message_length' => strlen($message),
    ]);
}

$eventData = $speaker . ': ' . $message . ' (talking to: ' . $targetNpc . ')';
storeEvent($eventType, $timestamp, $gamets, $eventData);

if ($dialogueMode === 'autochat' && trim($message) !== '') {
    stobeLogInfo('Autochat streaming rewritten speaker line', [
        'speaker' => $speaker,
        'target_npc' => $targetNpc,
        'rewritten' => $autochatRewriteApplied,
        'message_length' => strlen($message),
    ]);
    // Emit the player's autochat line before NPC generation so audio order is natural.
    streamResponse($speaker, 'ScriptQueue', $message);
}

$manualActionCannotSpeak = $manualActionActive
    ? stobeManualActionTargetCannotSpeak($npcData)
    : false;

$systemPrompt = stobeBuildGameTimePromptBlock($gamets)
    . "\n\n"
    . buildSystemPrompt($targetNpc, $npcData, $speaker, $message, true, 'chat', intval($gamets));
$deliveryStyleInstruction = '';
if ($dialogueMode === 'whisper') {
    $deliveryStyleInstruction = 'The player is whispering. Respond in a quiet, discreet tone.';
} elseif ($dialogueMode === 'shout') {
    $deliveryStyleInstruction = 'The player is shouting. Respond with urgency and stronger emotional intensity.';
} elseif ($dialogueMode === 'autochat') {
    $deliveryStyleInstruction = 'The player triggered a bored-event automatic chat. Keep responses brief and natural for overheard conversation.';
}
if ($deliveryStyleInstruction !== '') {
    $systemPrompt .= "\n\n<speech_mode>\n"
        . "  <mode>" . stobePromptXmlEscape($dialogueMode) . "</mode>\n"
        . "  <instruction>" . stobePromptXmlEscape($deliveryStyleInstruction) . "</instruction>\n"
        . "</speech_mode>";
}
if ($manualActionActive) {
    $manualInstruction = $manualActionCannotSpeak
        ? 'Manual limb removal is happening now, and the target cannot speak. Do not invent coherent spoken dialogue for the target.'
        : 'Manual limb removal is happening now. The target should react with immediate extreme pain, shock, and desperation.';
    $systemPrompt .= "\n\n<manual_action_context>\n"
        . "  <type>remove_limb</type>\n"
        . "  <action_key>" . stobePromptXmlEscape($manualActionKey) . "</action_key>\n"
        . "  <actor>" . stobePromptXmlEscape($manualActionActor) . "</actor>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <limb_token>" . stobePromptXmlEscape($manualActionLimbToken) . "</limb_token>\n"
        . "  <limb_label>" . stobePromptXmlEscape($manualActionLimbLabel) . "</limb_label>\n"
        . "  <target_can_speak>" . ($manualActionCannotSpeak ? 'false' : 'true') . "</target_can_speak>\n"
        . "  <instruction>" . stobePromptXmlEscape($manualInstruction) . "</instruction>\n"
        . "</manual_action_context>";
}
$userContent = "<player_input>\n"
    . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
    . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
    . "  <mode>" . stobePromptXmlEscape($dialogueMode) . "</mode>\n"
    . "  <text>" . stobePromptXmlEscape($message) . "</text>\n"
    . "</player_input>";
if ($manualActionActive) {
    $userContent .= "\n<manual_action_event>\n"
        . "  <type>remove_limb</type>\n"
        . "  <actor>" . stobePromptXmlEscape($manualActionActor) . "</actor>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <limb>" . stobePromptXmlEscape($manualActionLimbLabel) . "</limb>\n"
        . "  <target_can_speak>" . ($manualActionCannotSpeak ? 'false' : 'true') . "</target_can_speak>\n"
        . "</manual_action_event>";
}
if ($dialogueMode === 'cheat') {
    $priorityInstruction = "PRIORITY INSTRUCTION - {$targetNpc} must do this, even if it breaks character roleplay: {$message}";
    $systemPrompt .= "\n\n<cheatmode>\n"
        . "  <priority_instruction>" . stobePromptXmlEscape($priorityInstruction) . "</priority_instruction>\n"
        . "</cheatmode>";
    $userContent = "<cheat_request>\n"
        . "  <speaker>" . stobePromptXmlEscape($speaker) . "</speaker>\n"
        . "  <target>" . stobePromptXmlEscape($targetNpc) . "</target>\n"
        . "  <request>" . stobePromptXmlEscape($message) . "</request>\n"
        . "</cheat_request>";
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
    'content' => $userContent,
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildTurnGuidanceUserPrompt($targetNpc, $speaker),
];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildOutputContractUserPrompt(
        $targetNpc,
        $dialogueMode === 'cheat',
        true,
        npcIsInPlayerFaction($npcData)
    ),
];

$llmConfig = getLlmConfigForNpc($npcData);
$actionConfig = stobeBuildActionConfigForNpc('chat', $npcData);

$responseText = '';
$responseActions = [];
$alreadyStreamed = false;
$actionsStreamedInLlm = false;
$manualActionForcedEmoteOnly = false;
if ($manualActionActive && $manualActionCannotSpeak) {
    $manualActionForcedEmoteOnly = true;
    $responseText = '';
    $responseActions = [
        stobeBuildManualActionPainFallback($targetNpc, $manualActionActor, $manualActionKey),
    ];
    stobeLogInfo('Manual action fallback emitted: target cannot speak', [
        'target_npc' => $targetNpc,
        'manual_action' => $manualActionKey,
        'manual_action_actor' => $manualActionActor,
        'manual_action_target' => $manualActionTarget,
    ]);
} elseif ($llmConfig['api_key'] === '') {
    $responseText = 'No OpenRouter API key configured yet.';
    stobeLogWarn('LLM call skipped because API key is missing', ['target_npc' => $targetNpc]);
} else {
    $streamResult = stobeStreamDialogueViaLlm(
        $targetNpc,
        $npcData,
        $messages,
        $llmConfig,
        'chat',
        [
            'npc_name' => $targetNpc,
            'event_type' => 'chat',
            'speaker' => $speaker,
            'action_config' => $actionConfig,
        ]
    );

    if (boolval($streamResult['ok'] ?? false)) {
        $responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
        $responseActions = is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [];
        $alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
        $actionsStreamedInLlm = boolval($streamResult['actions_streamed'] ?? false);
        stobeLogInfo('LLM stream response generated', [
            'target_npc' => $targetNpc,
            'model' => $llmConfig['model'] ?? '',
            'response_length' => strlen($responseText),
            'structured_json' => boolval($streamResult['structured_json'] ?? false),
            'actions_count' => count($responseActions),
            'actions' => $responseActions,
            'chunks_emitted' => intval($streamResult['chunks_emitted'] ?? 0),
            'actions_streamed' => $actionsStreamedInLlm,
        ]);
    } else {
        $rawResponse = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $targetNpc,
        'event_type' => 'chat',
        'speaker' => $speaker,
        ]);
        if ($rawResponse === false || trim($rawResponse) === '') {
            $responseText = '...';
            stobeLogWarn('LLM returned an empty response', [
                'target_npc' => $targetNpc,
                'model' => $llmConfig['model'] ?? '',
            ]);
        } else {
            $structured = stobeParseStructuredDialogueResponse($rawResponse, 'chat');
            $responseText = sanitizeForKenshi(trim(strval($structured['message'] ?? '')));
            $responseActions = [];
            $structuredAction = trim(strval($structured['action_tag'] ?? ''));
            if ($structuredAction !== '') {
                $responseActions[] = $structuredAction;
            }
            if ($responseText !== '') {
                $actionExtraction = extractAndNormalizeActionTags($responseText, 'chat', $actionConfig);
                $responseText = sanitizeForKenshi(trim(strval($actionExtraction['text'] ?? $responseText)));
                $inlineActions = is_array($actionExtraction['actions'] ?? null) ? $actionExtraction['actions'] : [];
                foreach ($inlineActions as $inlineAction) {
                    if (!in_array($inlineAction, $responseActions, true)) {
                        $responseActions[] = $inlineAction;
                    }
                }
            }
            stobeLogInfo('LLM response generated (non-stream fallback)', [
                'target_npc' => $targetNpc,
                'model' => $llmConfig['model'] ?? '',
                'response_length' => strlen($responseText),
                'structured_json' => boolval($structured['is_structured'] ?? false),
                'actions_count' => count($responseActions),
                'actions' => $responseActions,
            ]);
        }
    }
}
$responseActions = stobeDedupeActionList($responseActions, 'chat', $actionConfig);

if (!$manualActionForcedEmoteOnly) {
    $relationshipEval = stobeEvaluateRelationshipsForTurn(
        $targetNpc,
        $speaker,
        $speaker . ': ' . $message,
        $responseText,
        $npcData,
        'chat'
    );
    $responseText = stobeStripParentheticalDialogueText(
        sanitizeForKenshi(trim(strval($relationshipEval['clean_response'] ?? $responseText)))
    );
}
if ($responseText === '' && count($responseActions) === 0) {
    $responseText = '...';
}

$storedResponseText = $responseText;
if ($storedResponseText === '' && count($responseActions) > 0) {
    $storedResponseText = '[action issued]';
} elseif ($storedResponseText === '') {
    $storedResponseText = '...';
}

storeActionEvents($targetNpc, $responseActions, $gamets, $speaker, 'chat');

$chatEventData = $targetNpc . ': ' . $storedResponseText . ' (talking to: ' . $speaker . ')';
storeEvent('chat', time(), $gamets, $chatEventData);

if ($alreadyStreamed) {
    if (count($responseActions) > 0 && !$actionsStreamedInLlm) {
        streamResponse($targetNpc, 'ScriptQueue', '', $npcData, $responseActions);
    }
} else {
    streamResponse($targetNpc, 'ScriptQueue', $responseText, $npcData, $responseActions);
}
