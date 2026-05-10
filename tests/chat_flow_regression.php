<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function chatFlowFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function chatFlowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        chatFlowFail($message);
    }
}

function chatFlowAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        chatFlowFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function chatFlowAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        chatFlowFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

function chatFlowClearEventlog(): void
{
    $db = $GLOBALS['db'];
    $db->exec('DELETE FROM eventlog');
}

function chatFlowEventRows(): array
{
    $db = $GLOBALS['db'];
    return $db->fetchAll('SELECT type, data FROM eventlog ORDER BY rowid ASC');
}

function chatFlowEnsureNpcState(string $name, string $state): void
{
    storeNpcProfile($name, []);

    $payload = json_encode(['character_state' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        chatFlowFail('failed to encode NPC state payload');
    }

    $db = $GLOBALS['db'];
    $db->exec(
        'UPDATE core_npc_master
         SET metadata = $1::jsonb,
             extended_data = $2::jsonb,
             updated_at = NOW()
         WHERE LOWER(name) = LOWER($3)',
        [$payload, $payload, $name]
    );
}

function chatFlowRunProcessor(string $eventType, string $eventData, array $query = [], string $peopleCache = ''): string
{
    $oldGet = $_GET;
    $hadPeopleCache = array_key_exists('CACHE_PEOPLE', $GLOBALS);
    $oldPeopleCache = $GLOBALS['CACHE_PEOPLE'] ?? '';

    $_GET = $query;
    if ($peopleCache !== '') {
        $GLOBALS['CACHE_PEOPLE'] = $peopleCache;
    } elseif ($hadPeopleCache) {
        unset($GLOBALS['CACHE_PEOPLE']);
    }

    $timestamp = time();
    $gamets = 321;

    ob_start();
    include __DIR__ . '/../processor/chat.php';
    $output = ob_get_clean();

    $_GET = $oldGet;
    if ($hadPeopleCache) {
        $GLOBALS['CACHE_PEOPLE'] = $oldPeopleCache;
    } else {
        unset($GLOBALS['CACHE_PEOPLE']);
    }

    return trim(strval($output));
}

chatFlowClearEventlog();
$output = chatFlowRunProcessor('inputtext', 'Ruka:    ', ['profile' => 'Beep']);
chatFlowAssertSame('ok', $output, 'empty sanitized chat should short-circuit with ok');
chatFlowAssertSameInt(0, count(chatFlowEventRows()), 'empty sanitized chat should not store event rows');

chatFlowClearEventlog();
$output = chatFlowRunProcessor('inputtext', 'Ruka: Hello there');
chatFlowAssertSame('ok', $output, 'missing target chat should short-circuit with ok');
chatFlowAssertSameInt(0, count(chatFlowEventRows()), 'missing target chat should not store event rows');

chatFlowEnsureNpcState('UT_RUKA_SPEAKER', 'dead');
chatFlowClearEventlog();
$output = chatFlowRunProcessor('inputtext', 'UT_RUKA_SPEAKER: Hello there', ['profile' => 'Beep']);
chatFlowAssertSame('ok', $output, 'incapacitated speaker chat should short-circuit with ok');
chatFlowAssertSameInt(0, count(chatFlowEventRows()), 'incapacitated speaker chat should not store event rows');

chatFlowEnsureNpcState('UT_BEEP_TARGET', 'dead');
chatFlowClearEventlog();
$output = chatFlowRunProcessor('inputtext', 'Ruka: Hello there', ['profile' => 'UT_BEEP_TARGET']);
chatFlowAssertSame('ok', $output, 'incapacitated target chat should short-circuit with ok');
$rows = chatFlowEventRows();
chatFlowAssertSameInt(2, count($rows), 'incapacitated target chat should keep player input plus mirrored chat row');
chatFlowAssertSame('inputtext', strval($rows[0]['type'] ?? ''), 'first stored row should be the original inputtext');
chatFlowAssertSame('chat', strval($rows[1]['type'] ?? ''), 'second stored row should be the mirrored chat');
$expectedData = 'Ruka: Hello there (talking to: UT_BEEP_TARGET)';
chatFlowAssertSame($expectedData, strval($rows[0]['data'] ?? ''), 'inputtext row should preserve normalized target payload');
chatFlowAssertSame($expectedData, strval($rows[1]['data'] ?? ''), 'mirrored chat row should preserve normalized target payload');

echo "All chat flow regression tests passed.\n";
