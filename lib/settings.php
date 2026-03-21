<?php

/**
 * Database-driven settings for StobeServer.
 * All configuration lives in the general_settings table.
 * No conf.php, no .ini files.
 */

function getSetting(string $id, string $default = ''): string {
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT value FROM general_settings WHERE id = $1",
        [$id]
    );
    return $row ? $row['value'] : $default;
}

function getConfOpt(string $id, string $default = ''): string {
    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT value FROM conf_opts WHERE id = $1",
        [$id]
    );
    return $row ? strval($row['value'] ?? '') : $default;
}

function setConfOpt(string $id, string $value, bool $onlyIfChanged = false): bool {
    $db = $GLOBALS["db"];
    $safeId = trim($id);
    if ($safeId === '') {
        return false;
    }

    if ($onlyIfChanged) {
        $existing = $db->fetchOne(
            "SELECT value FROM conf_opts WHERE id = $1 LIMIT 1",
            [$safeId]
        );
        if ($existing && strval($existing['value'] ?? '') === $value) {
            return false;
        }
    }

    $db->exec(
        "INSERT INTO conf_opts (id, value, updated_at)
         VALUES ($1, $2, NOW())
         ON CONFLICT (id) DO UPDATE
         SET value = EXCLUDED.value,
             updated_at = NOW()",
        [$safeId, $value]
    );
    return true;
}

function setSetting(string $id, string $value, string $category = 'general', string $description = ''): void {
    $db = $GLOBALS["db"];
    if ($description) {
        $db->exec(
            "INSERT INTO general_settings (id, value, description, updated_at)
             VALUES ($1, $2, $3, NOW())
             ON CONFLICT (id) DO UPDATE
             SET value = $2,
                 description = EXCLUDED.description,
                 updated_at = NOW()",
            [$id, $value, $description]
        );
    } else {
        $db->exec(
            "INSERT INTO general_settings (id, value, updated_at)
             VALUES ($1, $2, NOW())
             ON CONFLICT (id) DO UPDATE SET value = $2, updated_at = NOW()",
            [$id, $value]
        );
    }
}

function getSettingBool(string $id, bool $default = false): bool {
    $val = getSetting($id, $default ? 'true' : 'false');
    return in_array(strtolower($val), ['true', '1', 'yes'], true);
}

function getSettingInt(string $id, int $default = 0): int {
    return intval(getSetting($id, strval($default)));
}

function getSettingFloat(string $id, float $default = 0.0): float {
    return floatval(getSetting($id, strval($default)));
}

function normalizeDialogueMode(string $mode): string {
    $normalized = strtolower(trim($mode));
    if ($normalized === '') {
        return 'talk';
    }

    $allowed = ['talk', 'shout', 'whisper', 'autochat', 'cheat'];
    if (!in_array($normalized, $allowed, true)) {
        return 'talk';
    }

    return $normalized;
}

function getDialogueMode(): string {
    return 'talk';
}

function setDialogueMode(string $mode): string {
    $normalized = normalizeDialogueMode($mode);
    return $normalized;
}

function stobeStringIsTruthy(string $value): bool {
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function stobeIsRelationshipSystemEnabled(): bool {
    static $cached = null;
    if (is_bool($cached)) {
        return $cached;
    }

    $missingSentinel = '__STOBE_RELATIONSHIP_SYSTEM_MISSING__';

    $primary = getSetting('RELATIONSHIP_SYSTEM', $missingSentinel);
    if ($primary !== $missingSentinel) {
        $cached = stobeStringIsTruthy(strval($primary));
        return $cached;
    }

    $legacy = getSetting('RELATIONSHIP_SYSTEM_ENABLED', $missingSentinel);
    if ($legacy !== $missingSentinel) {
        $cached = stobeStringIsTruthy(strval($legacy));
        return $cached;
    }

    $cached = true;
    return $cached;
}

function stobeMarkQuickstartCompleted(bool $completed = true): void {
    setSetting(
        'STOBE_QUICKSTART_COMPLETED',
        $completed ? 'true' : 'false',
        'general',
        'When false, first dashboard visit redirects to the quickstart menu.'
    );
}

function stobeIsQuickstartCompleted(): bool {
    return stobeStringIsTruthy(getSetting('STOBE_QUICKSTART_COMPLETED', 'false'));
}

function stobeShouldRedirectToQuickstart(): bool {
    $rawSetting = getSetting('STOBE_QUICKSTART_COMPLETED', '');
    if ($rawSetting !== '') {
        return !stobeStringIsTruthy($rawSetting);
    }

    $db = $GLOBALS["db"] ?? null;
    if (!$db) {
        return true;
    }

    // Existing installs with live data should not be forced through quickstart.
    $hasEventLog = false;
    $hasNpcData = false;
    $hasApiKey = false;
    try {
        $row = $db->fetchOne("SELECT 1 AS v FROM eventlog LIMIT 1");
        $hasEventLog = is_array($row) && isset($row['v']);
    } catch (Throwable $exception) {
        $hasEventLog = false;
    }
    try {
        $row = $db->fetchOne("SELECT 1 AS v FROM core_npc LIMIT 1");
        $hasNpcData = is_array($row) && isset($row['v']);
    } catch (Throwable $exception) {
        $hasNpcData = false;
    }
    try {
        $row = $db->fetchOne(
            "SELECT 1 AS v
             FROM core_api_badge
             WHERE BTRIM(COALESCE(api_key, '')) <> ''
             LIMIT 1"
        );
        $hasApiKey = is_array($row) && isset($row['v']);
    } catch (Throwable $exception) {
        $hasApiKey = false;
    }

    $isExistingInstall = ($hasEventLog || $hasNpcData || $hasApiKey);
    stobeMarkQuickstartCompleted($isExistingInstall);
    return !$isExistingInstall;
}
