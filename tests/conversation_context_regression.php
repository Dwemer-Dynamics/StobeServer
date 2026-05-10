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
