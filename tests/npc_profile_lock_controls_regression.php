<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';
require_once __DIR__ . '/../lib/npc_profile_lock_controls.php';

function npcLockFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function npcLockAssert(bool $condition, string $message): void
{
    if (!$condition) {
        npcLockFail($message);
    }
}

npcLockAssert(stobeNpcProfileLockParseBool('on'), 'on should enable auto-lock');
npcLockAssert(!stobeNpcProfileLockParseBool('off'), 'off should disable auto-lock');

$db = $GLOBALS['db'];
$seed = strval(time()) . '_' . strval(random_int(1000, 9999));
$lockedName = 'UT_LOCKED_' . $seed;
$unlockedName = 'UT_UNLOCKED_' . $seed;

$db->exec('BEGIN');
try {
    npcLockAssert(stobeSetAutoLockProfileSetting('1'), 'auto-lock setting should enable');
    npcLockAssert(getSettingBool('AUTO_LOCK_PROFILE', false), 'enabled auto-lock should persist');
    npcLockAssert(!stobeSetAutoLockProfileSetting('0'), 'auto-lock setting should disable');
    npcLockAssert(!getSettingBool('AUTO_LOCK_PROFILE', true), 'disabled auto-lock should persist');

    $db->exec(
        "INSERT INTO core_npc (name, lock_profile) VALUES ($1, TRUE), ($2, FALSE)",
        [$lockedName, $unlockedName]
    );
    $db->exec(
        "INSERT INTO core_npc (name, lock_profile) VALUES ('The Narrator', TRUE)
         ON CONFLICT (name) DO UPDATE SET lock_profile = TRUE"
    );

    $before = $db->fetchOne(
        "SELECT COUNT(*)::int AS count
         FROM core_npc
         WHERE COALESCE(lock_profile, FALSE) = TRUE
           AND trim(lower(name)) <> 'the narrator'"
    );
    $unlocked = stobeBulkUnlockNpcProfiles('Unlock');
    npcLockAssert($unlocked === intval($before['count'] ?? -1), 'bulk unlock should report every eligible row');

    $lockedRow = $db->fetchOne('SELECT lock_profile FROM core_npc WHERE name = $1', [$lockedName]);
    $narratorRow = $db->fetchOne("SELECT lock_profile FROM core_npc WHERE trim(lower(name)) = 'the narrator' LIMIT 1");
    npcLockAssert(!stobeNpcProfileLockParseBool($lockedRow['lock_profile'] ?? true), 'eligible NPC should unlock');
    npcLockAssert(stobeNpcProfileLockParseBool($narratorRow['lock_profile'] ?? false), 'The Narrator should remain locked');

    try {
        stobeBulkUnlockNpcProfiles('unlock');
        npcLockFail('bulk unlock should require exact confirmation text');
    } catch (InvalidArgumentException $exception) {
        npcLockAssert($exception->getMessage() === 'Confirmation text mismatch', 'confirmation error should remain stable');
    }

    $db->exec('ROLLBACK');
} catch (Throwable $exception) {
    $db->exec('ROLLBACK');
    throw $exception;
}

echo 'PASS: NPC profile lock controls regression' . PHP_EOL;
