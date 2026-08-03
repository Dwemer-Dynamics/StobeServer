<?php

require_once __DIR__ . '/../lib/settings.php';

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . var_export($expected, true)
            . PHP_EOL . 'Actual: ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
};

$assertSame(
    'http://192.168.1.40:8082',
    stobeNormalizeMiniMeServiceUrl('http://192.168.1.40:8082/'),
    'Remote MiniMe URL should be preserved.'
);
$assertSame(
    'https://ai.example.test/services/minime/topic?text=The%20Hub',
    stobeMiniMeServiceEndpoint(
        '/topic',
        ['text' => 'The Hub'],
        'https://ai.example.test/services/minime/'
    ),
    'HTTPS path prefixes and encoded query strings should be preserved.'
);
$assertSame(
    ['host' => 'ai.example.test', 'port' => 443, 'socket_host' => 'ssl://ai.example.test'],
    stobeMiniMeServiceSocket('https://ai.example.test/services/minime'),
    'HTTPS MiniMe URLs should use the default TLS socket port.'
);
$assertSame(
    'http://127.0.0.1:8082',
    stobeNormalizeMiniMeServiceUrl('not a URL'),
    'Invalid MiniMe URLs should fall back to the local service.'
);

echo "MiniMe service URL regression passed.\n";
