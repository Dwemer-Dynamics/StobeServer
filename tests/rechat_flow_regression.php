<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

function rechatFlowFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function rechatFlowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        rechatFlowFail($message);
    }
}

function rechatFlowAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        rechatFlowFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function rechatFlowAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        rechatFlowFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

function rechatFlowClearEventlog(): void
{
    $db = $GLOBALS['db'];
    $db->exec('DELETE FROM eventlog');
}

function rechatFlowEventRows(): array
{
    $db = $GLOBALS['db'];
    return $db->fetchAll('SELECT type, data FROM eventlog ORDER BY rowid ASC');
}

function rechatFlowRunProcessor(string $eventType, string $eventData, array $query = [], string $peopleCache = ''): string
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
    $gamets = 654;

    ob_start();
    include __DIR__ . '/../processor/rechat.php';
    $output = ob_get_clean();

    $_GET = $oldGet;
    if ($hadPeopleCache) {
        $GLOBALS['CACHE_PEOPLE'] = $oldPeopleCache;
    } else {
        unset($GLOBALS['CACHE_PEOPLE']);
    }

    return trim(strval($output));
}

rechatFlowAssert(
    stobeShouldSuppressRechatInitiatorTts('Ruka', 'ruka', false),
    'disabled player dialogue audio should suppress a case-insensitive initiator rechat match'
);
rechatFlowAssert(
    !stobeShouldSuppressRechatInitiatorTts('Ruka', 'Ruka', true),
    'enabled player dialogue audio should keep initiator rechat TTS eligible'
);
rechatFlowAssert(
    !stobeShouldSuppressRechatInitiatorTts('Beep', 'Ruka', false),
    'disabled player dialogue audio should not suppress a different NPC responder'
);
rechatFlowAssert(
    !stobeShouldSuppressRechatInitiatorTts('Ruka', '', false),
    'missing initiator identity should not suppress NPC response audio'
);

$oldGet = $_GET;
$_GET = array_merge($_GET, ['tts_enabled' => '1']);
ob_start();
ob_start();
stobeStreamDialogueResponse(
    'UT_RECHAT_AUDIO_INITIATOR',
    false,
    'Silent rechat line.',
    [],
    'rechat',
    'UT_RECHAT_AUDIO_LISTENER',
    0,
    ['suppress_tts' => true]
);
$innerOutput = ob_get_clean();
$outerOutput = ob_get_clean();
$_GET = $oldGet;
$silentRechatOutput = strval($outerOutput . $innerOutput);
rechatFlowAssert(
    strpos($silentRechatOutput, 'UT_RECHAT_AUDIO_INITIATOR|ScriptQueue|Silent rechat line.') !== false,
    'suppressed initiator rechat should preserve streamed dialogue text'
);
rechatFlowAssert(
    strpos($silentRechatOutput, '|tts=') === false && strpos($silentRechatOutput, '|ttsd=') === false,
    'suppressed initiator rechat should omit TTS metadata'
);

