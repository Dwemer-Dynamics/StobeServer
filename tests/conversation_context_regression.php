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

echo "All conversation context regression tests passed.\n";
