<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';
require_once __DIR__ . '/../lib/playthrough_storage.php';
require_once __DIR__ . '/../lib/playthrough_snapshot.php';
require_once __DIR__ . '/../lib/playthrough_rollback.php';
require_once __DIR__ . '/../lib/player_base_functions.php';

$db = $GLOBALS['db'];

function ptFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function ptAssert(bool $condition, string $message): void
{
    if (!$condition) {
        ptFail($message);
    }
}

function ptConfOptGetRaw(string $id): array
{
    global $db;
    $row = $db->fetchOne('SELECT value FROM conf_opts WHERE id = $1 LIMIT 1', [$id]);
    if (!$row) {
        return ['exists' => false, 'value' => ''];
    }
    return ['exists' => true, 'value' => strval($row['value'] ?? '')];
}

function ptConfOptRestore(string $id, array $snapshot): void
{
    global $db;
    if (!($snapshot['exists'] ?? false)) {
        $db->exec('DELETE FROM conf_opts WHERE id = $1', [$id]);
        return;
    }
    $db->exec(
        'INSERT INTO conf_opts (id, value, updated_at)
         VALUES ($1, $2, NOW())
         ON CONFLICT (id) DO UPDATE SET value = EXCLUDED.value, updated_at = NOW()',
        [$id, strval($snapshot['value'] ?? '')]
    );
}

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));
$prefix = 'UT_ROLLBACK_' . $seed;

$trackedConfKeys = [
    'PLAYTHROUGH_LAST_SEEN_GAMETS',
    'PLAYTHROUGH_LAST_SEEN_TS',
    'PLAYTHROUGH_LAST_ROLLBACK_GAMETS',
    'PLAYTHROUGH_LAST_ROLLBACK_TS',
    'PLAYTHROUGH_LAST_ROLLBACK_FROM_GAMETS',
    'PLAYTHROUGH_LAST_ROLLBACK_DELTA_GAMETS',
    'DYNAMIC_PROFILE_LOAD_GRACE_UNTIL_TS',
];

$confBackup = [];
foreach ($trackedConfKeys as $key) {
    $confBackup[$key] = ptConfOptGetRaw($key);
}

$dragonBreakEnabledBackup = $GLOBALS['DRAGON_BREAK_AUTOSNAPSHOT'] ?? null;
$dragonBreakMinDaysBackup = $GLOBALS['DRAGON_BREAK_MIN_DAYS'] ?? null;

$createdSnapshotIds = [];
$zoneKeepName = 'UT_ROLLBACK_ZONE_KEEP_' . $seed;
$zoneDropName = 'UT_ROLLBACK_ZONE_DROP_' . $seed;
$baseKeepId = 'ut-rollback-base-keep-' . $seed;
$baseDropId = 'ut-rollback-base-drop-' . $seed;
$baseLegacyId = 'ut-rollback-base-legacy-' . $seed;
$presenceBackup = $db->fetchOne(
    "SELECT scope_key, session_id, observer_serial, observer_name, inside, base_id, game_ts, observed_at
     FROM player_base_presence
     WHERE scope_key = 'selected_player'
     LIMIT 1"
);