$participants = extractParticipantIdentities([
    'people' => '["Beep|101","Agnu","Beep","Agnu|202"]',
    'profile' => 'Ruka',
    'speaker' => 'Beep',
    'npcs' => [
        ['name' => 'Crumblejon', 'storage_id' => '303'],
        'Burn',
    ],
    'nearby' => [
        ['name' => 'Sadneil', 'id' => '404'],
        'Ruka',
    ],
]);
$participantMap = [];
foreach ($participants as $participant) {
    $name = strval($participant['name'] ?? '');
    if ($name === '') {
        continue;
    }
    if (!isset($participantMap[$name])) {
        $participantMap[$name] = [];
    }
    $participantMap[$name][] = strval($participant['storage_id'] ?? '');
}
foreach ($participantMap as $name => $storageIds) {
    $storageIds = array_values(array_unique($storageIds));
    sort($storageIds);
    $participantMap[$name] = $storageIds;
}
ksort($participantMap);
rechatFlowAssertSame(
    '{"Agnu":["hand_202"],"Beep":["","hand_101"],"Burn":[""],"Crumblejon":["hand_303"],"Ruka":[""],"Sadneil":["hand_404"]}',
    json_encode($participantMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'participant identity extraction should preserve SID-bearing identities while merging people, npc, and nearby sources'
);

rechatFlowClearEventlog();
$output = rechatFlowRunProcessor(
    'rechat',
    'Ruka: Keep moving. (talking to: Beep)',
    ['mode' => 'whisper', 'profile' => 'Beep', 'people' => '["Ruka","Beep"]'],
    '["Ruka","Beep"]'
);
rechatFlowAssertSame('ok', $output, 'whisper rechat should short-circuit with ok');
rechatFlowAssertSameInt(0, count(rechatFlowEventRows()), 'rechat trigger echo should not store a duplicate incoming event row');

rechatFlowClearEventlog();
$output = rechatFlowRunProcessor(
    'limb_loss',
    'Beep loses a limb near the outpost.',
    ['mode' => 'whisper', 'profile' => 'Beep', 'people' => '["Ruka","Beep"]'],
    '["Ruka","Beep"]'
);
rechatFlowAssertSame('ok', $output, 'private-mode limb-loss rechat route should short-circuit with ok');
$rows = rechatFlowEventRows();
rechatFlowAssertSameInt(1, count($rows), 'limb_loss should still persist the incoming event before private-mode skip');
rechatFlowAssertSame('limb_loss', strval($rows[0]['type'] ?? ''), 'stored limb-loss row should preserve the original event type');
rechatFlowAssert(
    strpos(strval($rows[0]['data'] ?? ''), 'Beep loses a limb near the outpost.') !== false,
    'stored limb-loss row should preserve the original payload'
);

rechatFlowClearEventlog();
$output = rechatFlowRunProcessor(
    'rechat',
    stobeNarratorName() . ': The wind cuts across the camp. (talking to: Beep)',
    ['mode' => 'talk', 'profile' => 'Beep', 'people' => '["Beep"]'],
    '["Beep"]'
);
rechatFlowAssertSame('ok', $output, 'narrator-speaker rechat should short-circuit with ok');
rechatFlowAssertSameInt(0, count(rechatFlowEventRows()), 'narrator-speaker rechat should not store a duplicate incoming event row');

rechatFlowClearEventlog();
$output = rechatFlowRunProcessor(
    'rechat',
    'Ruka: We should keep going. (talking to: Beep)',
    ['mode' => 'talk', 'profile' => 'Beep', 'people' => '["Ruka","Beep"]', 'rechat_depth' => '4'],
    '["Ruka","Beep"]'
);
rechatFlowAssertSame('ok', $output, 'over-budget rechat depth should short-circuit with ok');
rechatFlowAssertSameInt(0, count(rechatFlowEventRows()), 'over-budget rechat depth should stop before writing extra dialogue rows');

rechatFlowClearEventlog();
storeEvent('inputtext', time(), 100, 'Ruka: First line (talking to: Beep)');
storeEvent('rechat', time(), 100, 'Beep: Second line (talking to: Ruka)');
storeEvent('narration', time() - 5000, 100, 'Old narration');
$GLOBALS['db']->exec(
    "UPDATE eventlog
     SET localts = $1
     WHERE type = 'narration'",
    [time() - 5000]
);
$history = DataRechatHistory('', 120, 10);
rechatFlowAssertSameInt(2, count($history), 'rechat history should include only recent dialogue-turn events inside the requested window');
rechatFlowAssertSameInt(100, intval($history[0]['gamets'] ?? 0), 'rechat history should preserve recent gamets values');

storeEvent('inputtext_s', time(), 100, 'Agnu: Third line (talking to: Ruka)');
$legacyEligible = isRechatEligible(false, '', 0);
rechatFlowAssert(!$legacyEligible, 'legacy rechat fallback should block when recent dialogue history already fills the default chain budget');
rechatFlowAssert(!isRechatEligible(false, '', 4), 'explicit rechat depth beyond the default max rounds should be rejected');

echo "All rechat flow regression tests passed.\n";
