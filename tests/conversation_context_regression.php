<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

function contextFlowFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function contextFlowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        contextFlowFail($message);
    }
}

function contextFlowAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        contextFlowFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function contextFlowAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        contextFlowFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

function contextFlowMessageContents(array $messages): array
{
    $contents = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        $contents[] = strval($message['content'] ?? '');
    }
    return $contents;
}

$historyAliases = stobeResolveNpcEventHistoryAliases(
    ['original_name' => 'Dust Boss Hotlongs'],
    'Fenth [Dust Boss Hotlongs]'
);
contextFlowAssertSame(
    'Dust Boss Hotlongs',
    strval($historyAliases[0] ?? ''),
    'renamed NPC history should retain the original game name as an event alias'
);

$recentCombatHistory = [
    [
        'type' => 'major_damage',
        'data' => 'Fenth [Dust Boss Hotlongs]: took a major hit from Herika using Short Cleaver',
        'gamets' => 1950,
        'localts' => time(),
    ],
    [
        'type' => 'major_damage',
        'data' => 'Fenth [Dust Boss Hotlongs]: took an old hit',
        'gamets' => 500,
        'localts' => time() - 600,
    ],
];
$recentCombatEvents = stobeBuildRecentCombatPromptEvents($recentCombatHistory, 2000);
contextFlowAssertSameInt(1, count($recentCombatEvents), 'combat prompt should retain only recent severe events');
contextFlowAssert(
    str_contains(strval($recentCombatEvents[0]['line'] ?? ''), 'Herika using Short Cleaver'),
    'combat prompt event should preserve attacker and weapon details'
);
$combatNpcData = stobeAttachRecentCombatPromptEvents(
    ['name' => 'Fenth [Dust Boss Hotlongs]', 'metadata' => []],
    $recentCombatHistory,
    2000
);
$combatPriorityBlock = stobeBuildCombatPriorityPromptBlock($combatNpcData, 'Fenth [Dust Boss Hotlongs]');
contextFlowAssert(
    str_contains($combatPriorityBlock, '<combat_priority>')
        && str_contains($combatPriorityBlock, '<recent_event type="major_damage">')
        && str_contains($combatPriorityBlock, 'never detached or analytical'),
    'recent major damage should activate an urgent combat prompt with concrete evidence'
);

$eventHistory = [
    [
        'type' => 'chat',
        'data' => 'Beep: Hello there. (talking to: Ruka)',
        'location' => 'The Hub',
        'gamets' => 500,
    ],
    [
        'type' => 'chat',
        'data' => 'Beep: Hello there.',
        'location' => 'The Hub',
        'gamets' => 499,
    ],
    [
        'type' => 'inputtext',
        'data' => 'Ruka: Hello there.',
        'location' => 'The Hub',
        'gamets' => 498,
    ],
    [
        'type' => 'infoloc',
        'data' => 'Dust storm sweeping across the valley',
        'location' => 'Squin',
        'gamets' => 497,
    ],
];

$messages = stobeBuildRecentContextMessages($eventHistory, 600, 64);
$contents = contextFlowMessageContents($messages);

// Short-term memory needs the surviving history floor without leaking internal timestamps.
$timedMessages = stobeBuildRecentContextMessages($eventHistory, 600, 64, '', true);
contextFlowAssertSameInt(497, min(array_column($timedMessages, '_stobe_gamets')), 'history retains its oldest surviving timestamp');
contextFlowAssertSame('', stobeBuildShortTermMemoryContext(false, 'Unknown NPC', $timedMessages, 600, false), 'unknown NPCs do not receive summaries');
contextFlowAssert($timedMessages === $messages, 'short-term memory strips timestamps without changing live dialogue');

