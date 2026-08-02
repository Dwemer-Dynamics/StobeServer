<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

/**
 * Lightweight regression tests for renamed NPC snapshot hydration.
 *
 * These tests run directly against the configured Stobe DB but each test is
 * wrapped in a transaction and rolled back, so no permanent data is written.
 */

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
    if ($needle === '' || strpos($haystack, $needle) === false) {
        testFail($message . ' (missing="' . $needle . '", actual="' . $haystack . '")');
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

function fetchNpcRowByName(string $name): array|false
{
    $db = $GLOBALS['db'];
    return $db->fetchOne(
        "SELECT name, original_name, race, faction, gender, equipment, skills,
                COALESCE(metadata->>'storage_id', '') AS storage_id,
                gamets_last_updated
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$name]
    );
}

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));

runInRollbackTransaction('batch identity rename preserves unique NPC names', function (): void {
    $uniqueNames = ['Griffin', 'Hobbs', 'Green Finger', 'Cannibal Hunter Robun'];
    $identities = [];
    foreach ($uniqueNames as $index => $name) {
        assertTrue(!isServerRenameEligibleName($name), $name . ' should not be rename eligible');
        $identities[] = [
            'serial' => strval(1100000000 + $index),
            'name' => $name,
            'gender' => 'male',
            'race' => 'Greenlander',
            'faction' => 'Drifters',
        ];
    }

    $decisions = batchIdentityRenameDecisions($identities);
    assertTrue(count($decisions) === count($uniqueNames), 'unique NPC batch should return one decision per identity');
    foreach ($decisions as $index => $decision) {
        assertSameText('ok', strval($decision['status'] ?? ''), $uniqueNames[$index] . ' should keep its original name');
        assertSameText('', strval($decision['new_name'] ?? ''), $uniqueNames[$index] . ' should not receive a generated name');
    }
});

runInRollbackTransaction('manual rename resolves source by storage_id', function () use ($seed): void {
    $sourceName = 'UT_SRC_' . $seed . '_Bandit';
    $renamedName = 'UT_RNM_' . $seed . '_Quinn [Nomadic Bandit]';
    $storageId = 'hand_' . strval(random_int(200000000, 399999999));

    storeNpcProfile($sourceName, [
        'race' => 'Greenlander',
        'faction' => 'Nomadic Bandits',
        'gender' => 'male',
        'equipment' => 'Rusty Club',
        'skills' => 'ATTR: STR 11',
        'metadata' => ['storage_id' => $storageId],
    ]);

    $result = persistManualRename('MISSING_' . $sourceName, $renamedName, $storageId);
    assertSameText('ok', strval($result['status'] ?? ''), 'rename status should be ok');

    $row = fetchNpcRowByName($renamedName);
    assertTrue(is_array($row), 'renamed row should exist');
    assertSameText($renamedName, strval($row['name'] ?? ''), 'renamed row should keep new name');
    assertSameText($storageId, strval($row['storage_id'] ?? ''), 'renamed row should keep storage_id');
    assertSameText('Greenlander', strval($row['race'] ?? ''), 'rename should preserve existing race');
    assertContainsText('Nomadic Bandits', strval($row['faction'] ?? ''), 'rename should preserve faction');
});

runInRollbackTransaction('storeNpcSnapshot hydrates renamed placeholder without medical payload', function () use ($seed): void {
    $name = 'UT_RNM_' . $seed . '_Haze [Wandering Poor Ronin]';
    $storageId = 'hand_' . strval(random_int(400000000, 799999999));

    storeNpcProfile($name, [
        'metadata' => ['storage_id' => $storageId],
    ]);

    $stored = storeNpcSnapshot([
        'name' => $name,
        'storage_id' => $storageId,
        'race' => 'Scorchlander',
        'faction' => 'Wandering Ronin',
        'faction_id' => '1535000-test.mod',
        'gender' => 'female',
        'equipment' => "Assassin's Rags, Katana",
        'stats' => ['strength' => 33, 'dexterity' => 21],
        'medical' => [],
        'inventory' => [],
        'environment' => ['indoors' => false],
    ], 777001);

    assertTrue($stored, 'storeNpcSnapshot should return true');

    $row = fetchNpcRowByName($name);
    assertTrue(is_array($row), 'hydrated renamed row should exist');
    assertSameText('Scorchlander', strval($row['race'] ?? ''), 'hydrated row should set race');
    assertContainsText('Wandering Ronin', strval($row['faction'] ?? ''), 'hydrated row should set faction');
    assertSameText('female', strval($row['gender'] ?? ''), 'hydrated row should set gender');
    assertContainsText('Katana', strval($row['equipment'] ?? ''), 'hydrated row should set equipment');
    assertTrue(intval($row['gamets_last_updated'] ?? 0) >= 777001, 'hydrated row should update gamets');
});

