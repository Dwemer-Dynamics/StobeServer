<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function chatProcessorFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function chatProcessorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        chatProcessorFail($message);
    }
}

function chatProcessorAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        chatProcessorFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function chatProcessorAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        chatProcessorFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

function chatProcessorAssertSameList(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        chatProcessorFail(
            $message
            . ' (expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . ', actual=' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ')'
        );
    }
}

function chatProcessorClearEventlog(): void
{
    $GLOBALS['db']->exec('DELETE FROM eventlog');
}

function chatProcessorEventRows(): array
{
    return $GLOBALS['db']->fetchAll('SELECT type, data FROM eventlog ORDER BY rowid ASC');
}

function chatProcessorForceNoApiKey(): void
{
    $db = $GLOBALS['db'];
    $db->exec("UPDATE core_api_badge SET api_key = ''");
    try {
        $db->exec("UPDATE core_llm_connector SET api_key = ''");
    } catch (Throwable $exception) {
        // Older schemas may not persist connector-level api_key columns.
    }
}

function chatProcessorRun(string $eventType, string $eventData, array $query = [], string $peopleCache = ''): string
{
    $oldGet = $_GET;
    $oldPost = $_POST;
    $hadPeopleCache = array_key_exists('CACHE_PEOPLE', $GLOBALS);
    $oldPeopleCache = $GLOBALS['CACHE_PEOPLE'] ?? '';

    $_GET = array_merge(['tts_enabled' => '0'], $query);
    $_POST = [];
    if ($peopleCache !== '') {
        $GLOBALS['CACHE_PEOPLE'] = $peopleCache;
    } elseif ($hadPeopleCache) {
        unset($GLOBALS['CACHE_PEOPLE']);
    }

    $timestamp = time();
    $gamets = 555;

    ob_start();
    ob_start();
    include __DIR__ . '/../processor/chat.php';
    $innerOutput = ob_get_clean();
    $outerOutput = ob_get_clean();

    $_GET = $oldGet;
    $_POST = $oldPost;
    if ($hadPeopleCache) {
        $GLOBALS['CACHE_PEOPLE'] = $oldPeopleCache;
    } else {
        unset($GLOBALS['CACHE_PEOPLE']);
    }

    return rtrim(strval($outerOutput . $innerOutput));
}

function chatProcessorOutputLines(string $output): array
{
    $lines = preg_split('/\r\n|\r|\n/', $output);
    if (!is_array($lines)) {
        return [];
    }

    $normalized = [];
    foreach ($lines as $line) {
        $trimmed = trim(strval($line));
        if ($trimmed === '') {
            continue;
        }
        $normalized[] = chatProcessorNormalizeWireLine($trimmed);
    }
    return $normalized;
}

function chatProcessorNormalizeWireLine(string $line): string
{
    $trimmed = trim($line);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^([^|]+\|[^|]+\|[^|]*)(?:\|.*)?$/', $trimmed, $match) === 1) {
        return strval($match[1]);
    }

    return $trimmed;
}

chatProcessorForceNoApiKey();

$inlineSpeaker = 'UT_CHAT_INLINE_SPEAKER';
$inlineTarget = 'UT_CHAT_INLINE_TARGET';
chatProcessorClearEventlog();
$inlineOutput = chatProcessorRun(
    'inputtext',
    $inlineSpeaker . ': Hello there (talking to: ' . $inlineTarget . ')'
);
chatProcessorAssertSame(
    $inlineTarget . '|ScriptQueue|No OpenRouter API key configured yet.',
    chatProcessorNormalizeWireLine($inlineOutput),
    'inline target extraction should still produce the API-key fallback response'
);
$inlineRows = chatProcessorEventRows();
chatProcessorAssertSameInt(3, count($inlineRows), 'inline target flow should store input, mirrored chat, and fallback reply');
chatProcessorAssertSame('inputtext', strval($inlineRows[0]['type'] ?? ''), 'first inline row should be inputtext');
chatProcessorAssertSame('chat', strval($inlineRows[1]['type'] ?? ''), 'second inline row should be mirrored chat');
chatProcessorAssertSame('chat', strval($inlineRows[2]['type'] ?? ''), 'third inline row should be NPC fallback reply');
chatProcessorAssertSame(
    $inlineSpeaker . ': Hello there (talking to: ' . $inlineTarget . ')',
    strval($inlineRows[0]['data'] ?? ''),
    'inline target flow should normalize the player event data with the extracted target'
);
chatProcessorAssertSame(
    $inlineSpeaker . ': Hello there (talking to: ' . $inlineTarget . ')',
    strval($inlineRows[1]['data'] ?? ''),
    'inline target flow should preserve the mirrored chat payload'
);
chatProcessorAssertSame(
    $inlineTarget . ': No OpenRouter API key configured yet. (talking to: ' . $inlineSpeaker . ')',
    strval($inlineRows[2]['data'] ?? ''),
    'inline target flow should store the fallback NPC reply against the original speaker'
);
chatProcessorAssert(
    is_array(getNpcData($inlineTarget)),
    'inline target flow should JIT-create the extracted target profile'
);

$autochatSpeaker = 'UT_CHAT_AUTO_SPEAKER';
$autochatTarget = 'UT_CHAT_AUTO_TARGET';
chatProcessorClearEventlog();
$autochatOutput = chatProcessorRun(
    'inputtext',
    $autochatSpeaker . ': Keep walking',
    ['profile' => $autochatTarget, 'mode' => 'autochat'],
    '["' . $autochatSpeaker . '","' . $autochatTarget . '"]'
);
$autochatLines = chatProcessorOutputLines($autochatOutput);
chatProcessorAssertSameList(
    [
        $autochatSpeaker . '|ScriptQueue|Keep walking',
        $autochatTarget . '|ScriptQueue|No OpenRouter API key configured yet.',
    ],
    $autochatLines,
    'autochat without an API key should stream the speaker line before the NPC fallback reply'
);
$autochatRows = chatProcessorEventRows();
chatProcessorAssertSameInt(4, count($autochatRows), 'autochat fallback should store input, mirrored chat, tracked speaker output, and fallback reply');
chatProcessorAssertSame(
    $autochatSpeaker . ': Keep walking (talking to: ' . $autochatTarget . ')',
    strval($autochatRows[0]['data'] ?? ''),
    'autochat fallback should store normalized player input'
);
chatProcessorAssertSame(
    $autochatSpeaker . ': Keep walking (talking to: ' . $autochatTarget . ')',
    strval($autochatRows[1]['data'] ?? ''),
    'autochat fallback should preserve the mirrored chat row'
);
chatProcessorAssertSame(
    $autochatSpeaker . ': Keep walking (talking to: ' . $autochatTarget . ')',
    strval($autochatRows[2]['data'] ?? ''),
    'autochat fallback should persist the streamed speaker line as a tracked utterance'
);
chatProcessorAssertSame(
    $autochatTarget . ': No OpenRouter API key configured yet. (talking to: ' . $autochatSpeaker . ')',
    strval($autochatRows[3]['data'] ?? ''),
    'autochat fallback should store the NPC reply after the speaker line'
);

echo "All chat processor regression tests passed.\n";
