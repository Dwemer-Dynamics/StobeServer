<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

$db = $GLOBALS['db'];

function historyTestFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function historyAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        historyTestFail($message);
    }
}

/**
 * @param callable():void $fn
 */
function historyRunInRollbackTransaction(string $label, callable $fn): void
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

function historyFetchNpcId(string $name): int
{
    $db = $GLOBALS['db'];
    $row = $db->fetchOne(
        "SELECT id FROM core_npc WHERE LOWER(name) = LOWER($1) LIMIT 1",
        [$name]
    );
    return intval($row['id'] ?? 0);
}

function historyCountRows(int $npcId): int
{
    $db = $GLOBALS['db'];
    $row = $db->fetchOne(
        "SELECT COUNT(*)::int AS count
         FROM core_npc_master_history
         WHERE npc_id = $1",
        [$npcId]
    );
    return intval($row['count'] ?? 0);
}

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));

historyRunInRollbackTransaction('updateNpcById stores pre-update snapshot', function () use ($seed): void {
    $name = 'UT_HISTORY_' . $seed . '_UI';
    storeNpcProfile($name, [
        'race' => 'Scorchlander',
        'faction' => 'No Faction',
        'gender' => 'male',
        'skills' => 'ATTR: STR 12',
    ]);

    $npcId = historyFetchNpcId($name);
    historyAssertTrue($npcId > 0, 'NPC id should resolve');
    $beforeCount = historyCountRows($npcId);

    updateNpcById($npcId, [
        'race' => 'Greenlander',
        'skills' => 'ATTR: STR 18',
    ]);

    $afterCount = historyCountRows($npcId);
    historyAssertTrue($afterCount === ($beforeCount + 1), 'UI update should add one history snapshot');

    $historyRow = $GLOBALS['db']->fetchOne(
        "SELECT snapshot_reason, race, skills
         FROM core_npc_master_history
         WHERE npc_id = $1
         ORDER BY history_id DESC
         LIMIT 1",
        [$npcId]
    );
    historyAssertTrue(is_array($historyRow), 'History row should exist');
    historyAssertTrue(strval($historyRow['snapshot_reason'] ?? '') === 'ui_update', 'History reason should be ui_update');
    historyAssertTrue(strval($historyRow['race'] ?? '') === 'Scorchlander', 'History snapshot should contain pre-update race');
    historyAssertTrue(strpos(strval($historyRow['skills'] ?? ''), 'STR 12') !== false, 'History snapshot should contain pre-update skills');
});

historyRunInRollbackTransaction('storeNpcSnapshot dedupes unchanged payload updates', function () use ($seed): void {
    $name = 'UT_HISTORY_' . $seed . '_SNAP';
    $storageId = 'hand_' . strval(random_int(1000000, 9999999));

    storeNpcProfile($name, [
        'race' => 'Greenlander',
        'faction' => 'Wandering Traders',
        'gender' => 'female',
        'metadata' => ['storage_id' => $storageId],
    ]);

    $npcId = historyFetchNpcId($name);
    historyAssertTrue($npcId > 0, 'NPC id should resolve for snapshot test');
    $beforeCount = historyCountRows($npcId);

    $payload = [
        'name' => $name,
        'storage_id' => $storageId,
        'race' => 'Greenlander',
        'faction' => 'Wandering Traders',
        'gender' => 'female',
        'equipment' => "Drifter's Leather Jacket",
        'stats' => ['strength' => 22, 'dexterity' => 17],
        'medical' => [
            'blood' => 85,
            'max_blood' => 95,
            'hunger' => 260,
            'hunger_max' => 300,
        ],
    ];

    historyAssertTrue(storeNpcSnapshot($payload, 100100), 'First snapshot write should succeed');
    historyAssertTrue(storeNpcSnapshot($payload, 100400), 'Second identical snapshot write should succeed');

    $afterCount = historyCountRows($npcId);
    historyAssertTrue($afterCount === ($beforeCount + 1), 'Identical snapshot writes should only create one history row');
});

historyRunInRollbackTransaction('relationship state survives generic writes and is anchored to history', function () use ($seed): void {
    $name = 'UT_HISTORY_' . $seed . '_REL';
    $storageId = 'rel_' . strval(random_int(1000000, 9999999));
    $initialRelationships = [
        'Beep' => ['aff' => 25, 'type' => 'friend'],
    ];

    storeNpcProfile($name, [
        'race' => 'Greenlander',
        'faction' => 'Player Faction',
        'gender' => 'female',
        'metadata' => ['storage_id' => $storageId],
        'extended_data' => [
            'relationships' => $initialRelationships,
            'environment' => ['town_name' => 'The Hub'],
        ],
    ]);

    $npcId = historyFetchNpcId($name);
    historyAssertTrue($npcId > 0, 'NPC id should resolve for relationship test');

    updateNpcById($npcId, [
        'extended_data' => ['environment' => ['town_name' => 'Squin']],
    ]);
    $afterGenericUpdate = $GLOBALS['db']->fetchOne('SELECT extended_data FROM core_npc WHERE id = $1', [$npcId]);
    $genericExtended = normalizeCoreNpcExtendedData($afterGenericUpdate['extended_data'] ?? '{}');
    historyAssertTrue(
        ($genericExtended['relationships'] ?? null) === $initialRelationships,
        'Generic NPC updates should preserve relationship affinities'
    );

    $updatedRelationships = [
        'Beep' => ['aff' => 40, 'type' => 'trusted'],
    ];
    $genericExtended['relationships'] = $updatedRelationships;
    stobeRunWithRelationshipExtendedDataWrite(
        static function () use ($npcId, $genericExtended): void {
            updateNpcById($npcId, ['extended_data' => $genericExtended]);
        }
    );
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', '123456', true);
    historyAssertTrue(stobeRelationshipTimelineStamp($npcId), 'Relationship timeline stamp should succeed');

    $timelineRow = $GLOBALS['db']->fetchOne(
        "SELECT snapshot_reason, gamets_last_updated, extended_data
         FROM core_npc_master_history
         WHERE npc_id = $1
         ORDER BY history_id DESC
         LIMIT 1",
        [$npcId]
    );
    $timelineExtended = normalizeCoreNpcExtendedData($timelineRow['extended_data'] ?? '{}');
    historyAssertTrue(intval($timelineRow['gamets_last_updated'] ?? 0) === 123456, 'Relationship history should use current game time');
    historyAssertTrue(
        ($timelineExtended['relationships'] ?? null) === $updatedRelationships,
        'Relationship history should contain the edited affinities'
    );

    historyAssertTrue(storeNpcSnapshot([
        'name' => $name,
        'storage_id' => $storageId,
        'race' => 'Greenlander',
        'faction' => 'Player Faction',
        'gender' => 'female',
        'environment' => ['town_name' => 'Stack'],
    ], 123900), 'Generic game snapshot should succeed');
    $afterGameSnapshot = $GLOBALS['db']->fetchOne('SELECT extended_data FROM core_npc WHERE id = $1', [$npcId]);
    $snapshotExtended = normalizeCoreNpcExtendedData($afterGameSnapshot['extended_data'] ?? '{}');
    historyAssertTrue(
        ($snapshotExtended['relationships'] ?? null) === $updatedRelationships,
        'Game snapshots should not replace relationship affinities'
    );
});

echo 'All npc history snapshot regression tests passed.' . PHP_EOL;
exit(0);