runInRollbackTransaction('storeNpcSnapshot does not downgrade known race to Unknown', function () use ($seed): void {
    $name = 'UT_RNM_' . $seed . '_Indigo [Messenger Pacifier]';
    $storageId = 'hand_' . strval(random_int(800000000, 1199999999));

    storeNpcProfile($name, [
        'race' => 'Shek',
        'faction' => 'Messenger',
        'gender' => 'male',
        'metadata' => ['storage_id' => $storageId],
    ]);

    $stored = storeNpcSnapshot([
        'name' => $name,
        'storage_id' => $storageId,
        'race' => 'Unknown',
        'faction' => '',
        'gender' => '',
        'equipment' => '',
        'stats' => [],
        'medical' => [],
    ], 777002);

    assertTrue($stored, 'storeNpcSnapshot should return true for unknown-race payload');

    $row = fetchNpcRowByName($name);
    assertTrue(is_array($row), 'row should still exist');
    assertSameText('Shek', strval($row['race'] ?? ''), 'known race should not be overwritten by Unknown');
});

runInRollbackTransaction('batch identity rename seeds profile fields for renamed placeholders', function () use ($seed): void {
    $serial = strval(random_int(1200000000, 1800000000));
    $generic = 'UT_GEN_' . $seed . '_Nomadic Bandit';
    $decisions = batchIdentityRenameDecisions([
        [
            'serial' => $serial,
            'name' => $generic,
            'gender' => 'female',
            'race' => 'Scorchlander',
            'faction' => 'Nomadic Bandits',
        ],
    ]);

    assertTrue(count($decisions) === 1, 'batch rename should return one decision');
    assertSameText('rename', strval($decisions[0]['status'] ?? ''), 'batch rename should request rename');

    $newName = strval($decisions[0]['new_name'] ?? '');
    assertTrue($newName !== '', 'batch rename should return new_name');
    assertContainsText('[', $newName, 'batch rename should include original in bracket format');

    $row = fetchNpcRowByName($newName);
    assertTrue(is_array($row), 'renamed batch row should exist');
    assertSameText('Scorchlander', strval($row['race'] ?? ''), 'batch rename should seed race');
    assertContainsText('Nomadic Bandits', strval($row['faction'] ?? ''), 'batch rename should seed faction');
    assertSameText('female', strtolower(strval($row['gender'] ?? '')), 'batch rename should seed gender');
    assertSameText('hand_' . strval(intval($serial)), strval($row['storage_id'] ?? ''), 'batch rename should persist storage_id');
});

runInRollbackTransaction('batch identity rename retries when first generated name already exists', function () use ($seed): void {
    $serial = strval(random_int(2140000001, 2300000000));
    $generic = 'UT_GEN_COLLIDE_' . $seed . '_Bandit';

    $firstSeenOriginal = ensureOriginalName($generic, $generic);
    $firstGenerated = generateUniqueLoreName('', $generic, '', '');
    $occupiedName = formatAutoRenameWithOriginal($firstGenerated, $firstSeenOriginal);
    assertTrue($occupiedName !== '', 'occupied candidate should be generated');

    storeNpcProfile($occupiedName, [
        'metadata' => ['storage_id' => 'hand_collision_' . strval(random_int(100000, 999999))],
    ]);

    $decisions = batchIdentityRenameDecisions([
        [
            'serial' => intval($serial),
            'name' => $generic,
            'gender' => 'male',
            'race' => 'Greenlander',
            'faction' => 'Bandit',
        ],
    ]);

    assertTrue(count($decisions) === 1, 'collision retry should return one decision');
    assertSameText('rename', strval($decisions[0]['status'] ?? ''), 'collision retry should still rename');
    $newName = strval($decisions[0]['new_name'] ?? '');
    assertTrue($newName !== '', 'collision retry should return a new name');
    assertTrue(strtolower($newName) !== strtolower($occupiedName), 'collision retry should avoid occupied name');

    $row = fetchNpcRowByName($newName);
    assertTrue(is_array($row), 'collision retry renamed row should exist');
    assertSameText('hand_' . strval(intval($serial)), strval($row['storage_id'] ?? ''), 'collision retry should persist storage_id');
});