$rollingEvents = [];
for ($index = 1; $index <= 20; $index++) {
    $rollingEvents[] = [
        'type' => 'chat',
        'data' => ($index % 2 ? 'Beep' : 'Ruka') . ': Message ' . $index,
        'gamets' => 600 + $index,
    ];
}
$rollingEvents = array_reverse($rollingEvents);
$rollingTimed = stobeBuildRecentContextMessages($rollingEvents, 700, 8, 'Beep', true);
contextFlowAssertSameInt(613, $rollingTimed[0]['_stobe_gamets'], 'summary boundary follows the surviving window after cropping');
stobeBuildShortTermMemoryContext(false, 'Unknown NPC', $rollingTimed, 700, true);
contextFlowAssert($rollingTimed === stobeBuildRecentContextMessages($rollingEvents, 700, 8, 'Beep'), 'timestamp tracking preserves cropped dialogue');

contextFlowAssert(count($contents) === 4, 'recent context builder should emit location transitions plus deduped narrative rows');
contextFlowAssert(
    strpos($contents[0] ?? '', 'LOCATION CHANGE to Squin') !== false,
    'recent context builder should emit the oldest location transition first'
);
contextFlowAssert(
    strpos($contents[1] ?? '', '[infoloc] Dust storm sweeping across the valley') !== false,
    'non-inline event types should be prefixed with their type in context history'
);
contextFlowAssert(
    strpos($contents[2] ?? '', 'LOCATION CHANGE to The Hub') !== false,
    'recent context builder should emit subsequent location transitions when the area changes'
);
contextFlowAssert(
    strpos($contents[3] ?? '', 'Beep: Hello there. (talking to: Ruka)') !== false,
    'recent context builder should keep the richer targeted dialogue variant'
);
contextFlowAssert(
    strpos(implode("\n", $contents), 'Ruka: Hello there.') === false,
    'inputtext rows should be excluded from recent prompt context'
);

$mergedDialogueHistory = [
    [
        'type' => 'rechat',
        'data' => 'Doran: We just fixed it. (talking to: Ruka)',
        'location' => 'The Hub',
        'gamets' => 512,
    ],
    [
        'type' => 'chat',
        'data' => 'Doran: It was worse. (talking to: Unknown)',
        'location' => 'The Hub',
        'gamets' => 511,
    ],
    [
        'type' => 'chat',
        'data' => 'Ruka: Keep moving. (talking to: Doran)',
        'location' => 'The Hub',
        'gamets' => 510,
    ],
];

$mergedMessages = stobeBuildRecentContextMessages($mergedDialogueHistory, 700, 64);
$mergedContents = contextFlowMessageContents($mergedMessages);
contextFlowAssert(count($mergedContents) === 3, 'recent context should merge consecutive same-speaker dialogue rows into one block');
contextFlowAssert(
    strpos($mergedContents[1] ?? '', 'Ruka: Keep moving. (talking to: Doran)') !== false,
    'recent context merge should still preserve earlier different-speaker turns as separate blocks'
);
contextFlowAssert(
    strpos($mergedContents[2] ?? '', 'Doran: It was worse. We just fixed it. (talking to: Ruka)') !== false,
    'recent context merge should concatenate consecutive same-speaker dialogue and keep the richer known listener once'
);
contextFlowAssert(
    strpos($mergedContents[2] ?? '', '(talking to: Unknown)') === false,
    'recent context merge should strip placeholder Unknown listeners from dialogue history'
);

