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

function rechatSelectionDeleteNpc(string $name): void
{
    $safeName = trim($name);
    if ($safeName === '') {
        return;
    }
    $row = $GLOBALS['db']->fetchOne(
        "SELECT id FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$safeName]
    );
    $npcId = intval($row['id'] ?? 0);
    if ($npcId > 0) {
        deleteNpc($npcId);
    }
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
storeNpcProfile('UT_RECHAT_SELECTION_GROUP_SPEAKER', []);
setSetting('RANDOM_NARATION', '0');
setSetting('RECHAT_MODE', 'conversational');

$speakerOff = 'UT_RECHAT_SELECTION_SPEAKER_OFF';
$initiatorOff = 'UT_RECHAT_SELECTION_INITIATOR_OFF';
$peopleOff = '["' . $speakerOff . '","' . $initiatorOff . '"]';
rechatSelectionDeleteNpc($initiatorOff);
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
rechatSelectionDeleteNpc($initiatorOn);
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

$groupSpeaker = 'UT_RECHAT_SELECTION_GROUP_SPEAKER';
$groupTarget = 'UT_RECHAT_SELECTION_GROUP_TARGET';
$groupPeople = '["' . $groupSpeaker . '","' . $groupTarget . '"]';
rechatSelectionDeleteNpc($groupTarget);
setSetting('SPEAKER_RECHAT', 'false');
setSetting('RECHAT_MODE', 'group');
rechatSelectionClearEventlog();
$groupOutput = rechatSelectionRun(
    'rechat',
    $groupSpeaker . ': Hold position. (talking to: ' . $groupTarget . ')',
    [
        'mode' => 'talk',
        'profile' => $groupTarget,
        'people' => $groupPeople,
        'initiator' => $groupTarget,
        'rechat_target' => $groupTarget,
    ],
    $groupPeople
);
rechatSelectionAssertSame('ok', $groupOutput, 'group-mode rechat should still return ok when direct-target fallback is selected without API access');
rechatSelectionAssertSameInt(
    0,
    count($GLOBALS['db']->fetchAll('SELECT rowid FROM eventlog')),
    'group-mode fallback selection should not store duplicate rechat rows when API access is absent'
);
rechatSelectionAssert(
    getNpcData($groupTarget) === false,
    'group-mode direct fallback must still respect disabled speaker rechat and avoid selecting the initiator'
);

echo "All rechat selection regression tests passed.\n";
