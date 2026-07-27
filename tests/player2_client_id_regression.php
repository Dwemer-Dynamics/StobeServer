<?php

declare(strict_types=1);

require_once __DIR__ . '/../connector/openaijson.php';
require_once __DIR__ . '/../connector/player2json.php';

function player2ClientIdFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function player2ClientIdAssert(bool $condition, string $message): void
{
    if (!$condition) {
        player2ClientIdFail($message);
    }
}

$expectedClientId = '019cf504-1461-74e7-b4da-045b14e9019d';

$defaultRuntime = stobeAdapterPlayer2json([]);
player2ClientIdAssert(
    strval($defaultRuntime['config']['player2_game_key'] ?? '') === $expectedClientId,
    'Player2 adapter should default to the registered STOBE Game Client Id'
);

$legacyRuntime = stobeAdapterPlayer2json([
    'api_key' => 'STOBE',
    'config' => ['player2_game_key' => 'STOBE'],
]);
player2ClientIdAssert(
    strval($legacyRuntime['config']['player2_game_key'] ?? '') === $expectedClientId,
    'Player2 adapter should replace the legacy STOBE game key'
);

$customRuntime = stobeAdapterPlayer2json([
    'config' => ['player2_game_key' => 'custom-player2-client-id'],
]);
player2ClientIdAssert(
    strval($customRuntime['config']['player2_game_key'] ?? '') === 'custom-player2-client-id',
    'Player2 adapter should preserve an explicit custom game key'
);

foreach ([false, true] as $forStreaming) {
    $headers = stobeBuildLlmRequestHeaders(
        '',
        $defaultRuntime['config'],
        'player2json',
        $forStreaming
    );
    player2ClientIdAssert(
        in_array('player2-game-key: ' . $expectedClientId, $headers, true),
        'Player2 completion headers should include the registered STOBE Game Client Id'
    );
    player2ClientIdAssert(
        !in_array('player2-game-key: STOBE', $headers, true),
        'Player2 completion headers should not include the legacy STOBE literal'
    );
}

echo 'All Player2 client ID regression tests passed.' . PHP_EOL;
