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
        "SELECT name, personality, backstory, speechstyle, occupation, goals
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$name]
    );
}

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));

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

echo 'All bio_unique regression tests passed.' . PHP_EOL;
exit(0);

