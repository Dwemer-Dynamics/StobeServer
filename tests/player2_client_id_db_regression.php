<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

function player2ClientIdDbFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function player2ClientIdDbAssert(bool $condition, string $message): void
{
    if (!$condition) {
        player2ClientIdDbFail($message);
    }
}

$db = $GLOBALS['db'];
$expectedClientId = '019cf504-1461-74e7-b4da-045b14e9019d';

$seedBadge = $db->fetchOne("SELECT api_key FROM core_api_badge WHERE LOWER(label) = 'player2'");
player2ClientIdDbAssert(
    strval($seedBadge['api_key'] ?? '') === $expectedClientId,
    'fresh schema should seed the registered STOBE Player2 Game Client Id'
);
$seedConnector = $db->fetchOne(
    "SELECT config->>'player2_game_key' AS game_key
     FROM core_llm_connector
     WHERE LOWER(name) = 'player2 local'"
);
player2ClientIdDbAssert(
    strval($seedConnector['game_key'] ?? '') === $expectedClientId,
    'fresh schema should seed the registered Player2 connector key'
);

$db->exec('BEGIN');
try {
    $db->exec("UPDATE core_api_badge SET api_key = 'STOBE' WHERE LOWER(label) = 'player2'");
    $db->exec(
        "INSERT INTO core_api_badge (label, api_key)
         VALUES ('Stobe', 'custom-player2-client-id')
         ON CONFLICT (label) DO UPDATE SET api_key = EXCLUDED.api_key"
    );
    $db->exec(
        "UPDATE core_llm_connector
         SET config = jsonb_set(COALESCE(config, '{}'::jsonb), '{player2_game_key}', '\"STOBE\"'::jsonb, TRUE)
         WHERE LOWER(name) = 'player2 local'"
    );
    $db->exec(
        "INSERT INTO core_llm_connector (
            name, connector_type, api_key, base_url, model, config
         ) VALUES (
            'Player2 Custom Test', 'player2json', '', 'http://127.0.0.1:4315/v1',
            'player2-app-selected', '{\"player2_game_key\":\"custom-player2-client-id\"}'::jsonb
         )"
    );
    $db->exec("DELETE FROM database_versioning WHERE tablename = 'player2_game_client_id'");

    require __DIR__ . '/../debug/db_updates.php';

    $legacyBadge = $db->fetchOne("SELECT api_key FROM core_api_badge WHERE LOWER(label) = 'player2'");
    player2ClientIdDbAssert(
        strval($legacyBadge['api_key'] ?? '') === $expectedClientId,
        'upgrade should replace the legacy Player2 badge value'
    );
    $customBadge = $db->fetchOne("SELECT api_key FROM core_api_badge WHERE LOWER(label) = 'stobe'");
    player2ClientIdDbAssert(
        strval($customBadge['api_key'] ?? '') === 'custom-player2-client-id',
        'upgrade should preserve a custom badge value'
    );
    $legacyConnector = $db->fetchOne(
        "SELECT config->>'player2_game_key' AS game_key
         FROM core_llm_connector
         WHERE LOWER(name) = 'player2 local'"
    );
    player2ClientIdDbAssert(
        strval($legacyConnector['game_key'] ?? '') === $expectedClientId,
        'upgrade should replace the legacy Player2 connector key'
    );
    $customConnector = $db->fetchOne(
        "SELECT config->>'player2_game_key' AS game_key
         FROM core_llm_connector
         WHERE name = 'Player2 Custom Test'"
    );
    player2ClientIdDbAssert(
        strval($customConnector['game_key'] ?? '') === 'custom-player2-client-id',
        'upgrade should preserve a custom Player2 connector key'
    );

    $db->exec('ROLLBACK');
} catch (Throwable $exception) {
    $db->exec('ROLLBACK');
    throw $exception;
}

echo 'All Player2 client ID database regression tests passed.' . PHP_EOL;
