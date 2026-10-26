<?php

/**
 * Addon action follow-up (stobe.addon_followup.v1, docs/plugin-runtime.md).
 * Reached from main.php only for funcret with addon_followup=1. One model turn
 * for the NPC an opted-in ExtCmd was issued to, after the client reports that
 * exact action completed; everything else is the legacy "ok" acknowledgement.
 */

$followupHeader = static function (string $status): void {
    if (!headers_sent()) {
        header('X-Stobe-Addon-Followup: v1 ' . $status);
    }
};

if (!stobeInteractionAllowed()) {
    $followupHeader('skipped interaction-off');
    echo 'ok';
    return;
}
$followupCheck = stobeAddonFollowupClaim(is_array($gameRequest) ? $gameRequest : [], $_GET);
if (!$followupCheck['ok']) {
    $followupHeader('rejected');
    echo 'ok';
    return;
}
$followupClaim = $followupCheck['claim'];
$speakerNpc = $followupClaim['actor'];
// The profile registered for the exact serial, never a namesake (stobeAddonFollowupProfile).
$speakerData = stobeAddonFollowupProfile($speakerNpc, $followupClaim['actor_sid']);
$llmConfig = $speakerData ? getLlmConfigForNpc($speakerData) : [];
// Keyless KoboldCpp, Player2 and loopback connectors are valid (same rule as the autonomy planner).
$unavailable = !$speakerData ? 'no unique profile for this serial'
    : (trim(strval($llmConfig['api_key'] ?? '')) === '' && stobeAutonomyPlannerConnectorRequiresApiKey($llmConfig)
        ? 'connector needs an API key' : '');
if ($unavailable !== '') {
    stobeLogWarn('Addon follow-up skipped: ' . $unavailable, [
        'speaker' => $speakerNpc, 'sid' => $followupClaim['actor_sid'], 'aid' => $followupClaim['aid'],
    ]);
    $followupHeader('skipped unavailable aid=' . $followupClaim['aid']);
    echo 'ok';
    return;
}

$GLOBALS['STOBE_ADDON_FOLLOWUP_TURN'] = true;
$GLOBALS['STOBE_ADDON_FOLLOWUP_ACTOR'] = ['name' => $speakerNpc, 'sid' => strval($followupClaim['actor_sid'])];
$GLOBALS['STOBE_ADDON_FOLLOWUP_ALLOW_ACTIONS'] = !empty($followupClaim['followup']['use_functions_again']);
$followupHeader('accepted aid=' . $followupClaim['aid']);
$campaign = 'Default';
$listener = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));

stobeRunExtensionPromptStages();
$contextHistory = getNpcProfileIntegerSetting($speakerData, ['CONTEXT_HISTORY'], '', 30, 10, 120);
$eventHistory = stobeFilterNarratorRowsForContext(DataEventLog($contextHistory, $speakerNpc, $campaign), $speakerNpc, 'addon_followup');
$historyMessages = stobeApplyExtensionContextBuilding(
    stobeBuildRecentContextMessages($eventHistory, intval($gamets), 64, $speakerNpc)
);

stobeRunExtensionHook('context_pre.php');
$systemPrompt = stobeBuildGameTimePromptBlock($gamets, $speakerData)
    . "\n\n"
    . buildSystemPrompt($speakerNpc, $speakerData, $listener, '', false, 'addon_followup', intval($gamets));
$systemPrompt = stobeApplyExtensionPromptSections($systemPrompt, $speakerNpc, $speakerData);
$messages = [['role' => 'system', 'content' => $systemPrompt]];
foreach ($historyMessages as $historyMessage) {
    $messages[] = $historyMessage;
}
// The outcome is a tool result for this NPC, not something the player said.
$argName = $followupClaim['followup']['arg_name'];
$messages[] = [
    'role' => 'user',
    'content' => "<addon_action_result>\n"
        . '  <actor>' . stobePromptXmlEscape($speakerNpc) . "</actor>\n"
        . '  <action>' . stobePromptXmlEscape($followupClaim['code']) . "</action>\n"
        . '  <' . $argName . '>' . stobePromptXmlEscape($followupClaim['argument']) . '</' . $argName . ">\n"
        . "  <status>completed</status>\n"
        . '  <result>' . stobePromptXmlEscape($followupClaim['detail']) . "</result>\n"
        . '  <instruction>' . stobePromptXmlEscape($followupClaim['followup']['prompt']) . "</instruction>\n"
        . '</addon_action_result>',
];
$messages[] = ['role' => 'user', 'content' => stobeBuildTurnGuidanceUserPrompt($speakerNpc, $listener)];
$messages[] = [
    'role' => 'user',
    'content' => stobeBuildOutputContractUserPrompt($speakerNpc, false, false, npcIsInPlayerFaction($speakerData), 'addon_followup'),
];
$messages = stobeApplyExtensionContextHook($messages);

$actionConfig = stobeBuildActionConfigForNpc('addon_followup', $speakerData);
$enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'connector/llm_dispatcher.php');
$streamResult = stobeStreamDialogueViaLlm($speakerNpc, $speakerData, $messages, $llmConfig, 'addon_followup', [
    'npc_name' => $speakerNpc,
    'event_type' => 'addon_followup',
    'action_config' => $actionConfig,
    'stream_event_type' => 'addon_followup',
    'stream_listener' => $listener,
    'stream_gamets' => $gamets,
    'response_format' => stobeBuildStructuredDialogueResponseFormat($speakerNpc, $speakerData, npcIsInPlayerFaction($speakerData), 'addon_followup'),
]);
if (!boolval($streamResult['ok'] ?? false)) {
    stobeLogWarn('Addon follow-up LLM stream failed', ['speaker' => $speakerNpc, 'aid' => $followupClaim['aid']]);
    echo 'ok';
    return;
}
$responseText = sanitizeForKenshi(trim(strval($streamResult['response_text'] ?? '')));
$responseActions = stobeDedupeActionList(is_array($streamResult['actions'] ?? null) ? $streamResult['actions'] : [], 'addon_followup', $actionConfig);
$alreadyStreamed = intval($streamResult['chunks_emitted'] ?? 0) > 0;
if (!stobeInlineNarrationApplies($speakerNpc, 'addon_followup')) {
    $responseText = stobeStripParentheticalDialogueText($responseText);
}
if ($responseText === '' && count($responseActions) === 0) {
    echo 'ok';
    return;
}
storeActionEvents($speakerNpc, $responseActions, $gamets, $listener, 'addon_followup');
stobeLogInfo('Addon follow-up response generated', [
    'speaker' => $speakerNpc,
    'aid' => $followupClaim['aid'],
    'client_request_id' => $followupClaim['client_request_id'],
    'code' => $followupClaim['code'],
    'response_length' => strlen($responseText),
    'actions' => $responseActions,
    'already_streamed' => $alreadyStreamed,
]);
if ($alreadyStreamed) {
    if (count($responseActions) > 0 && !boolval($streamResult['actions_streamed'] ?? false)) {
        streamResponse($speakerNpc, 'ScriptQueue', '', $speakerData, $responseActions, 'addon_followup', $listener, $gamets);
    }
} else {
    stobeStreamDialogueResponse($speakerNpc, $speakerData, $responseText, $responseActions, 'addon_followup', $listener, intval($gamets));
}
stobeMarkExtensionModelTurnCompleted();
