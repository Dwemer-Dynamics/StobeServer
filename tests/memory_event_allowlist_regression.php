<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/memory_helper_functions.php';

function memoryEventAllowlistFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function memoryEventAllowlistAssert(bool $condition, string $message): void
{
    if (!$condition) {
        memoryEventAllowlistFail($message);
    }
}

$durableEvents = [
    'inputtext',
    'inputtext_s',
    'chat',
    'rechat',
    'bored',
    'action',
    'diary',
    'location',
    'combat_start',
    'combat_end',
    'limb_loss',
    'horn_cut',
    'knockout',
    'recovered',
    'death',
    'slavery',
    'enslaved',
    'freed_slave',
    'imprisonment',
    'healing',
    'trade',
    'item_pickup',
    'looting',
    'carry',
    'predation',
    'eat',
    'build',
    'dismantle',
    'lockpicked',
    'lockpiked',
];

foreach ($durableEvents as $eventType) {
    memoryEventAllowlistAssert(
        stobeRegularMemoryAllowedEventType($eventType),
        $eventType . ' should be available to regular memory'
    );
}

$noiseEvents = [
    '',
    'combat',
    'context',
    'infoloc',
    'infonpc',
    'infoaction',
    'init',
    'narration',
    'npc_snapshot',
    'playerinfo',
    'setconf',
    'status_msg',
];

foreach ($noiseEvents as $eventType) {
    memoryEventAllowlistAssert(
        !stobeRegularMemoryAllowedEventType($eventType),
        ($eventType === '' ? 'empty event type' : $eventType) . ' should stay out of regular memory'
    );
}

memoryEventAllowlistAssert(
    stobeRegularMemoryAllowedEventType(' Death '),
    'event type matching should remain case-insensitive and trim whitespace'
);

echo 'Memory event allowlist regression checks passed.' . PHP_EOL;
