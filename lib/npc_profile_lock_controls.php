<?php

declare(strict_types=1);

function stobeNpcProfileLockParseBool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    return in_array(strtolower(trim(strval($value))), ['1', 't', 'true', 'yes', 'on'], true);
}

function stobeSetAutoLockProfileSetting(mixed $value): bool
{
    $enabled = stobeNpcProfileLockParseBool($value);
    setSetting(
        'AUTO_LOCK_PROFILE',
        $enabled ? 'true' : 'false',
        'general',
        'When true, saving an NPC profile automatically locks it to prevent rollback/history overwrite updates.'
    );
    return $enabled;
}

function stobeBulkUnlockNpcProfiles(string $confirmation): int
{
    if (trim($confirmation) !== 'Unlock') {
        throw new InvalidArgumentException('Confirmation text mismatch');
    }

    $row = $GLOBALS['db']->fetchOne(
        "WITH updated AS (
            UPDATE core_npc
            SET lock_profile = FALSE
            WHERE COALESCE(lock_profile, FALSE) = TRUE
              AND trim(lower(name)) <> 'the narrator'
            RETURNING 1
        )
        SELECT COUNT(*)::int AS count FROM updated"
    );

    return intval($row['count'] ?? 0);
}
