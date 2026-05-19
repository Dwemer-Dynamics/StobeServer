<?php

/**
 * Dynamic profile generation runtime for StobeServer.
 *
 * Behavior:
 * - Periodically refreshes enabled NPC profile fields via LLM.
 * - Interval is controlled by conf_opts key DYNAMIC_PROFILE_INTERVAL_HOURS.
 * - Interval uses Kenshi in-game gamets, not wall-clock time.
 * - Per-NPC real-time cooldown prevents bursty refresh loops.
 * - Respects NPC/profile layered setting DYNAMIC_PROFILE_ENABLED.
 */

function stobeDynamicProfileNormalizeKeyToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return $normalized;
}

function stobeDynamicProfileLastGametsKey(string $npcName): string
{
    return 'DYNAMIC_PROFILE_LAST_GAMETS_' . stobeDynamicProfileNormalizeKeyToken($npcName);
}

function stobeDynamicProfileLastRunTsKey(string $npcName): string
{
    return 'DYNAMIC_PROFILE_LAST_RUN_TS_' . stobeDynamicProfileNormalizeKeyToken($npcName);
}

function stobeDynamicProfileAllowedEventType(string $eventType): bool
{
    $type = strtolower(trim($eventType));
    if ($type === '') {
        return false;
    }
    $allowed = ['chat', 'rechat', 'bored', 'inputtext', 'inputtext_s'];
    return in_array($type, $allowed, true);
}

function stobeDynamicProfileIntervalHours(): int
{
    $raw = trim(strval(getConfOpt('DYNAMIC_PROFILE_INTERVAL_HOURS', '')));
    if ($raw === '') {
        $raw = trim(strval(getSetting('DYNAMIC_PROFILE_INTERVAL_HOURS', '24')));
    }

    // Backward compatibility: older plugin/runtime sent minutes.
    if ($raw === '') {
        $legacyRaw = trim(strval(getConfOpt('DYNAMIC_PROFILE_INTERVAL_MINUTES', '')));
        if ($legacyRaw === '') {
            $legacyRaw = trim(strval(getSetting('DYNAMIC_PROFILE_INTERVAL_MINUTES', '1440')));
        }
        $legacyMinutes = parseIntLike($legacyRaw, 1440);
        if ($legacyMinutes < 1) {
            $legacyMinutes = 60;
        }
        $hours = intval(ceil($legacyMinutes / 60));
    } else {
        $hours = parseIntLike($raw, 24);
    }

    if ($hours < 1) {
        $hours = 1;
    } elseif ($hours > 720) {
        $hours = 720;
    }
    return $hours;
}

function stobeDynamicProfileLoadGraceSeconds(): int
{
    $seconds = parseIntLike(getSetting('DYNAMIC_PROFILE_LOAD_GRACE_SECONDS', '60'), 60);
    if ($seconds < 5) {
        $seconds = 5;
    } elseif ($seconds > 300) {
        $seconds = 300;
    }
    return $seconds;
}

function stobeDynamicProfileRealtimeCooldownSeconds(): int
{
    $seconds = parseIntLike(
        getSetting('DYNAMIC_PROFILE_REALTIME_COOLDOWN_SECONDS', '900'),
        900
    );
    if ($seconds < 60) {
        $seconds = 60;
    } elseif ($seconds > 86400) {
        $seconds = 86400;
    }
    return $seconds;
}

function stobeDynamicProfileMarkLoadGrace(int $nowTs, int $seconds, string $reason): void
{
    if ($nowTs <= 0) {
        $nowTs = time();
    }
    if ($seconds < 1) {
        $seconds = 1;
    }
    $untilTs = $nowTs + $seconds;
    setConfOpt('DYNAMIC_PROFILE_LOAD_GRACE_UNTIL_TS', strval($untilTs), true);
    stobeLogInfo('Dynamic profile cooldown armed', [
        'reason' => $reason,
        'grace_seconds' => $seconds,
        'until_ts' => $untilTs,
    ]);
}

