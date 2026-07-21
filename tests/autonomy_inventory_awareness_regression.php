<?php

function inventoryAwarenessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/lib/autonomy_helper_functions.php';

$items = stobeAutonomyNormalizeInventoryItems([
    [
        'name' => 'Map of the Border Zone',
        'kind' => 'map',
        'detail' => '',
        'reveals_towns' => ['The Hub', 'Squin', 'the hub', '', str_repeat('x', 200)],
        'count' => 1,
        'buy_value_each' => 500,
        'sell_value_each' => 250,
        'untrusted' => 'drop me',
    ],
    ['name' => 'Ancient Science Blueprint', 'kind' => 'blueprint', 'detail' => 'Advanced Research'],
    ['name' => 'Economy Arm', 'kind' => 'robotic_limb', 'detail' => 'left arm'],
    ['name' => 'Severed Human Left Arm', 'kind' => 'severed_limb'],
    ['name' => 'Suspicious Item', 'kind' => 'arbitrary_payload_type', 'detail' => 'spoofed', 'reveals_towns' => ['Spoofed Town']],
]);

inventoryAwarenessAssert(count($items) === 5, 'All valid inventory rows should survive normalization.');
inventoryAwarenessAssert($items[0]['kind'] === 'map', 'Map classification must be retained.');
inventoryAwarenessAssert($items[0]['reveals_towns'] === ['The Hub', 'Squin', str_repeat('x', 160)], 'Map town names must be bounded and deduplicated case-insensitively.');
inventoryAwarenessAssert(!array_key_exists('untrusted', $items[0]), 'Unknown payload fields must be discarded.');
inventoryAwarenessAssert($items[1]['detail'] === 'Advanced Research', 'Blueprint research detail must be retained.');
inventoryAwarenessAssert($items[2]['kind'] === 'robotic_limb', 'Robotic limb classification must be retained.');
inventoryAwarenessAssert($items[3]['kind'] === 'severed_limb', 'Severed limb classification must be retained.');
inventoryAwarenessAssert($items[4]['kind'] === 'item', 'Unknown classifications must fall back to item.');
inventoryAwarenessAssert($items[4]['detail'] === '' && $items[4]['reveals_towns'] === [], 'Generic items must not retain type-specific metadata.');

echo "PASS: autonomy inventory awareness regression\n";
