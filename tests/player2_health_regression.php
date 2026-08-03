<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/player2_health.php';

function player2HealthAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

player2HealthAssert(
    stobePlayer2HealthNormalizeUrl('http://127.0.0.1:4315/v1/chat/completions')
        === 'http://127.0.0.1:4315/v1/health',
    'chat completion URL should normalize to the Player2 health endpoint'
);
player2HealthAssert(
    stobePlayer2HealthNormalizeUrl('https://player2.example:443/docs')
        === 'https://player2.example:443/v1/health',
    'docs URL should normalize to the Player2 health endpoint'
);
player2HealthAssert(
    stobePlayer2GameKeyHeader() === 'player2-game-key: 019cf504-1461-74e7-b4da-045b14e9019d',
    'health requests should identify STOBE with its registered Player2 Game Client Id'
);

$state = [
    'last_activity' => 950,
    'session_started' => 900,
    'active_session' => 900,
    'last_used' => 940,
    'last_attempt' => 900,
    'health_url' => 'http://127.0.0.1:4315/v1/health',
];
player2HealthAssert(stobePlayer2HealthShouldPing($state, 1000), 'fresh armed session should ping');
player2HealthAssert(!stobePlayer2HealthShouldPing($state, 959), 'heartbeat interval should be enforced');

$state['last_activity'] = 700;
player2HealthAssert(!stobePlayer2HealthShouldPing($state, 1000), 'stale game activity should not ping');

$state['last_activity'] = 950;
$state['active_session'] = 800;
player2HealthAssert(!stobePlayer2HealthShouldPing($state, 1000), 'previous session should not remain armed');

$state['active_session'] = 900;
$state['last_used'] = 699;
player2HealthAssert(!stobePlayer2HealthShouldPing($state, 1000), 'stale Player2 use should not remain armed');

echo 'All Player2 health regression tests passed.' . PHP_EOL;