function stobeDynamicProfileHandleGlobalGametsRewind(int $gamets): bool
{
    if ($gamets <= 0) {
        return false;
    }
    $lastSeenGamets = intval(getConfOpt('DYNAMIC_PROFILE_LAST_SEEN_GAMETS', '0'));
    $nowTs = time();
    setConfOpt('DYNAMIC_PROFILE_LAST_SEEN_GAMETS', strval($gamets), true);
    setConfOpt('DYNAMIC_PROFILE_LAST_SEEN_TS', strval($nowTs), true);

    if ($lastSeenGamets > 0 && $gamets + 5 < $lastSeenGamets) {
        $graceSeconds = stobeDynamicProfileLoadGraceSeconds();
        stobeDynamicProfileMarkLoadGrace($nowTs, $graceSeconds, 'gamets_rewind_global');
        return true;
    }
    return false;
}

function stobeDynamicProfileInLoadGraceWindow(int $nowTs): bool
{
    if ($nowTs <= 0) {
        $nowTs = time();
    }
    $graceUntilTs = intval(getConfOpt('DYNAMIC_PROFILE_LOAD_GRACE_UNTIL_TS', '0'));
    return $graceUntilTs > 0 && $nowTs < $graceUntilTs;
}

function stobeDynamicProfileShouldRunCycle(string $eventType, int $gamets): bool
{
    if (!stobeDynamicProfileAllowedEventType($eventType)) {
        return false;
    }
    if ($gamets <= 0) {
        return false;
    }

    $nowTs = time();
    if (stobeDynamicProfileHandleGlobalGametsRewind($gamets)) {
        return false;
    }
    if (stobeDynamicProfileInLoadGraceWindow($nowTs)) {
        return false;
    }

    $lastRunTs = intval(getConfOpt('DYNAMIC_PROFILE_LAST_RUN_TS', '0'));
    if ($lastRunTs > 0 && ($nowTs - $lastRunTs) < 15) {
        return false;
    }

    return true;
}

function stobeDynamicProfileTryLock(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $row = $db->fetchOne("SELECT pg_try_advisory_lock(937462) AS locked");
    if (!is_array($row)) {
        return false;
    }
    $raw = $row['locked'] ?? false;
    if (is_bool($raw)) {
        return $raw;
    }
    if (is_numeric($raw)) {
        return intval($raw) === 1;
    }
    $normalized = strtolower(trim(strval($raw)));
    return in_array($normalized, ['t', 'true', '1', 'yes', 'on'], true);
}

function stobeDynamicProfileUnlock(): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return;
    }
    $db->exec("SELECT pg_advisory_unlock(937462)");
}

function stobeDynamicProfileFetchCandidates(int $limit = 64): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 256) {
        $limit = 256;
    }

    return $db->fetchAll(
        "SELECT id, name, profile_id, metadata, extended_data, gamets_last_updated
         FROM core_npc
         WHERE COALESCE(TRIM(name), '') <> ''
           AND LOWER(name) <> 'the narrator'
         ORDER BY COALESCE(gamets_last_updated, 0) DESC, updated_at DESC
         LIMIT " . intval($limit)
    );
}