$assistantPerspectiveMessages = stobeBuildRecentContextMessages($mergedDialogueHistory, 700, 64, 'Doran');
contextFlowAssertSameInt(3, count($assistantPerspectiveMessages), 'assistant perspective recent context should keep the same number of merged history blocks');
contextFlowAssertSame(
    'user',
    strval($assistantPerspectiveMessages[1]['role'] ?? ''),
    'non-perspective speaker history should remain user role'
);
contextFlowAssert(
    strpos(strval($assistantPerspectiveMessages[1]['content'] ?? ''), 'Ruka: Keep moving. (talking to: Doran)') !== false,
    'non-perspective speaker history should keep the formatted dialogue line'
);
contextFlowAssertSame(
    'assistant',
    strval($assistantPerspectiveMessages[2]['role'] ?? ''),
    'perspective speaker history should be promoted to assistant role like Herika'
);
$assistantPerspectivePayload = json_decode(strval($assistantPerspectiveMessages[2]['content'] ?? ''), true);
contextFlowAssert(is_array($assistantPerspectivePayload), 'assistant perspective history should serialize as structured JSON like Herika');
contextFlowAssertSame('Doran', strval($assistantPerspectivePayload['character'] ?? ''), 'assistant perspective history should preserve the speaker name');
contextFlowAssertSame('Ruka', strval($assistantPerspectivePayload['listener'] ?? ''), 'assistant perspective history should preserve the listener name');
contextFlowAssertSame('Talk', strval($assistantPerspectivePayload['action'] ?? ''), 'assistant perspective history should preserve Talk as the dialogue action');
contextFlowAssertSame('', strval($assistantPerspectivePayload['target'] ?? ''), 'assistant perspective history should keep the action target empty for spoken dialogue');
contextFlowAssertSame(
    'It was worse. We just fixed it.',
    strval($assistantPerspectivePayload['message'] ?? ''),
    'assistant perspective history should preserve the merged spoken text inside the structured payload'
);

$singletonInfoNpcHistory = [
    [
        'type' => 'infonpc',
        'data' => 'Huft: nearby NPC roster (42): Ruka, Beep, Agnu',
        'location' => 'Squin',
        'gamets' => 530,
    ],
    [
        'type' => 'chat',
        'data' => 'Beep: Keep your eyes open. (talking to: Ruka)',
        'location' => 'Squin',
        'gamets' => 529,
    ],
    [
        'type' => 'infonpc',
        'data' => 'Huft: nearby NPC roster (38): Ruka, Beep',
        'location' => 'Squin',
        'gamets' => 528,
    ],
];

$singletonMessages = stobeBuildRecentContextMessages($singletonInfoNpcHistory, 700, 64);
$singletonContents = contextFlowMessageContents($singletonMessages);
contextFlowAssert(count($singletonContents) === 2, 'recent context should omit infonpc snapshots while preserving the location transition and remaining dialogue');
contextFlowAssert(
    strpos($singletonContents[1] ?? '', 'Beep: Keep your eyes open. (talking to: Ruka)') !== false,
    'recent context should preserve non-infonpc rows when infonpc snapshots are removed'
);
contextFlowAssert(
    strpos(implode("\n", $singletonContents), '[infonpc]') === false,
    'recent context should remove infonpc snapshots entirely'
);

$textHistoryMessages = stobeBuildRecentContextMessagesFromText(
    "[chat] Doran: It was worse. (talking to: Ruka)\n[chat] Ruka: Keep moving. (talking to: Doran)",
    24,
    'Doran'
);
contextFlowAssertSameInt(2, count($textHistoryMessages), 'text-history recent context should preserve both dialogue lines');
contextFlowAssertSame(
    'assistant',
    strval($textHistoryMessages[0]['role'] ?? ''),
    'text-history perspective speaker lines should also use assistant role'
);
$textHistoryPayload = json_decode(strval($textHistoryMessages[0]['content'] ?? ''), true);
contextFlowAssert(is_array($textHistoryPayload), 'text-history perspective speaker lines should also serialize as structured assistant JSON');
contextFlowAssertSame('Doran', strval($textHistoryPayload['character'] ?? ''), 'text-history assistant payload should preserve the speaker name');
contextFlowAssertSame('Ruka', strval($textHistoryPayload['listener'] ?? ''), 'text-history assistant payload should preserve the listener name');
contextFlowAssertSame('It was worse.', strval($textHistoryPayload['message'] ?? ''), 'text-history assistant payload should preserve the spoken text');
contextFlowAssertSame(
    'user',
    strval($textHistoryMessages[1]['role'] ?? ''),
    'text-history non-perspective lines should remain user role'
);

