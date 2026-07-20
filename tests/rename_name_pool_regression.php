<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/rename_name_pool_functions.php';

$db = $GLOBALS['db'];

function renamePoolFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function renamePoolAssert(bool $condition, string $message): void
{
    if (!$condition) {
        renamePoolFail($message);
    }
}

function renamePoolCsv(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'stobe_names_');
    if ($path === false) {
        renamePoolFail('Could not create a temporary CSV file.');
    }
    file_put_contents($path, $contents);
    try {
        return stobeRenameNameParseCsv($path);
    } finally {
        @unlink($path);
    }
}

$minimal = renamePoolCsv("name\nKato\nSable\n");
renamePoolAssert(boolval($minimal['ok'] ?? false), 'one-column CSV with header should parse');
renamePoolAssert(count($minimal['rows'] ?? []) === 2, 'one-column CSV should import both names');
renamePoolAssert(boolval($minimal['rows'][0]['is_enabled'] ?? false), 'missing enabled column should default true');

$headerless = renamePoolCsv("Kato\nSable\n");
renamePoolAssert(count($headerless['rows'] ?? []) === 2, 'headerless one-column CSV should preserve the first name');

$full = renamePoolCsv("name,gender,race,faction,enabled\nNami,female,Greenlander,United Cities,false\n");
renamePoolAssert(count($full['rows'] ?? []) === 1, 'full CSV should parse one row');
renamePoolAssert(($full['rows'][0]['gender'] ?? '') === 'female', 'full CSV should preserve gender');
renamePoolAssert(!boolval($full['rows'][0]['is_enabled'] ?? true), 'full CSV should parse disabled state');

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));
$db->exec('BEGIN');
try {
    $enabledName = 'UT_NAME_ENABLED_' . $seed;
    $disabledName = 'UT_NAME_DISABLED_' . $seed;
    $overrideName = 'UT_NAME_OVERRIDE_' . $seed;
    $raceEnabled = 'UT_RACE_ENABLED_' . $seed;
    $raceDisabled = 'UT_RACE_DISABLED_' . $seed;
    $raceOverride = 'UT_RACE_OVERRIDE_' . $seed;

    $db->exec(
        'INSERT INTO rename_global_custom (name, gender, race, faction, is_enabled) VALUES ($1, $2, $3, $4, TRUE)',
        [$enabledName, 'neutral', $raceEnabled, '']
    );
    $db->exec(
        'INSERT INTO rename_global_custom (name, gender, race, faction, is_enabled) VALUES ($1, $2, $3, $4, FALSE)',
        [$disabledName, 'neutral', $raceDisabled, '']
    );
    $db->exec(
        'INSERT INTO rename_global (name, gender, race, faction, is_enabled) VALUES ($1, $2, $3, $4, TRUE)',
        [$overrideName, 'neutral', $raceOverride, '']
    );
    $db->exec(
        'INSERT INTO rename_global_custom (name, gender, race, faction, is_enabled) VALUES ($1, $2, $3, $4, FALSE)',
        [$overrideName, 'neutral', $raceOverride, '']
    );

    $enabledPool = loadGlobalNamePool('neutral', $raceEnabled, '');
    renamePoolAssert(in_array($enabledName, $enabledPool, true), 'enabled custom name should be available to the generator');
    $disabledPool = loadGlobalNamePool('neutral', $raceDisabled, '');
    renamePoolAssert(!in_array($disabledName, $disabledPool, true), 'disabled custom name should be excluded from the generator');
    $overridePool = loadGlobalNamePool('neutral', $raceOverride, '');
    renamePoolAssert(!in_array($overrideName, $overridePool, true), 'disabled custom override should suppress its base name');

    $saved = stobeRenameNameSaveCustom($db, ['name' => 'UT_SAVE_' . $seed, 'gender' => 'male']);
    renamePoolAssert(boolval($saved['ok'] ?? false), 'custom helper should insert a valid name');
    $duplicate = stobeRenameNameSaveCustom($db, ['name' => strtolower('UT_SAVE_' . $seed), 'gender' => 'male']);
    renamePoolAssert(!boolval($duplicate['ok'] ?? true), 'custom helper should reject case-insensitive duplicates');

    $genericName = 'Wandering Poor Ronin';
    $seeds = [];
    $generated = [];
    for ($index = 1; $index <= 16; $index++) {
        $identitySeed = buildIdentityRenameNameSeed($genericName, 'hand_' . strval($index), strval($index));
        $seeds[$identitySeed] = true;
        $generated[generateUniqueLoreName('neutral', $identitySeed, '', '')] = true;
    }
    renamePoolAssert(count($seeds) === 16, 'persistent identity should be part of every rename seed');
    renamePoolAssert(count($generated) > 1, 'different persistent identities should distribute across the name pool');

    $occupiedBase = generateUniqueLoreName('neutral', 'occupied_' . $seed, '', '');
    storeNpcProfile($occupiedBase . ' [Hungry Bandit]', [
        'metadata' => ['storage_id' => 'ut_occupied_' . $seed],
    ]);
    $reservations = loadNpcLoreNameReservations();
    renamePoolAssert(
        isset($reservations['bases'][normalizeGeneratedLoreNameBase($occupiedBase)]),
        'a bracketed NPC profile should reserve its generated base name'
    );
    $nextName = generateUniqueLoreName('neutral', 'occupied_' . $seed, '', '');
    renamePoolAssert(
        normalizeGeneratedLoreNameBase($nextName) !== normalizeGeneratedLoreNameBase($occupiedBase),
        'generator should scan past a base already used by a bracketed profile'
    );

    renamePoolAssert(normalizeGeneratedLoreNameBase('Ulric 7 [Dust Bandit]') === 'ulric', 'numeric suffixes should reserve the root lore name');
    $db->exec('ROLLBACK');
} catch (Throwable $exception) {
    $db->exec('ROLLBACK');
    throw $exception;
}

echo 'All rename name-pool regression tests passed.' . PHP_EOL;
exit(0);