function stobeDynamicProfileProcessNarrator(
    int $intervalHours,
    string $eventType,
    int $gamets
): bool {
    if (!function_exists('stobeGetNarrator')) {
        return false;
    }

    $narrator = stobeGetNarrator();
    if (!$narrator) {
        return false;
    }
    if (!$narrator->getBool('dynamic_profile', false)) {
        return false;
    }

    $narratorFields = $narrator->getDynamicProfileFields();
    if (count($narratorFields) === 0) {
        return false;
    }

    $narratorName = function_exists('stobeNarratorName') ? stobeNarratorName() : 'The Narrator';
    if (!stobeDynamicProfileNpcDue($narratorName, $gamets, $intervalHours)) {
        return false;
    }

    $narratorData = function_exists('stobeBuildNarratorNpcData')
        ? stobeBuildNarratorNpcData()
        : [];
    if (!is_array($narratorData)) {
        $narratorData = [];
    }

    $metadata = normalizeCoreNpcMetadata($narratorData['metadata'] ?? []);
    $metadata['DYNAMIC_PROFILE_ENABLED'] = true;
    $metadata['DYNAMIC_PROFILE_FIELDS'] = array_values($narratorFields);
    $narratorData['metadata'] = $metadata;
    $narratorData['dynamic_profile'] = 1;
    $narratorData['profile_id'] = max(1, intval($narratorData['profile_id'] ?? 1));

    $rows = stobeDynamicProfileFetchRecentContext($narratorName, 30);
    $contextText = stobeDynamicProfileBuildContextText($rows);
    if ($contextText === '(none)') {
        return false;
    }

    $gen = stobeDynamicProfileGenerateUpdates($narratorName, $narratorData, $contextText);
    if (!boolval($gen['ok'] ?? false)) {
        stobeLogWarn('Dynamic profile generation skipped', [
            'npc_name' => $narratorName,
            'reason' => strval($gen['reason'] ?? 'unknown'),
        ]);
        return false;
    }

    $updates = is_array($gen['updates'] ?? null) ? $gen['updates'] : [];
    if (count($updates) === 0) {
        return false;
    }

    $payload = [];
    foreach ($updates as $field => $value) {
        if (!is_string($value)) {
            continue;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            continue;
        }
        if ($field === 'personality' || $field === 'speechstyle' || $field === 'goals') {
            $payload[$field] = $trimmed;
        } elseif ($field === 'backstory') {
            $payload['background'] = $trimmed;
        }
    }
    if (count($payload) === 0) {
        return false;
    }

    $payload['gamets_last_updated'] = strval($gamets > 0 ? $gamets : time());
    $narrator->setMultiple($payload);

    if ($gamets > 0) {
        setConfOpt(stobeDynamicProfileLastGametsKey($narratorName), strval($gamets), true);
    }
    setConfOpt(stobeDynamicProfileLastRunTsKey($narratorName), strval(time()), true);

    stobeLogInfo('Dynamic profile updated', [
        'npc_name' => $narratorName,
        'npc_id' => 'core_narrator',
        'fields_updated' => array_keys($payload),
        'allowed_fields' => $gen['allowed_fields'] ?? [],
        'interval_hours' => $intervalHours,
        'event_type' => $eventType,
        'gamets' => $gamets,
    ]);

    return true;
}

function stobeDynamicProfileNpcEnabled(array $npcData): bool
{
    return getNpcProfileBoolSetting(
        $npcData,
        ['dynamic_profile_enabled', 'DYNAMIC_PROFILE_ENABLED'],
        'DYNAMIC_PROFILE_ENABLED',
        true
    );
}

function stobeDynamicProfileNpcDue(string $npcName, int $currentGamets, int $intervalHours): bool
{
    if ($currentGamets <= 0) {
        return false;
    }

    $gametsKey = stobeDynamicProfileLastGametsKey($npcName);
    $lastRunTsKey = stobeDynamicProfileLastRunTsKey($npcName);
    $lastRunGamets = intval(getConfOpt($gametsKey, '0'));
    $lastRunTs = intval(getConfOpt($lastRunTsKey, '0'));
    $nowTs = time();

    // Seed missing per-NPC state to current time/gamets so newly discovered
    // faction NPCs do not backfill into an immediate LLM burst.
    if ($lastRunGamets <= 0) {
        $hadLegacyTs = $lastRunTs > 0;
        setConfOpt($gametsKey, strval($currentGamets), true);
        setConfOpt($lastRunTsKey, strval($nowTs), true);
        stobeLogDebug(
            $hadLegacyTs
                ? 'Dynamic profile NPC timer migrated from wall-clock to seeded gamets state'
                : 'Dynamic profile NPC timer seeded on first sight',
            [
                'npc_name' => $npcName,
                'legacy_last_run_ts' => $lastRunTs,
                'current_gamets' => $currentGamets,
                'interval_hours' => $intervalHours,
            ]
        );
        return false;
    }

    if ($currentGamets + 5 < $lastRunGamets) {
        setConfOpt($gametsKey, strval($currentGamets), true);
        setConfOpt($lastRunTsKey, strval($nowTs), true);
        stobeLogDebug('Dynamic profile NPC timer rebased after gamets rewind', [
            'npc_name' => $npcName,
            'last_run_gamets' => $lastRunGamets,
            'current_gamets' => $currentGamets,
            'interval_hours' => $intervalHours,
        ]);
        return false;
    }

    $realtimeCooldownSeconds = stobeDynamicProfileRealtimeCooldownSeconds();
    if ($lastRunTs > 0 && ($nowTs - $lastRunTs) < $realtimeCooldownSeconds) {
        return false;
    }

    $intervalGamets = max(3600, $intervalHours * 3600);
    return ($currentGamets - $lastRunGamets) >= $intervalGamets;
}