try {
    // Location zone pruning behavior on fallback rollback:
    // - zones first discovered after cutoff should be removed
    // - previously discovered zones should be kept and rewound to cutoff
    $db->exec('DELETE FROM location_zones WHERE zone_name IN ($1, $2)', [$zoneKeepName, $zoneDropName]);
    $db->exec(
        'INSERT INTO location_zones (zone_name, city_name, first_game_ts, last_game_ts, first_seen_ts, last_seen_ts, metadata, updated_at)
         VALUES ($1, $2, $3, $4, $5, $5, $6::jsonb, NOW())',
        [$zoneKeepName, 'UT City', 100, 400, time(), '{}']
    );
    $db->exec(
        'INSERT INTO location_zones (zone_name, city_name, first_game_ts, last_game_ts, first_seen_ts, last_seen_ts, metadata, updated_at)
         VALUES ($1, $2, $3, $4, $5, $5, $6::jsonb, NOW())',
        [$zoneDropName, 'UT City', 320, 350, time(), '{}']
    );

    $basePayload = [
        'session_id' => 'ut-rollback-session-' . $seed,
        'observer_serial' => 42,
        'observer_name' => 'Rollback Tester',
        'game_ts' => 100,
        'player_base' => [
            'inside' => true,
            'base_id' => $baseKeepId,
            'name' => 'Rollback Keep Base',
            'members_inside' => 2,
            'has_gates' => true,
            'gates_closed' => true,
            'details' => [
                'available' => true,
                'construction' => [
                    'total' => 1,
                    'average_progress' => 20,
                    'groups' => [
                        ['name' => 'Wall', 'count' => 1, 'average_progress' => 20],
                    ],
                ],
                'supplies' => ['food' => 10],
            ],
        ],
    ];
    stobeStorePlayerBaseState($basePayload);

    $basePayload['game_ts'] = 500;
    $basePayload['player_base']['members_inside'] = 5;
    $basePayload['player_base']['details']['construction']['total'] = 3;
    $basePayload['player_base']['details']['construction']['average_progress'] = 80;
    $basePayload['player_base']['details']['construction']['groups'] = [
        ['name' => 'Wall', 'count' => 3, 'average_progress' => 80],
    ];
    $basePayload['player_base']['details']['supplies']['food'] = 50;
    stobeStorePlayerBaseState($basePayload);

    $basePayload['game_ts'] = 350;
    $basePayload['player_base']['base_id'] = $baseDropId;
    $basePayload['player_base']['name'] = 'Rollback Future Base';
    stobeStorePlayerBaseState($basePayload);

    $basePayload['game_ts'] = 500;
    $basePayload['player_base']['base_id'] = $baseLegacyId;
    $basePayload['player_base']['name'] = 'Rollback Legacy Base';
    stobeStorePlayerBaseState($basePayload);
    $db->exec('UPDATE player_bases SET first_game_ts = 0 WHERE base_id = $1', [$baseLegacyId]);
    $db->exec('UPDATE player_base_history SET game_ts = 0 WHERE base_id = $1', [$baseLegacyId]);

    $zonePrune = stobePlaythroughPruneFutureTimeline(250);
    ptAssert(intval($zonePrune['location_zones_deleted'] ?? 0) >= 1, 'Rollback prune should delete post-cutoff discovered zones');
    ptAssert(intval($zonePrune['location_zones_rewound'] ?? 0) >= 1, 'Rollback prune should rewind forward location game timestamps');
    ptAssert(intval($zonePrune['player_base_history_deleted'] ?? 0) >= 2, 'Rollback prune should delete future base snapshots');
    ptAssert(intval($zonePrune['player_bases_deleted'] ?? 0) >= 1, 'Rollback prune should delete bases first discovered after cutoff');
    ptAssert(intval($zonePrune['player_bases_restored'] ?? 0) >= 1, 'Rollback prune should restore known bases to their latest pre-cutoff state');
    ptAssert(intval($zonePrune['player_base_presence_cleared'] ?? 0) >= 1, 'Rollback prune should clear stale current-base presence');

    $keepRow = $db->fetchOne('SELECT first_game_ts, last_game_ts FROM location_zones WHERE zone_name = $1 LIMIT 1', [$zoneKeepName]);
    $dropRow = $db->fetchOne('SELECT zone_name FROM location_zones WHERE zone_name = $1 LIMIT 1', [$zoneDropName]);
    ptAssert(is_array($keepRow), 'Rollback prune should keep zones discovered before cutoff');
    ptAssert(intval($keepRow['first_game_ts'] ?? 0) === 100, 'Kept zone should preserve first discovery game timestamp');
    ptAssert(intval($keepRow['last_game_ts'] ?? 0) === 250, 'Kept zone should rewind last_game_ts to cutoff');
    ptAssert(!is_array($dropRow), 'Rollback prune should remove zones discovered only after cutoff');

    $keptBase = $db->fetchOne(
        'SELECT members_inside, details, game_ts, first_game_ts, last_game_ts
         FROM player_bases
         WHERE base_id = $1
         LIMIT 1',
        [$baseKeepId]
    );
    $droppedBase = $db->fetchOne('SELECT base_id FROM player_bases WHERE base_id = $1 LIMIT 1', [$baseDropId]);
    $legacyBase = $db->fetchOne(
        'SELECT first_game_ts, game_ts, last_game_ts
         FROM player_bases
         WHERE base_id = $1
         LIMIT 1',
        [$baseLegacyId]
    );
    $presence = $db->fetchOne(
        "SELECT inside, base_id, game_ts
         FROM player_base_presence
         WHERE scope_key = 'selected_player'
         LIMIT 1"
    );
    ptAssert(is_array($keptBase), 'Rollback prune should retain bases discovered before cutoff');
    $restoredDetails = stobeNormalizePlayerBaseDetails($keptBase['details'] ?? []);
    ptAssert(intval($keptBase['members_inside'] ?? 0) === 2, 'Retained base should restore pre-cutoff members');
    ptAssert(intval($keptBase['game_ts'] ?? 0) === 100, 'Retained base should restore its pre-cutoff game timestamp');
    ptAssert(intval($keptBase['first_game_ts'] ?? 0) === 100, 'Retained base should preserve first discovery timestamp');
    ptAssert(intval($keptBase['last_game_ts'] ?? 0) === 100, 'Retained base should rewind last game timestamp');
    ptAssert(intval($restoredDetails['supplies']['food'] ?? 0) === 10, 'Retained base should restore pre-cutoff supplies');
    ptAssert(intval($restoredDetails['construction']['total'] ?? 0) === 1, 'Retained base should restore pre-cutoff construction');
    ptAssert(!is_array($droppedBase), 'Rollback prune should remove future-only bases');
    ptAssert(is_array($legacyBase), 'Rollback prune should retain bases migrated with an unknown first discovery time');
    ptAssert(intval($legacyBase['first_game_ts'] ?? -1) === 0, 'Migrated base should preserve its legacy discovery sentinel');
    ptAssert(intval($legacyBase['game_ts'] ?? -1) === 0, 'Migrated base should restore its seeded baseline snapshot');
    ptAssert(intval($legacyBase['last_game_ts'] ?? -1) === 0, 'Migrated base should rewind its last game timestamp to baseline');
    ptAssert(is_array($presence), 'Rollback prune should retain the selected-player presence row');
    ptAssert(!coerceBoolean($presence['inside'] ?? true), 'Rollback prune should mark current presence as outside');
    ptAssert(($presence['base_id'] ?? null) === null, 'Rollback prune should detach current presence from the future base');
    ptAssert(intval($presence['game_ts'] ?? 0) === 250, 'Rollback prune should rewind presence to cutoff');

    // Pure threshold helper checks.
    ptAssert(stobeDragonBreakDaysRollback(200000, 200000) === 0, 'Equal gamets should be zero rollback days');
    ptAssert(stobeDragonBreakDaysRollback(200000, 199999) === 0, 'Sub-day rollback should be zero days');
    ptAssert(stobeDragonBreakDaysRollback(200000, 113600) === 1, '86400 rollback should map to 1 day');

    // Force deterministic dragonbreak config.
    $GLOBALS['DRAGON_BREAK_AUTOSNAPSHOT'] = true;
    $GLOBALS['DRAGON_BREAK_MIN_DAYS'] = 1;

    $futureBase = 900000000 + random_int(10000, 99999);

    // Case 1: rollback detected but below 1-day threshold => no dragonbreak snapshot.
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($futureBase + 1000));
    setConfOpt('PLAYTHROUGH_LAST_SEEN_TS', strval(time()));
    $resultSmall = stobeHandlePotentialGametsRollback($futureBase + 500, 'test_small_rewind');
    ptAssert(boolval($resultSmall['triggered'] ?? false), 'Small rewind should still trigger rollback handling');
    ptAssert(intval($resultSmall['snapshot_id'] ?? 0) === 0, 'Small rewind should not create dragonbreak snapshot');

    // Case 2: rollback >= 1 day => snapshot expected.
    setConfOpt('PLAYTHROUGH_LAST_SEEN_GAMETS', strval($futureBase + 200000));
    setConfOpt('PLAYTHROUGH_LAST_SEEN_TS', strval(time()));
    $resultLarge = stobeHandlePotentialGametsRollback($futureBase + 100000, 'test_large_rewind');
    ptAssert(boolval($resultLarge['triggered'] ?? false), 'Large rewind should trigger rollback handling');
    $snapshotId = intval($resultLarge['snapshot_id'] ?? 0);
    ptAssert($snapshotId > 0, 'Large rewind should create dragonbreak snapshot');

    $createdSnapshotIds[] = $snapshotId;
    $profile = stobePlaythroughGetProfileById($snapshotId);
    ptAssert(is_array($profile), 'Dragonbreak snapshot profile should exist');
    ptAssert(intval($profile['rollback_delta_days'] ?? 0) >= 1, 'Snapshot should record rollback_delta_days >= 1');

    echo 'All playthrough rollback regression tests passed.' . PHP_EOL;
} finally {
    // Cleanup created snapshots.
    foreach ($createdSnapshotIds as $snapshotId) {
        if ($snapshotId > 0) {
            stobePlaythroughDeleteProfile($snapshotId);
        }
    }

    // Cleanup any residual test snapshots by name prefix (safety net).
    $rows = stobePlaythroughListProfiles(2000);
    foreach ($rows as $row) {
        $name = strval($row['name'] ?? '');
        if (str_starts_with($name, $prefix)) {
            stobePlaythroughDeleteProfile(intval($row['id'] ?? 0));
        }
    }

    // Restore conf opts.
    foreach ($confBackup as $key => $snapshot) {
        ptConfOptRestore($key, $snapshot);
    }

    // Restore globals.
    if ($dragonBreakEnabledBackup === null) {
        unset($GLOBALS['DRAGON_BREAK_AUTOSNAPSHOT']);
    } else {
        $GLOBALS['DRAGON_BREAK_AUTOSNAPSHOT'] = $dragonBreakEnabledBackup;
    }
    if ($dragonBreakMinDaysBackup === null) {
        unset($GLOBALS['DRAGON_BREAK_MIN_DAYS']);
    } else {
        $GLOBALS['DRAGON_BREAK_MIN_DAYS'] = $dragonBreakMinDaysBackup;
    }

    // Cleanup location zone test rows.
    $db->exec('DELETE FROM location_zones WHERE zone_name IN ($1, $2)', [$zoneKeepName, $zoneDropName]);

    $db->exec(
        "DELETE FROM player_base_presence
         WHERE scope_key = 'selected_player'
           AND session_id = $1",
        ['ut-rollback-session-' . $seed]
    );
    $db->exec(
        'DELETE FROM player_bases WHERE base_id IN ($1, $2, $3)',
        [$baseKeepId, $baseDropId, $baseLegacyId]
    );
    if (is_array($presenceBackup)) {
        $db->exec(
            "INSERT INTO player_base_presence (
                scope_key, session_id, observer_serial, observer_name,
                inside, base_id, game_ts, observed_at
             ) VALUES (
                $1, $2, $3, $4, $5::boolean, $6, $7, $8::timestamp
             )
             ON CONFLICT (scope_key) DO UPDATE SET
                session_id = EXCLUDED.session_id,
                observer_serial = EXCLUDED.observer_serial,
                observer_name = EXCLUDED.observer_name,
                inside = EXCLUDED.inside,
                base_id = EXCLUDED.base_id,
                game_ts = EXCLUDED.game_ts,
                observed_at = EXCLUDED.observed_at",
            [
                strval($presenceBackup['scope_key'] ?? 'selected_player'),
                strval($presenceBackup['session_id'] ?? ''),
                intval($presenceBackup['observer_serial'] ?? 0),
                strval($presenceBackup['observer_name'] ?? ''),
                coerceBoolean($presenceBackup['inside'] ?? false) ? 'true' : 'false',
                $presenceBackup['base_id'] ?? null,
                intval($presenceBackup['game_ts'] ?? 0),
                strval($presenceBackup['observed_at'] ?? date('Y-m-d H:i:s')),
            ]
        );
    }
}

exit(0);