$textHistoryWithInfoNpcMessages = stobeBuildRecentContextMessagesFromText(
    "[infonpc] Huft: nearby NPC roster (43): Horse, Benjie\n[chat] Doran: It was worse. (talking to: Ruka)",
    24,
    'Doran'
);
contextFlowAssertSameInt(1, count($textHistoryWithInfoNpcMessages), 'text-history recent context should also drop infonpc rows entirely');
contextFlowAssert(
    strpos(strval($textHistoryWithInfoNpcMessages[0]['content'] ?? ''), 'It was worse.') !== false,
    'text-history recent context should keep the remaining dialogue after removing infonpc rows'
);

$formattedUnknownTargetLine = stobeFormatEventHistoryLine([
    'type' => 'chat',
    'data' => 'Doran: It was worse. (talking to: Unknown)',
    'gamets' => 512,
], false);
contextFlowAssertSame(
    '[chat] Doran: It was worse.',
    $formattedUnknownTargetLine,
    'formatted history lines should omit placeholder Unknown listeners'
);

$listenerResolved = stobeResolveDialogueListenerTarget(
    'Beep',
    ['Beep [Dust Boss]', 'Ruka'],
    'Ruka'
);
contextFlowAssertSame(
    'Beep [Dust Boss]',
    $listenerResolved,
    'listener target resolution should map base names onto known bracketed participants'
);

$listenerFallback = stobeResolveDialogueListenerTarget(
    '',
    ['Ruka', 'Agnu'],
    'Ruka'
);
contextFlowAssertSame(
    'Ruka',
    $listenerFallback,
    'listener target resolution should fall back to the known fallback participant'
);

$rowsWithNarrator = [
    [
        'type' => 'chat',
        'data' => stobeNarratorName() . ': The air turns cold. (talking to: Ruka)',
        'people' => '["' . stobeNarratorName() . '","Ruka"]',
    ],
    [
        'type' => 'chat',
        'data' => 'Beep: Stay alert. (talking to: Ruka)',
        'people' => '["Beep","Ruka"]',
    ],
    [
        'type' => 'init',
        'data' => 'initial boot marker',
        'people' => '[]',
    ],
];

$normalFiltered = stobeFilterNarratorRowsForContext($rowsWithNarrator, 'Beep', 'talk', 'Ruka');
contextFlowAssert(count($normalFiltered) === 2, 'normal chat context should exclude narrator-involved rows only');
contextFlowAssert(
    strpos(strval($normalFiltered[0]['data'] ?? ''), 'Beep: Stay alert.') !== false,
    'normal chat context should keep non-narrator dialogue rows'
);
contextFlowAssertSame(
    'init',
    strval($normalFiltered[1]['type'] ?? ''),
    'normal chat context should keep non-narrator init rows'
);

$narratorFiltered = stobeFilterNarratorRowsForContext($rowsWithNarrator, stobeNarratorName(), 'narrator', 'Ruka');
contextFlowAssert(count($narratorFiltered) === 2, 'narrator mode context should keep narrator rows but drop init rows');
contextFlowAssert(
    strpos(strval($narratorFiltered[0]['data'] ?? ''), stobeNarratorName() . ': The air turns cold.') !== false,
    'narrator mode context should keep narrator dialogue rows'
);
contextFlowAssertSame(
    'chat',
    strval($narratorFiltered[1]['type'] ?? ''),
    'narrator mode context should retain ordinary chat rows alongside narrator rows'
);

$inlineNarratorRow = [[
    'type' => 'inline_narration',
    'data' => stobeNarratorName() . ': Ruka studies the gate.',
    'people' => '["' . stobeNarratorName() . '","Ruka"]',
]];
$GLOBALS['PRESERVE_INLINE_NARRATION_CONTEXT'] = true;
$preservedInline = stobeFilterNarratorRowsForContext($inlineNarratorRow, 'Beep', 'talk', 'Ruka');
contextFlowAssertSameInt(
    1,
    count($preservedInline),
    'inline narrator context should be retained when its setting is enabled'
);
$GLOBALS['PRESERVE_INLINE_NARRATION_CONTEXT'] = false;