function stobeDynamicProfileDecodeJsonObject(string $raw): array
{
    $text = trim($raw);
    if ($text === '') {
        return [];
    }

    if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $fenced) === 1) {
        $text = trim($fenced[1]);
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $text, $match) === 1) {
        $decoded = json_decode($match[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return [];
}

function stobeDynamicProfileIsMeaningful(string $value): bool
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return false;
    }
    $collapsed = strtolower(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
    $blocked = ['unknown', 'none', 'n/a', 'na', 'null', '(none)', 'not specified', 'no data', '{}', '[]'];
    if (in_array($collapsed, $blocked, true)) {
        return false;
    }
    return !str_starts_with($collapsed, 'no notable ');
}

function stobeDynamicProfileResolveAllowedFields(array $npcData): array
{
    $raw = null;
    $source = '';
    $fields = [];
    if (stobeReadLayeredSettingRaw(
        $npcData,
        ['dynamic_profile_fields', 'DYNAMIC_PROFILE_FIELDS'],
        'DYNAMIC_PROFILE_FIELDS',
        $raw,
        $source
    )) {
        if (is_array($raw)) {
            foreach ($raw as $entry) {
                if (!is_scalar($entry)) {
                    continue;
                }
                $fields[] = strtolower(trim(strval($entry)));
            }
        } elseif (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!is_scalar($entry)) {
                        continue;
                    }
                    $fields[] = strtolower(trim(strval($entry)));
                }
            } else {
                foreach (explode(',', $raw) as $entry) {
                    $fields[] = strtolower(trim($entry));
                }
            }
        }
    }

    $canonical = [];
    foreach ($fields as $field) {
        if ($field === 'npc_static_bio' || $field === 'bio') {
            $field = 'backstory';
        }
        if (!in_array($field, ['backstory', 'personality', 'occupation', 'speechstyle', 'goals'], true)) {
            continue;
        }
        if (!in_array($field, $canonical, true)) {
            $canonical[] = $field;
        }
    }

    if (count($canonical) === 0) {
        return ['backstory', 'personality', 'occupation', 'speechstyle', 'goals'];
    }
    return $canonical;
}

function stobeDynamicProfileNormalizeGeneratedFields(array $parsed, array $allowedFields): array
{
    $aliases = [
        'backstory' => ['backstory', 'npc_static_bio', 'bio', 'npc_bio'],
        'personality' => ['personality'],
        'occupation' => ['occupation'],
        'speechstyle' => ['speechstyle', 'speech_style'],
        'goals' => ['goals'],
    ];

    $updates = [];
    foreach ($aliases as $targetField => $keys) {
        if (!in_array($targetField, $allowedFields, true)) {
            continue;
        }
        $value = '';
        foreach ($keys as $key) {
            if (!array_key_exists($key, $parsed)) {
                continue;
            }
            $candidate = is_string($parsed[$key]) ? $parsed[$key] : json_encode($parsed[$key], JSON_UNESCAPED_UNICODE);
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim(preg_replace('/\s+/u', ' ', sanitizeForKenshi($candidate)) ?? $candidate);
            if (!stobeDynamicProfileIsMeaningful($candidate)) {
                continue;
            }
            $value = $candidate;
            break;
        }
        if ($value === '') {
            continue;
        }
        if (strlen($value) > 6000) {
            $value = substr($value, 0, 6000);
        }
        $updates[$targetField] = $value;
    }
    return $updates;
}

function stobeDynamicProfileFetchRecentContext(string $npcName, int $limit = 30): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return [];
    }
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return [];
    }
    if ($limit < 10) {
        $limit = 10;
    } elseif ($limit > 80) {
        $limit = 80;
    }

    $deliveryVisibilitySql = function_exists('stobeBuildEventlogDeliveryVisibilitySql')
        ? stobeBuildEventlogDeliveryVisibilitySql('eventlog')
        : '1=1';

    return $db->fetchAll(
        "SELECT rowid AS id, type, data, gamets, localts, ts, people, location
         FROM eventlog
         WHERE type NOT IN (
             'setconf',
             'status_msg',
             'npc_snapshot',
             'playerinfo',
             'infonpc',
             'infoloc'
         )
           AND {$deliveryVisibilitySql}
           AND (
                LOWER(COALESCE(people, '')) LIKE LOWER($1)
                OR LOWER(COALESCE(data, '')) LIKE LOWER($1)
           )
         ORDER BY rowid DESC
         LIMIT " . intval($limit),
        ['%' . $safeNpcName . '%']
    );
}

