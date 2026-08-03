<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

$db = $GLOBALS['db'];

function testFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        testFail($message);
    }
}

function assertSameText(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        testFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function assertContainsText(string $needle, string $haystack, string $message): void
{
    if ($needle === '' || stripos($haystack, $needle) === false) {
        testFail($message . ' (needle="' . $needle . '", actual="' . $haystack . '")');
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    if ($needle !== '' && stripos($haystack, $needle) !== false) {
        testFail($message . ' (needle="' . $needle . '", actual="' . $haystack . '")');
    }
}

/**
 * @param callable():void $fn
 */
function runInRollbackTransaction(string $label, callable $fn): void
{
    $db = $GLOBALS['db'];
    $db->exec('BEGIN');
    try {
        $fn();
        $db->exec('ROLLBACK');
        echo 'PASS: ' . $label . PHP_EOL;
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function fetchNpcTraits(string $name): array|false
{
    $db = $GLOBALS['db'];
    return $db->fetchOne(
        "SELECT name, personality, backstory, speechstyle, occupation, appearance, goals
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$name]
    );
}

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));

$unknownMaleVoice = stobeSelectVoiceIdForNpc('UT_UNKNOWN_MALE_' . $seed, 'Unknown', 'male');
$unknownFemaleVoice = stobeSelectVoiceIdForNpc('UT_UNKNOWN_FEMALE_' . $seed, '', 'female');
assertTrue(
    preg_match('/^male(?:[1-9]|1[0-9]|20)$/', $unknownMaleVoice) === 1,
    'unknown male race should use the legacy male voice pool'
);
assertTrue(
    preg_match('/^female(?:[1-9]|1[0-9]|20)$/', $unknownFemaleVoice) === 1,
    'unknown female race should use the legacy female voice pool'
);

runInRollbackTransaction('bio_unique precedence and bracket fallback in selector', function () use ($seed): void {
    $db = $GLOBALS['db'];
    $baseName = 'UT_BIO_UNIQUE_' . $seed . '_Kuir';
    $bracketName = $baseName . ' [Security Guard]';

    $db->exec(
        "INSERT INTO bio_unique (name, type, description)
         VALUES
         ($1, 'personality', $2),
         ($1, 'backstory', $3),
         ($1, 'speechstyle', $4),
         ($1, 'occupation', $5),
         ($1, 'goals', $6)",
        [
            $baseName,
            'UT unique personality',
            'UT unique backstory',
            'UT unique speechstyle',
            'UT unique occupation',
            'UT unique goals',
        ]
    );

    $selection = selectBioTraitsForNpc($bracketName, 'greenlander', 'male', 'No Faction');
    $traits = is_array($selection['traits'] ?? null) ? $selection['traits'] : [];
    $sources = is_array($selection['sources'] ?? null) ? $selection['sources'] : [];

    assertSameText('UT unique personality', strval($traits['personality'] ?? ''), 'selector should use unique personality');
    assertSameText('UT unique backstory', strval($traits['backstory'] ?? ''), 'selector should use unique backstory');
    assertSameText('UT unique speechstyle', strval($traits['speechstyle'] ?? ''), 'selector should use unique speechstyle');
    assertSameText('UT unique occupation', strval($traits['occupation'] ?? ''), 'selector should use unique occupation');
    assertSameText('UT unique goals', strval($traits['goals'] ?? ''), 'selector should use unique goals');
    assertSameText('unique', strval($sources['personality'] ?? ''), 'source should be unique for personality');
    assertSameText('unique', strval($sources['goals'] ?? ''), 'source should be unique for goals');
});

runInRollbackTransaction('bio_random occupation uses original pre-rename name', function () use ($seed): void {
    $db = $GLOBALS['db'];
    $occupationMatch = 'UT random occupation security guard ' . $seed;
    $occupationOther = 'UT random occupation nomadic bandit ' . $seed;

    $db->exec(
        "INSERT INTO bio_random (type, description, name, race, gender, faction)
         VALUES
         ('occupation', $1, 'security guard', '', '', ''),
         ('occupation', $2, 'nomadic bandit', '', '', '')",
        [$occupationMatch, $occupationOther]
    );

    $selectionFromBracket = selectBioTraitsForNpc('UT_RENAMED_' . $seed . ' [Security Guard]', '', '', '');
    $traitsFromBracket = is_array($selectionFromBracket['traits'] ?? null) ? $selectionFromBracket['traits'] : [];
    $sourcesFromBracket = is_array($selectionFromBracket['sources'] ?? null) ? $selectionFromBracket['sources'] : [];
    assertSameText($occupationMatch, strval($traitsFromBracket['occupation'] ?? ''), 'selector should use occupation matched by bracket original name');
    assertSameText('random', strval($sourcesFromBracket['occupation'] ?? ''), 'source should be random for name-matched occupation');

    $selectionFromExplicitOriginal = selectBioTraitsForNpc('UT_RENAMED_' . $seed, '', '', '', 'Security Guard');
    $traitsFromExplicitOriginal = is_array($selectionFromExplicitOriginal['traits'] ?? null) ? $selectionFromExplicitOriginal['traits'] : [];
    assertSameText($occupationMatch, strval($traitsFromExplicitOriginal['occupation'] ?? ''), 'selector should use occupation matched by explicit original_name');
});

runInRollbackTransaction('storeNpcProfile resolves unique first and random fallback second', function () use ($seed): void {
    $db = $GLOBALS['db'];
    $name = 'UT_BIO_PROFILE_' . $seed . '_Leaf';
    $raceToken = 'ut_race_' . $seed;

    $db->exec(
        "INSERT INTO bio_unique (name, type, description)
         VALUES ($1, 'personality', $2)",
        [$name, 'UT profile unique personality']
    );
    $db->exec(
        "INSERT INTO bio_random (type, description, race, gender, faction)
         VALUES ('speechstyle', $1, $2, '', '')",
        ['UT profile random speechstyle', $raceToken]
    );

    storeNpcProfile($name, [
        'race' => $raceToken,
        'faction' => '',
        'gender' => '',
    ]);

    $row = fetchNpcTraits($name);
    assertTrue(is_array($row), 'profile row should exist');
    assertSameText('UT profile unique personality', strval($row['personality'] ?? ''), 'profile should use unique personality');
    assertSameText('UT profile random speechstyle', strval($row['speechstyle'] ?? ''), 'profile should fallback to random speechstyle');
});

runInRollbackTransaction('storeNpcSnapshot resolves unique traits for bracketed names', function () use ($seed): void {
    $db = $GLOBALS['db'];
    $baseName = 'UT_BIO_SNAPSHOT_' . $seed . '_Stream';
    $name = $baseName . ' [Outcast Warrior]';
    $storageId = 'hand_' . strval(random_int(200000000, 799999999));

    $db->exec(
        "INSERT INTO bio_unique (name, type, description)
         VALUES
         ($1, 'goals', $2),
         ($1, 'occupation', $3)",
        [$baseName, 'UT snapshot unique goals', 'UT snapshot unique occupation']
    );

    $stored = storeNpcSnapshot([
        'name' => $name,
        'storage_id' => $storageId,
        'race' => 'Scorchlander',
        'faction' => 'No Faction',
        'gender' => 'female',
        'equipment' => "Assassin's Rags",
        'stats' => [],
        'medical' => [],
        'inventory' => [],
        'environment' => [],
    ], 991100);

    assertTrue($stored, 'storeNpcSnapshot should return true');
    $row = fetchNpcTraits($name);
    assertTrue(is_array($row), 'snapshot row should exist');
    assertSameText('UT snapshot unique goals', strval($row['goals'] ?? ''), 'snapshot should use unique goals');
    assertSameText('UT snapshot unique occupation', strval($row['occupation'] ?? ''), 'snapshot should use unique occupation');
});

runInRollbackTransaction('storeNpcProfile appends unique appearance type text to generated appearance', function () use ($seed): void {
    $db = $GLOBALS['db'];
    $name = 'UT_BIO_APPEARANCE_UNIQUE_' . $seed . '_Rust';
    $appearanceExtra = 'A jagged scar runs across the left cheek.';

    $db->exec(
        "INSERT INTO bio_unique (name, type, description)
         VALUES ($1, 'appearance', $2)",
        [$name, $appearanceExtra]
    );

    storeNpcProfile($name, [
        'appearance' => 'Tall and broad-shouldered.',
        'race' => 'Scorchlander',
        'gender' => 'male',
        'faction' => 'Tech Hunters',
    ]);

    $row = fetchNpcTraits($name);
    assertTrue(is_array($row), 'profile row should exist for unique appearance test');
    assertContainsText('Tall and broad-shouldered.', strval($row['appearance'] ?? ''), 'base appearance should be preserved');
    assertContainsText($appearanceExtra, strval($row['appearance'] ?? ''), 'unique appearance extra should be appended');
});

runInRollbackTransaction('storeNpcProfile appends random appearance type text to generated appearance', function () use ($seed): void {
    $db = $GLOBALS['db'];
    $name = 'UT_BIO_APPEARANCE_RANDOM_' . $seed . '_Dust';
    $appearanceExtra = 'Their coat is dust-caked and sun-bleached.';
    $raceToken = 'ut_random_race_' . $seed;

    $db->exec(
        "INSERT INTO bio_random (type, description, race, gender, faction)
         VALUES ('appearance', $1, $2, 'female', 'Nomads')",
        [$appearanceExtra, $raceToken]
    );

    storeNpcProfile($name, [
        'appearance' => 'Lean frame with wary eyes.',
        'race' => $raceToken,
        'gender' => 'female',
        'faction' => 'Nomads',
    ]);

    $row = fetchNpcTraits($name);
    assertTrue(is_array($row), 'profile row should exist for random appearance test');
    assertContainsText('Lean frame with wary eyes.', strval($row['appearance'] ?? ''), 'base random-test appearance should be preserved');
    assertContainsText($appearanceExtra, strval($row['appearance'] ?? ''), 'random appearance extra should be appended');
});

runInRollbackTransaction('storeNpcSnapshot rebuilds generated appearance before applying unique appearance and horns', function () use ($seed): void {
    $db = $GLOBALS['db'];
    $name = 'UT_BIO_APPEARANCE_SNAPSHOT_' . $seed . '_Esata';
    $storageId = 'hand_' . strval(random_int(200000000, 799999999));
    $staleAppearance = 'Stale imported appearance that should be replaced.';
    $appearanceExtra = 'A powerful female Shek whose posture reflects veteran confidence.';

    $db->exec(
        "INSERT INTO bio_unique (name, type, description)
         VALUES ($1, 'appearance', $2)",
        [$name, $appearanceExtra]
    );

    storeNpcProfile($name, [
        'appearance' => 'Initial placeholder appearance.',
        'race' => 'Shek',
        'gender' => 'female',
        'faction' => 'Stone Golem',
        'metadata' => ['storage_id' => $storageId],
    ]);

    $existingRow = $db->fetchOne(
        "SELECT id
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$name]
    );
    assertTrue(is_array($existingRow), 'existing row should exist before stale appearance overwrite');
    updateNpcById(intval($existingRow['id'] ?? 0), [
        'appearance' => $staleAppearance,
    ]);

    $stored = storeNpcSnapshot([
        'name' => $name,
        'storage_id' => $storageId,
        'race' => 'Shek',
        'faction' => 'Stone Golem',
        'gender' => 'female',
        'equipment' => 'Plated longboots',
        'stats' => [],
        'medical' => [],
        'inventory' => [],
        'environment' => [],
        'horn_sliders' => ['average' => 0.15],
    ], 991200);

    assertTrue($stored, 'storeNpcSnapshot should succeed for appearance precedence test');
    $row = fetchNpcTraits($name);
    assertTrue(is_array($row), 'snapshot row should exist for appearance precedence test');
    assertContainsText('Female Shek with a middle-aged look.', strval($row['appearance'] ?? ''), 'snapshot should rebuild generated appearance identity');
    assertContainsText('Build appears average.', strval($row['appearance'] ?? ''), 'snapshot should rebuild generated appearance build');
    assertContainsText($appearanceExtra, strval($row['appearance'] ?? ''), 'snapshot should append unique appearance extra');
    assertContainsText('They have very large horns.', strval($row['appearance'] ?? ''), 'snapshot should append horn detection sentence');
    assertNotContainsText($staleAppearance, strval($row['appearance'] ?? ''), 'snapshot should not reuse stale imported appearance base');
});

echo 'All bio_unique regression tests passed.' . PHP_EOL;
exit(0);