$compactSourceMessages = [
    [
        'role' => 'assistant',
        'content' => json_encode([
            'character' => 'Doran',
            'listener' => 'Ruka',
            'message' => 'Stay close.',
            'action' => 'Follow',
            'target' => 'Ruka',
        ], JSON_UNESCAPED_SLASHES),
    ],
    [
        'role' => 'user',
        'content' => " (...\nRuka: We move now. (talking to: Doran)\n...)",
    ],
    [
        'role' => 'user',
        'content' => " (...\n[death] Dust Bandit was killed by Doran\n...)",
    ],
];
$compactBlock = stobeFormatCompactChatHistory($compactSourceMessages, 'Doran');
contextFlowAssertSame(
    implode("\n", [
        '# Doran, speaking to Ruka: Stay close. [Action: Follow, targeting Ruka]',
        '# Ruka, speaking to Doran: We move now.',
        '# [death] Dust Bandit was killed by Doran',
    ]),
    $compactBlock,
    'compact chat history should preserve speakers, listeners, actions, and normalized event text as Markdown'
);
contextFlowAssert(
    strpos($compactBlock, '{"character"') === false,
    'compact chat history should remove assistant JSON wrappers'
);
contextFlowAssert(
    strpos($compactBlock, '(...)') === false,
    'compact chat history should remove ambient user-message wrappers'
);

$priorCompactSetting = getSetting('COMPACT_CHAT_HISTORY_ENABLED', 'true');
try {
    setSetting('COMPACT_CHAT_HISTORY_ENABLED', 'true');
    contextFlowAssert(
        stobeShouldCompactChatHistory('Doran'),
        'compact chat history should activate for NPC prompts when the global setting is enabled'
    );
    contextFlowAssert(
        !stobeShouldCompactChatHistory(stobeNarratorName()),
        'compact chat history should remain disabled for narrator prompts'
    );
} finally {
    setSetting('COMPACT_CHAT_HISTORY_ENABLED', $priorCompactSetting);
}

$unchangedHistory = stobeApplyCompactChatHistory(
    '<system>Keep existing prompt shape</system>',
    $compactSourceMessages,
    'Doran',
    false
);
contextFlowAssertSame(
    '<system>Keep existing prompt shape</system>',
    strval($unchangedHistory['system_prompt'] ?? ''),
    'disabled compact chat history should leave the system prompt unchanged'
);
contextFlowAssertSameInt(
    count($compactSourceMessages),
    count(is_array($unchangedHistory['history_messages'] ?? null) ? $unchangedHistory['history_messages'] : []),
    'disabled compact chat history should leave role-separated history unchanged'
);

$compactedHistory = stobeApplyCompactChatHistory(
    '<system>Use compact history</system>',
    $compactSourceMessages,
    'Doran',
    true
);
contextFlowAssertSame(
    "<system>Use compact history</system>\n\n" . $compactBlock,
    strval($compactedHistory['system_prompt'] ?? ''),
    'enabled compact chat history should append the Markdown block to the system prompt'
);
contextFlowAssertSameInt(
    0,
    count(is_array($compactedHistory['history_messages'] ?? null) ? $compactedHistory['history_messages'] : []),
    'enabled compact chat history should remove the role-separated recent history'
);