function stobeDynamicProfileBuildContextText(array $rows): string
{
    if (count($rows) === 0) {
        return '(none)';
    }
    $lines = [];
    foreach (array_reverse($rows) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = stobeFormatEventHistoryLine($row, true);
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\s+/u', ' ', trim($line)) ?? $line;
        if (strlen($line) > 420) {
            $line = substr($line, 0, 420) . '...';
        }
        $lines[] = $line;
        if (count($lines) >= 18) {
            break;
        }
    }
    return count($lines) > 0 ? implode("\n", $lines) : '(none)';
}

function stobeDynamicProfileGenerateUpdates(string $npcName, array $npcData, string $recentContext): array
{
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return ['ok' => false, 'reason' => 'missing_npc_name'];
    }

    $allowedFields = stobeDynamicProfileResolveAllowedFields($npcData);
    if (count($allowedFields) === 0) {
        return ['ok' => false, 'reason' => 'no_allowed_fields'];
    }

    $currentState = [
        'backstory' => trim(strval($npcData['backstory'] ?? '')),
        'personality' => trim(strval($npcData['personality'] ?? '')),
        'occupation' => trim(strval($npcData['occupation'] ?? '')),
        'speechstyle' => trim(strval($npcData['speechstyle'] ?? '')),
        'goals' => trim(strval($npcData['goals'] ?? '')),
    ];

    $fieldList = implode(', ', $allowedFields);
    $defaultSystemPrompt = implode("\n", [
        'You generate Kenshi NPC profile fields for dynamic profile refresh.',
        'Return STRICT JSON only (no markdown, no prose).',
        'Allowed keys: {"backstory":"","personality":"","occupation":"","speechstyle":"","goals":""}',
        'Only meaningfully change fields when context supports it.',
        'Stay grounded and in-world. Avoid placeholders like unknown/none.',
    ]);
    $systemPromptTemplate = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('dynamic_profile_generator', $defaultSystemPrompt)
        : $defaultSystemPrompt;
    if (strpos($systemPromptTemplate, '#ALLOWED_FIELDS#') !== false) {
        $systemPrompt = str_replace('#ALLOWED_FIELDS#', $fieldList, $systemPromptTemplate);
    } else {
        $systemPrompt = rtrim($systemPromptTemplate) . "\nFields currently editable for this NPC: " . $fieldList;
    }

    $userSections = [];
    $userSections[] = '<npc_name>' . stobePromptXmlEscape($safeNpcName) . '</npc_name>';
    $userSections[] = '<race>' . stobePromptXmlEscape(trim(strval($npcData['race'] ?? 'Unknown'))) . '</race>';
    $userSections[] = '<faction>' . stobePromptXmlEscape(trim(strval($npcData['faction'] ?? 'Unknown'))) . '</faction>';
    $userSections[] = '<current_profile_fields>';
    foreach ($currentState as $key => $value) {
        $safeValue = $value === '' ? '(empty)' : $value;
        $userSections[] = '  <' . $key . '>' . stobePromptXmlEscape($safeValue) . '</' . $key . '>';
    }
    $userSections[] = '</current_profile_fields>';
    $userSections[] = '<recent_context>';
    $userSections[] = stobePromptXmlEscape($recentContext);
    $userSections[] = '</recent_context>';

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => implode("\n", $userSections)],
    ];

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php');

    $llmConfig = getLlmConfigForNpcPurpose($npcData, 'dynamic');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_api_key'];
    }

    $llmConfigForGeneration = $llmConfig;
    $configuredMax = intval($llmConfigForGeneration['max_tokens'] ?? 0);
    if ($configuredMax < 900) {
        $llmConfigForGeneration['max_tokens'] = 900;
    }

    $raw = stobeCallLLM($messages, $llmConfigForGeneration, [
        'npc_name' => $safeNpcName,
        'event_type' => 'dynamic_profile_generate',
        'response_format' => ['type' => 'json_object'],
    ]);
    if ($raw === false || trim(strval($raw)) === '') {
        return ['ok' => false, 'reason' => 'llm_failed'];
    }

    $parsed = stobeDynamicProfileDecodeJsonObject(strval($raw));
    if (count($parsed) === 0) {
        return ['ok' => false, 'reason' => 'parse_failed', 'response_preview' => substr(strval($raw), 0, 300)];
    }

    $updates = stobeDynamicProfileNormalizeGeneratedFields($parsed, $allowedFields);
    if (count($updates) === 0) {
        return ['ok' => false, 'reason' => 'no_usable_updates'];
    }

    return [
        'ok' => true,
        'updates' => $updates,
        'allowed_fields' => $allowedFields,
    ];
}