runInRollbackTransaction('batch identity rename context hydrates gameplay fields', function () use ($seed): void {
    $serial = strval(random_int(1800000000, 2140000000));
    $generic = 'UT_GEN_CTX_' . $seed . '_Wandering Poor Ronin';
    $decisions = batchIdentityRenameDecisions([
        [
            'serial' => intval($serial),
            'name' => $generic,
            'gender' => 'female',
            'race' => 'Greenlander',
            'faction' => 'No Faction',
            'context' => [
                'game_ts' => 889900,
                'equipment' => "Assassin's Rags, Drifter's Boots, Rusted Sabre",
                'stats' => [
                    'strength' => 18,
                    'dexterity' => 21,
                    'toughness' => 14,
                ],
                'medical' => [
                    'blood' => 72,
                    'max_blood' => 92,
                    'hunger' => 170,
                    'hunger_max' => 300,
                    'blood_rate' => 0,
                    'limbs' => [
                        'head' => 95,
                        'head_max' => 100,
                        'stomach' => 90,
                        'stomach_max' => 100,
                        'left_arm' => 80,
                        'left_arm_max' => 100,
                    ],
                ],
            ],
        ],
    ]);

    assertTrue(count($decisions) === 1, 'batch rename context should return one decision');
    assertSameText('rename', strval($decisions[0]['status'] ?? ''), 'batch rename context should request rename');
    $newName = strval($decisions[0]['new_name'] ?? '');
    assertTrue($newName !== '', 'batch rename context should return new_name');

    $db = $GLOBALS['db'];
    $row = $db->fetchOne(
        "SELECT name,
                emote_moods,
                occupation,
                skills,
                goals,
                equipment,
                gamets_last_updated,
                extended_data::text AS extended_data,
                limbs::text AS limbs,
                blood,
                hunger
         FROM core_npc_master
         WHERE LOWER(name) = LOWER($1)
         LIMIT 1",
        [$newName]
    );
    assertTrue(is_array($row), 'batch rename context row should exist');
    $emoteMoods = strval($row['emote_moods'] ?? '');
    assertTrue($emoteMoods !== '', 'batch context should set emote moods');
    assertContainsText('Faction:', strval($row['occupation'] ?? ''), 'batch context should set occupation');
    assertContainsText('ATTR:', strval($row['skills'] ?? ''), 'batch context should set skills');
    assertContainsText('Survive', strval($row['goals'] ?? ''), 'batch context should set goals');
    assertContainsText('Rusted Sabre', strval($row['equipment'] ?? ''), 'batch context should set equipment');
    assertTrue(intval($row['gamets_last_updated'] ?? 0) >= 889900, 'batch context should update gamets');
    $extendedDataDecoded = json_decode(strval($row['extended_data'] ?? '{}'), true);
    assertTrue(is_array($extendedDataDecoded), 'batch context extended_data should decode to object');
    assertTrue(!array_key_exists('stats', $extendedDataDecoded), 'extended_data should not duplicate stats');
    assertTrue(!array_key_exists('medical', $extendedDataDecoded), 'extended_data should not duplicate medical');
    assertTrue(!array_key_exists('inventory', $extendedDataDecoded), 'extended_data should not duplicate inventory');
    assertContainsText('head_max', strval($row['limbs'] ?? ''), 'batch context should set limbs');
    assertTrue(intval($row['blood'] ?? 0) > 0, 'batch context should set blood');
});

echo 'All rename/snapshot regression tests passed.' . PHP_EOL;
exit(0);