$GLOBALS['db']->exec('BEGIN');
try {
    $GLOBALS['db']->exec("DELETE FROM general_settings WHERE id = 'PROMPT_HEAD_MARKDOWN_ENABLED'");
    contextFlowAssert(getSettingBool('PROMPT_HEAD_MARKDOWN_ENABLED', true), 'Compact Prompt Info defaults on when missing');
    setSetting('PROMPT_HEAD_MARKDOWN_ENABLED', 'false');
    contextFlowAssert(!getSettingBool('PROMPT_HEAD_MARKDOWN_ENABLED', true), 'Explicitly disabled Compact Prompt Info stays off');
    $GLOBALS['db']->exec("DELETE FROM general_settings WHERE id = 'COMPACT_CHAT_HISTORY_ENABLED'");
    contextFlowAssert(stobeShouldCompactChatHistory('Doran'), 'Compact Chat History defaults on when missing');
    setSetting('COMPACT_CHAT_HISTORY_ENABLED', 'false');
    contextFlowAssert(!stobeShouldCompactChatHistory('Doran'), 'Explicitly disabled Compact Chat History stays off');
} finally {
    $GLOBALS['db']->exec('ROLLBACK');
}
$xmlPrompt = "<world>\r\n<location>The Hub</location>\r\n</world>\r\n"
    . "<character>\n<skills>\n<group name=\"Combat\">\n"
    . "<skill name=\"Melee Attack\">Expert</skill>\n</group>\n</skills>\n"
    . "<character_state>\n<health>Healthy</health>\n</character_state>\n</character>\n"
    . "<nearby_actors>\n#NEARBY ACTORS/NPC IN THE SCENE\n##Beep (1234)\n</nearby_actors>\n"
    . "<general_instructions>\n<rule>Keep action JSON unchanged.</rule>\n"
    . "* Be brief.\nUse <speech_style> for reference.\n</general_instructions>\n"
    . "<nearby_context_json>\n{\"actor\":\"Beep\",\"id\":1234}\n</nearby_context_json>";
contextFlowAssertSame($xmlPrompt, stobeFormatPromptHeadSection($xmlPrompt, false), 'off preserves exact XML and line endings');
$markdownPrompt = stobeFormatPromptHeadSection($xmlPrompt, true);
foreach ([
    '# World', '- Location: The Hub', '# Character', '## Skills', '### Combat',
    '- Melee Attack: Expert', '## Character State', '- Health: Healthy',
    '# Nearby Actors', '- Beep (1234)', '- Keep action JSON unchanged.', '- Be brief.',
    'Use `Speech Style` for reference.', '{"actor":"Beep","id":1234}',
] as $expected) {
    contextFlowAssert(strpos($markdownPrompt, $expected) !== false, 'Markdown retains ' . $expected);
}
contextFlowAssert(strpos($markdownPrompt, '<') === false, 'Markdown removes section tags including named skills');
contextFlowAssert(strpos($markdownPrompt, 'NEARBY ACTORS/NPC IN THE SCENE') === false, 'duplicate legacy heading removed');
foreach ([false, true] as $compactEnabled) {
    $off = stobeApplyCompactChatHistory($xmlPrompt, $compactSourceMessages, 'Doran', $compactEnabled);
    $explicitOff = stobeApplyCompactChatHistory($xmlPrompt, $compactSourceMessages, 'Doran', $compactEnabled, false);
    contextFlowAssert($off === $explicitOff, 'missing and false Markdown flag are identical');
    $on = stobeApplyCompactChatHistory($xmlPrompt, $compactSourceMessages, 'Doran', $compactEnabled, true);
    contextFlowAssert($on['history_messages'] === $off['history_messages'], 'Markdown does not change history routing');
    $expectedSystem = $compactEnabled
        ? rtrim($markdownPrompt) . "\n\n# Conversation History\n\n" . preg_replace('/^# /m', '- ', $compactBlock)
        : $markdownPrompt;
    contextFlowAssertSame($expectedSystem, $on['system_prompt'], 'Markdown works independently of Compact Chat');
}