function stobeMaybeRunDynamicProfileCycle(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData = ''
): void {
    if (!stobeDynamicProfileShouldRunCycle($eventType, $gamets)) {
        return;
    }

    if (!stobeDynamicProfileTryLock()) {
        return;
    }

    try {
        $intervalHours = stobeDynamicProfileIntervalHours();
        $processed = false;
        if (stobeDynamicProfileProcessNarrator($intervalHours, $eventType, $gamets)) {
            $processed = true;
        }
        $candidates = stobeDynamicProfileFetchCandidates(64);

        foreach ($candidates as $candidate) {
            if ($processed) {
                break;
            }
            if (!is_array($candidate)) {
                continue;
            }

            $npcName = normalizeParticipantNameToken(strval($candidate['name'] ?? ''));
            if ($npcName === '') {
                continue;
            }

            $npcData = getNpcData($npcName);
            if (!is_array($npcData)) {
                continue;
            }
            if (!stobeDynamicProfileNpcEnabled($npcData)) {
                continue;
            }
            if (!stobeDynamicProfileNpcDue($npcName, $gamets, $intervalHours)) {
                continue;
            }

            $rows = stobeDynamicProfileFetchRecentContext($npcName, 30);
            $contextText = stobeDynamicProfileBuildContextText($rows);
            if ($contextText === '(none)') {
                continue;
            }

            $gen = stobeDynamicProfileGenerateUpdates($npcName, $npcData, $contextText);
            if (!boolval($gen['ok'] ?? false)) {
                stobeLogWarn('Dynamic profile generation skipped', [
                    'npc_name' => $npcName,
                    'reason' => strval($gen['reason'] ?? 'unknown'),
                ]);
                continue;
            }

            $updates = is_array($gen['updates'] ?? null) ? $gen['updates'] : [];
            if (count($updates) === 0) {
                continue;
            }

            $npcId = intval($npcData['id'] ?? 0);
            if ($npcId <= 0) {
                continue;
            }
            updateNpcById($npcId, $updates);

            if ($gamets > 0) {
                setConfOpt(stobeDynamicProfileLastGametsKey($npcName), strval($gamets), true);
            }
            setConfOpt(stobeDynamicProfileLastRunTsKey($npcName), strval(time()), true);

            stobeLogInfo('Dynamic profile updated', [
                'npc_name' => $npcName,
                'npc_id' => $npcId,
                'fields_updated' => array_keys($updates),
                'allowed_fields' => $gen['allowed_fields'] ?? [],
                'interval_hours' => $intervalHours,
                'event_type' => $eventType,
                'gamets' => $gamets,
            ]);

            $processed = true;
            break; // One NPC per cycle to keep request latency bounded.
        }

        setConfOpt('DYNAMIC_PROFILE_LAST_RUN_TS', strval(time()), true);
        if ($gamets > 0) {
            setConfOpt('DYNAMIC_PROFILE_LAST_RUN_GAMETS', strval($gamets), true);
        }

        if (!$processed) {
            stobeLogDebug('Dynamic profile cycle completed with no eligible NPC work', [
                'event_type' => $eventType,
                'gamets' => $gamets,
                'candidate_count' => count($candidates),
                'interval_hours' => $intervalHours,
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Dynamic profile cycle failed', [
            'event_type' => $eventType,
            'gamets' => $gamets,
        ]);
    } finally {
        stobeDynamicProfileUnlock();
    }
}
