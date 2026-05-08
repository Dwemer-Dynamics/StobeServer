<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function rechatSelectionFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function rechatSelectionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        rechatSelectionFail($message);
    }
}

function rechatSelectionAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        rechatSelectionFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function rechatSelectionAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        rechatSelectionFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

function rechatSelectionClearEventlog(): void
{
    $GLOBALS['db']->exec('DELETE FROM eventlog');
}

function rechatSelectionForceNoApiKey(): void
{
    $db = $GLOBALS['db'];
    $db->exec("UPDATE core_api_badge SET api_key = ''");
    try {
        $db->exec("UPDATE core_llm_connector SET api_key = ''");
    } catch (Throwable $exception) {
        // Older schemas may not persist connector-level api_key columns.
    }
}

function rechatSelectionRun(string $eventType, string $eventData, array $query = [], string $peopleCache = ''): string
{
    $oldGet = $_GET;
    $hadPeopleCache = array_key_exists('CACHE_PEOPLE', $GLOBALS);
    $oldPeopleCache = $GLOBALS['CACHE_PEOPLE'] ?? '';

    $_GET = array_merge(['tts_enabled' => '0'], $query);
    if ($peopleCache !== '') {
        $GLOBALS['CACHE_PEOPLE'] = $peopleCache;
    } elseif ($hadPeopleCache) {
        unset($GLOBALS['CACHE_PEOPLE']);
    }

    $timestamp = time();
    $gamets = 777;

    ob_start();
    ob_start();
    include __DIR__ . '/../processor/rechat.php';
    $innerOutput = ob_get_clean();
    $outerOutput = ob_get_clean();

    $_GET = $oldGet;
    if ($hadPeopleCache) {
        $GLOBALS['CACHE_PEOPLE'] = $oldPeopleCache;
    } else {
        unset($GLOBALS['CACHE_PEOPLE']);
    }

    return trim(strval($outerOutput . $innerOutput));
}

rechatSelectionForceNoApiKey();
storeNpcProfile('UT_RECHAT_SELECTION_SPEAKER_OFF', []);
storeNpcProfile('UT_RECHAT_SELECTION_SPEAKER_ON', []);
setSetting('RANDOM_NARATION', '0');

$speakerOff = 'UT_RECHAT_SELECTION_SPEAKER_OFF';
$initiatorOff = 'UT_RECHAT_SELECTION_INITIATOR_OFF';
$peopleOff = '["' . $speakerOff . '","' . $initiatorOff . '"]';
setSetting('SPEAKER_RECHAT', 'false');
rechatSelectionClearEventlog();
$offOutput = rechatSelectionRun(
    'rechat',
    $speakerOff . ': Hold position. (talking to: ' . $initiatorOff . ')',
    [
        'mode' => 'talk',
        'profile' => $initiatorOff,
        'people' => $peopleOff,
        'initiator' => $initiatorOff,
    ],
    $peopleOff
);
rechatSelectionAssertSame('ok', $offOutput, 'rechat should short-circuit cleanly when speaker rechat is disabled');
rechatSelectionAssertSameInt(
    0,
    count($GLOBALS['db']->fetchAll('SELECT rowid FROM eventlog')),
    'disabled speaker rechat should not store duplicate incoming or outgoing rows'
);
rechatSelectionAssert(
    getNpcData($initiatorOff) === false,
    'disabled speaker rechat should not JIT-create the initiator as a responder candidate'
);

$speakerOn = 'UT_RECHAT_SELECTION_SPEAKER_ON';
$initiatorOn = 'UT_RECHAT_SELECTION_INITIATOR_ON';
$peopleOn = '["' . $speakerOn . '","' . $initiatorOn . '"]';
setSetting('SPEAKER_RECHAT', 'true');
rechatSelectionClearEventlog();
$onOutput = rechatSelectionRun(
    'rechat',
    $speakerOn . ': Hold position. (talking to: ' . $initiatorOn . ')',
    [
        'mode' => 'talk',
        'profile' => $initiatorOn,
        'people' => $peopleOn,
        'initiator' => $initiatorOn,
    ],
    $peopleOn
);
rechatSelectionAssertSame('ok', $onOutput, 'rechat should still return ok when speaker rechat is enabled but API access is absent');
rechatSelectionAssertSameInt(
    0,
    count($GLOBALS['db']->fetchAll('SELECT rowid FROM eventlog')),
    'missing API key should still avoid emitting duplicate rechat rows after responder selection'
);
rechatSelectionAssert(
    is_array(getNpcData($initiatorOn)),
    'enabled speaker rechat should allow the initiator to be selected and JIT-created as the responder candidate'
);

echo "All rechat selection regression tests passed.\n";