$baseSnapshot = stobeNormalizePlayerBaseSnapshot([
    'inside' => true,
    'base_id' => 'test-base',
    'name' => 'Test Base',
    'battery_drain' => 4.5,
    'battery_charging' => 8.25,
    'details' => [
        'available' => true,
        'security' => [
            'alarm_state' => 'attack',
            'hostiles_inside' => 3,
            'turrets_total' => 8,
            'turrets_manned' => 6,
        ],
        'infrastructure' => [
            'damaged' => 2,
            'issues' => [
                [
                    'name' => 'Storm House',
                    'count' => 2,
                    'damaged' => 2,
                ],
            ],
        ],
        'construction' => [
            'total' => 12,
            'paused' => 1,
            'missing_materials' => 3,
            'average_progress' => 45.5,
            'groups' => [
                [
                    'name' => 'Defensive Wall IV',
                    'count' => 12,
                    'paused' => 1,
                    'missing_materials' => 3,
                    'average_progress' => 45.5,
                ],
            ],
        ],
        'power' => [
            'consumers' => 16,
            'unpowered' => 2,
            'switched_off' => 1,
            'generators_total' => 4,
            'generators_active' => 3,
        ],
        'supplies' => [
            'food' => 120,
            'medicine' => 18,
        ],
        'storage' => [
            'total' => 6,
            'empty' => 1,
            'full' => 2,
            'item_units' => 94,
            'groups' => [
                [
                    'name' => 'Storage: Building Materials',
                    'total' => 3,
                    'empty' => 0,
                    'full' => 2,
                    'item_units' => 72,
                ],
            ],
        ],
        'production' => [
            'total' => 7,
            'active' => 4,
            'input_blocked' => 2,
            'average_efficiency' => 67.5,
            'groups' => [
                [
                    'name' => 'Iron Refinery III',
                    'total' => 3,
                    'active' => 1,
                    'input_blocked' => 2,
                    'average_efficiency' => 52.5,
                ],
            ],
        ],
        'farms' => [
            'total' => 5,
            'active' => 3,
            'needs_water' => 1,
            'hydroponic' => 2,
            'average_yield' => 81.25,
            'groups' => [
                [
                    'name' => 'Hydroponic Hemp',
                    'total' => 2,
                    'active' => 1,
                    'needs_water' => 1,
                    'hydroponic' => 2,
                    'average_yield' => 81.25,
                ],
            ],
        ],
    ],
], true);
contextFlowAssertSame(
    'attack',
    strval($baseSnapshot['details']['security']['alarm_state'] ?? ''),
    'player-base normalization should preserve supported alarm states'
);
contextFlowAssertSameInt(
    120,
    intval($baseSnapshot['details']['supplies']['food'] ?? 0),
    'player-base normalization should preserve bounded supply counts'
);
contextFlowAssertSameInt(
    12,
    intval($baseSnapshot['details']['construction']['groups'][0]['count'] ?? 0),
    'player-base normalization should preserve grouped construction counts'
);
contextFlowAssertSameInt(
    2,
    intval($baseSnapshot['details']['storage']['groups'][0]['full'] ?? 0),
    'player-base normalization should preserve grouped storage status'
);

$baseContextBlock = buildPlayerBaseStateBlock([
    'extended_data' => ['player_base' => $baseSnapshot],
]);
contextFlowAssert(
    strpos($baseContextBlock, '<alarm_state>attack</alarm_state>') !== false,
    'player-base prompt context should include security state'
);
contextFlowAssert(
    strpos($baseContextBlock, '<food>120</food>') !== false,
    'player-base prompt context should include supply levels'
);
contextFlowAssert(
    strpos($baseContextBlock, '<name>Defensive Wall IV</name>') !== false
        && strpos($baseContextBlock, '<count>12</count>') !== false,
    'player-base prompt context should include grouped construction'
);
contextFlowAssert(
    strpos($baseContextBlock, '<power_resilience>') !== false
        && strpos($baseContextBlock, '<unpowered>2</unpowered>') !== false,
    'player-base prompt context should include power resilience'
);
contextFlowAssert(
    strpos($baseContextBlock, '<name>Storage: Building Materials</name>') !== false
        && strpos($baseContextBlock, '<full>2</full>') !== false,
    'player-base prompt context should include grouped storage'
);
contextFlowAssert(
    strpos($baseContextBlock, '<input_blocked>2</input_blocked>') !== false,
    'player-base prompt context should include production blockers'
);
contextFlowAssert(
    strpos($baseContextBlock, '<needs_water>1</needs_water>') !== false
        && strpos($baseContextBlock, '<average_yield>81.25</average_yield>') !== false,
    'player-base prompt context should include farm status'
);

echo "All conversation context regression tests passed.\n";
