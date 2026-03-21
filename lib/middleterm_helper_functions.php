<?php

/**
 * Middle-term memory generation for StobeServer.
 *
 * Herika-style behavior:
 * - Runs periodically from live traffic (no dedicated daemon required).
 * - Builds summary chunks from new eventlog rows for enabled NPCs.
 * - Persists summary rows into memory_summary.
 * - Appends latest summaries into core_npc.extended_data.middle_term_memory.
 */

function stobeMiddleTermNormalizeKeyToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return $normalized;
}

function stobeMiddleTermCursorKey(string $npcName): string
{
    return 'MIDDLETERM_CURSOR_ROWID_' . stobeMiddleTermNormalizeKeyToken($npcName);
}

function stobeMiddleTermAllowedEventType(string $eventType): bool
{
    $type = strtolower(trim($eventType));
    if ($type === '') {
        return false;
    }
    $blocked = [
        'location',
        'infoloc',
        'context',
        'setconf',
        'status_msg',
        'npc_snapshot',
        'playerinfo',
    ];
    return !in_array($type, $blocked, true);
}

function stobeMiddleTermRunIntervalGamets(): int
{
    $minutes = getSettingInt('MEMORY_AUTO_CREATE_SUMMARY_INTERVAL', 10);
    if ($minutes < 1) {
        $minutes = 1;
    } elseif ($minutes > 1440) {
        $minutes = 1440;
    }
    return $minutes * 60;
}

function stobeMiddleTermShouldRunCycle(string $eventType, int $gamets): bool
{
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return false;
    }
    if (!stobeMiddleTermAllowedEventType($eventType)) {
        return false;
    }

    $lastRunTs = intval(getConfOpt('MIDDLETERM_LAST_RUN_TS', '0'));
    $nowTs = time();
    if ($lastRunTs > 0 && ($nowTs - $lastRunTs) < 15) {
        return false;
    }

    if ($gamets <= 0) {
        return false;
    }

    $lastRunGamets = intval(getConfOpt('MIDDLETERM_LAST_RUN_GAMETS', '0'));
    if ($lastRunGamets > 0) {
        $intervalGamets = stobeMiddleTermRunIntervalGamets();
        if ($gamets >= $lastRunGamets && ($gamets - $lastRunGamets) < $intervalGamets) {
            return false;
        }
    }

    return true;
}

function stobeMiddleTermTryLock(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $row = $db->fetchOne("SELECT pg_try_advisory_lock(937461) AS locked");
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

function stobeMiddleTermUnlock(): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return;
    }
    $db->exec("SELECT pg_advisory_unlock(937461)");
}

function stobeMiddleTermNpcEnabled(array $npcData): bool
{
    return getNpcProfileBoolSetting(
        $npcData,
        ['middle_term_enabled', 'MIDDLE_TERM_MEMORY_ENABLED'],
        'MIDDLE_TERM_MEMORY_ENABLED',
        true
    );
}

function stobeMiddleTermFetchCandidates(int $limit = 64): array
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
         ORDER BY gamets_last_updated DESC, updated_at DESC
         LIMIT " . intval($limit)
    );
}

function stobeMiddleTermFetchNpcEventChunk(string $npcName, int $afterRowid, int $limit = 140): array
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
    } elseif ($limit > 400) {
        $limit = 400;
    }

    $excludeTypes = "'prechat','setconf','status_msg','user_input','npc_snapshot','playerinfo'";
    return $db->fetchAll(
        "SELECT rowid, type, data, gamets, localts, ts, people, location
         FROM eventlog
         WHERE rowid > $1
           AND type NOT IN ({$excludeTypes})
           AND (
                LOWER(COALESCE(people, '')) LIKE LOWER($2)
                OR LOWER(COALESCE(data, '')) LIKE LOWER($2)
           )
         ORDER BY rowid ASC
         LIMIT " . intval($limit),
        [
            $afterRowid,
            '%' . $safeNpcName . '%',
        ]
    );
}

function stobeMiddleTermLastSummaryFromExtended(array $npcData): string
{
    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $rawList = $extended['middle_term_memory'] ?? [];
    if (!is_array($rawList) || count($rawList) === 0) {
        return '';
    }

    $values = array_values($rawList);
    $last = end($values);
    if (!is_scalar($last) || $last === null) {
        return '';
    }
    return trim(sanitizeForKenshi(strval($last)));
}

function stobeMiddleTermBuildContextHistory(array $eventRows): string
{
    $lines = [];
    foreach ($eventRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $line = stobeFormatEventHistoryLine($row, true);
        if ($line === '') {
            continue;
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function stobeMiddleTermExtractPeopleFromEventRows(array $eventRows, string $npcName): array
{
    $namesByKey = [];
    $addName = static function (string $rawName) use (&$namesByKey): void {
        $name = normalizeParticipantNameToken($rawName);
        if ($name === '' || strcasecmp($name, 'The Narrator') === 0) {
            return;
        }
        $namesByKey[strtolower($name)] = $name;
    };
    $extractNameToken = static function (string $rawToken): string {
        $token = trim($rawToken);
        if ($token === '') {
            return '';
        }
        $pipePos = strpos($token, '|');
        if ($pipePos !== false) {
            return trim(substr($token, 0, $pipePos));
        }
        return $token;
    };

    $addName($npcName);
    foreach ($eventRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $peopleRaw = trim(strval($row['people'] ?? ''));
        if ($peopleRaw !== '') {
            $decoded = json_decode($peopleRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $entry) {
                    if (!is_scalar($entry) || $entry === null) {
                        continue;
                    }
                    $addName($extractNameToken(strval($entry)));
                }
            } else {
                $pieces = explode(',', $peopleRaw);
                foreach ($pieces as $piece) {
                    $addName($extractNameToken($piece));
                }
            }
        }

        $rawData = strval($row['data'] ?? '');
        if ($rawData !== '' && function_exists('stobeRegularMemoryParseDialogueData')) {
            $parsed = stobeRegularMemoryParseDialogueData($rawData);
            $speaker = strval($parsed['speaker'] ?? '');
            $target = strval($parsed['target'] ?? '');
            if ($speaker !== '') {
                $addName($speaker);
            }
            if ($target !== '') {
                $addName($target);
            }
        }
    }

    if (count($namesByKey) === 0) {
        $fallback = normalizeParticipantNameToken($npcName);
        if ($fallback === '') {
            return [];
        }
        return [$fallback];
    }

    ksort($namesByKey);
    return array_values($namesByKey);
}

function stobeMiddleTermGenerateSummary(string $npcName, array $npcData, array $eventRows): array
{
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return ['ok' => false, 'reason' => 'missing_npc_name'];
    }
    if (count($eventRows) === 0) {
        return ['ok' => false, 'reason' => 'no_event_rows'];
    }

    $history = stobeMiddleTermBuildContextHistory($eventRows);
    if ($history === '') {
        return ['ok' => false, 'reason' => 'empty_history'];
    }

    $previousSummary = stobeMiddleTermLastSummaryFromExtended($npcData);

    $defaultSystemPrompt = "<middle_term_memory_summarizer>\n"
        . "  <rule>You summarize longer-term narrative continuity for one Kenshi NPC.</rule>\n"
        . "  <rule>Maintain strict in-world continuity. Do not invent events not present in the inputs.</rule>\n"
        . "  <rule>Prefer compact continuity notes over verbose retelling.</rule>\n"
        . "  <rule>Preserve major relationship shifts, injuries, faction conflicts, goals, and unresolved tensions.</rule>\n"
        . "  <rule>Output plain text only, no XML wrappers or JSON.</rule>\n"
        . "</middle_term_memory_summarizer>";
    $systemPrompt = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('middleterm_memory_summarizer', $defaultSystemPrompt)
        : $defaultSystemPrompt;

    $previousSummaryBlock = '';
    if ($previousSummary !== '') {
        $previousSummaryBlock = "  <previous_summary>" . stobePromptXmlEscape($previousSummary) . "</previous_summary>\n";
    }
    $defaultUserPrompt = "<middle_term_memory_request>\n"
        . "  <npc_name>#NPC_NAME#</npc_name>\n"
        . "#PREVIOUS_SUMMARY_BLOCK#"
        . "  <context_history>#CONTEXT_HISTORY#</context_history>\n"
        . "  <instruction>Create an updated continuity summary for this NPC using previous summary plus new context.</instruction>\n"
        . "  <instruction>Keep it concise and durable for future prompt injection.</instruction>\n"
        . "</middle_term_memory_request>";
    $userPromptTemplate = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('middleterm_memory_request', $defaultUserPrompt)
        : $defaultUserPrompt;
    $userPrompt = strtr($userPromptTemplate, [
        '#NPC_NAME#' => stobePromptXmlEscape($safeNpcName),
        '#PREVIOUS_SUMMARY_BLOCK#' => $previousSummaryBlock,
        '#CONTEXT_HISTORY#' => stobePromptXmlEscape($history),
    ]);

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php');

    $llmConfig = getLlmConfigForNpcPurpose($npcData, 'middleterm');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_api_key'];
    }

    $raw = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $safeNpcName,
        'event_type' => 'middleterm_generate',
    ]);
    if ($raw === false) {
        return ['ok' => false, 'reason' => 'llm_failed'];
    }

    $summary = trim(sanitizeForKenshi(strval($raw)));
    if ($summary === '') {
        return ['ok' => false, 'reason' => 'llm_empty'];
    }
    $summary = truncatePromptValue($summary, 3500);

    return [
        'ok' => true,
        'summary' => $summary,
        'history_length' => strlen($history),
    ];
}

function stobeMiddleTermPersistSummary(string $npcName, array $npcData, array $eventRows, string $summary): bool
{
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '' || trim($summary) === '') {
        return false;
    }
    if (count($eventRows) === 0) {
        return false;
    }

    $first = $eventRows[0];
    $last = $eventRows[count($eventRows) - 1];
    $firstLocalTs = intval($first['localts'] ?? time());
    $lastLocalTs = intval($last['localts'] ?? $firstLocalTs);
    if ($firstLocalTs <= 0) {
        $firstLocalTs = time();
    }
    if ($lastLocalTs <= 0) {
        $lastLocalTs = $firstLocalTs;
    }

    $periodStart = gmdate('Y-m-d H:i:s', $firstLocalTs);
    $periodEnd = gmdate('Y-m-d H:i:s', $lastLocalTs);
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $gametsStart = max(0, intval($first['gamets'] ?? 0));
    $gametsEnd = max($gametsStart, intval($last['gamets'] ?? $gametsStart));
    $npcId = intval($npcData['id'] ?? 0);
    if ($npcId <= 0) {
        $lookup = getNpcData($safeNpcName);
        if (is_array($lookup)) {
            $npcId = intval($lookup['id'] ?? 0);
            if (!is_array($npcData) || count($npcData) === 0) {
                $npcData = $lookup;
            }
        }
    }

    // Herika flow compatibility: middle-term output must live in NPC extended_data.
    $extended = normalizeCoreNpcExtendedData($npcData['extended_data'] ?? []);
    $existing = $extended['middle_term_memory'] ?? [];
    $memoryMap = [];
    if (is_array($existing)) {
        foreach ($existing as $key => $entry) {
            if (!is_scalar($entry) || $entry === null) {
                continue;
            }
            $text = trim(sanitizeForKenshi(strval($entry)));
            if ($text === '') {
                continue;
            }
            $entryKey = strval($key);
            if ($entryKey === '' || strtolower($entryKey) === 'null') {
                $entryKey = strval(count($memoryMap) + 1);
            }
            $memoryMap[$entryKey] = truncatePromptValue($text, 3500);
        }
    }

    $summaryText = truncatePromptValue(trim(sanitizeForKenshi($summary)), 3500);
    $newKey = strval($gametsEnd > 0 ? $gametsEnd : max($lastLocalTs, time()));
    $memoryMap[$newKey] = $summaryText;

    // Keep only the latest 20 entries (prefer numeric key order for game-ts keyed maps).
    if (count($memoryMap) > 20) {
        $sortable = [];
        foreach ($memoryMap as $key => $text) {
            $sortKey = preg_match('/^-?\d+$/', $key) === 1 ? intval($key) : PHP_INT_MIN;
            $sortable[] = ['k' => $key, 'v' => $text, 's' => $sortKey];
        }
        usort($sortable, static function (array $a, array $b): int {
            if ($a['s'] === $b['s']) {
                return strcmp(strval($a['k']), strval($b['k']));
            }
            return ($a['s'] < $b['s']) ? -1 : 1;
        });
        if (count($sortable) > 20) {
            $sortable = array_slice($sortable, -20);
        }
        $memoryMap = [];
        foreach ($sortable as $row) {
            $memoryMap[strval($row['k'])] = strval($row['v']);
        }
    }
    $extended['middle_term_memory'] = $memoryMap;

    if ($npcId > 0) {
        updateNpcById($npcId, ['extended_data' => $extended]);
    } else {
        stobeLogWarn('Middle-term summary generated but NPC id missing for extended_data update', [
            'npc_name' => $safeNpcName,
            'gamets_end' => $gametsEnd,
        ]);
    }

    // Best-effort audit row in memory_summary; profile extended_data remains source of truth.
    try {
        $peopleNames = stobeMiddleTermExtractPeopleFromEventRows($eventRows, $safeNpcName);
        $peopleKey = json_encode($peopleNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($peopleKey) || trim($peopleKey) === '') {
            $peopleKey = '["' . addslashes($safeNpcName) . '"]';
        }

        $intMax = 2147483647;
        $sourceFromId = max(0, min($intMax, intval($first['rowid'] ?? 0)));
        $sourceToId = max($sourceFromId, min($intMax, intval($last['rowid'] ?? $sourceFromId)));
        $packedMessage = truncatePromptValue(stobeMiddleTermBuildContextHistory($eventRows), 12000);
        $summaryEmbedding = [];
        if (function_exists('stobeRegularMemoryEmbedText')) {
            $summaryEmbedding = stobeRegularMemoryEmbedText($summaryText);
        }
        $vectorLiteral = '';
        if (function_exists('stobeRegularMemoryVectorLiteral')) {
            $vectorLiteral = stobeRegularMemoryVectorLiteral($summaryEmbedding);
        }

        $db->exec(
            "INSERT INTO memory_summary (
                people,
                summary,
                embedding,
                period_start,
                period_end,
                n,
                packed_message,
                source_from_memory_id,
                source_to_memory_id,
                localts,
                gamets_start,
                gamets_end,
                created_at
            ) VALUES (
                $1,
                $2,
                CASE WHEN $3 = '' THEN NULL ELSE $3::vector END,
                $4,
                $5,
                $6,
                $7,
                $8,
                $9,
                $10,
                $11,
                $12,
                NOW()
            )",
            [
                $peopleKey,
                $summaryText,
                $vectorLiteral,
                $periodStart,
                $periodEnd,
                count($eventRows),
                $packedMessage,
                $sourceFromId,
                $sourceToId,
                $lastLocalTs,
                $gametsStart,
                $gametsEnd,
            ]
        );
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Middle-term memory_summary audit insert failed', [
            'npc_name' => $safeNpcName,
            'gamets_start' => $gametsStart,
            'gamets_end' => $gametsEnd,
            'event_rows' => count($eventRows),
        ]);
    }

    return $npcId > 0;
}

function stobeMaybeRunMiddleTermCycle(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData = ''
): void {
    if (!stobeMiddleTermShouldRunCycle($eventType, $gamets)) {
        return;
    }

    if (!stobeMiddleTermTryLock()) {
        return;
    }

    try {
        $minEvents = 8;
        $maxRows = 140;
        $processed = false;
        $candidates = stobeMiddleTermFetchCandidates(64);

        foreach ($candidates as $candidate) {
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
            if (!stobeMiddleTermNpcEnabled($npcData)) {
                continue;
            }

            $cursorKey = stobeMiddleTermCursorKey($npcName);
            $cursorRowid = intval(getConfOpt($cursorKey, '0'));
            $eventRows = stobeMiddleTermFetchNpcEventChunk($npcName, $cursorRowid, $maxRows);
            if (count($eventRows) < $minEvents) {
                continue;
            }

            $gen = stobeMiddleTermGenerateSummary($npcName, $npcData, $eventRows);
            if (!boolval($gen['ok'] ?? false)) {
                stobeLogWarn('Middle-term summary generation skipped', [
                    'npc_name' => $npcName,
                    'reason' => strval($gen['reason'] ?? 'unknown'),
                    'event_rows' => count($eventRows),
                    'cursor_rowid' => $cursorRowid,
                ]);
                continue;
            }

            $summary = strval($gen['summary'] ?? '');
            if ($summary === '') {
                continue;
            }

            $persisted = stobeMiddleTermPersistSummary($npcName, $npcData, $eventRows, $summary);
            if (!$persisted) {
                stobeLogWarn('Middle-term summary persist failed', [
                    'npc_name' => $npcName,
                    'event_rows' => count($eventRows),
                    'cursor_rowid' => $cursorRowid,
                ]);
                continue;
            }

            $lastRow = $eventRows[count($eventRows) - 1];
            $lastRowid = intval($lastRow['rowid'] ?? 0);
            if ($lastRowid > 0) {
                setConfOpt($cursorKey, strval($lastRowid), true);
            }

            stobeLogInfo('Middle-term summary generated', [
                'npc_name' => $npcName,
                'event_rows' => count($eventRows),
                'cursor_before' => $cursorRowid,
                'cursor_after' => $lastRowid,
                'summary_length' => strlen($summary),
                'history_length' => intval($gen['history_length'] ?? 0),
                'event_type' => $eventType,
                'gamets' => $gamets,
            ]);

            $processed = true;
            break; // One NPC per cycle to keep latency bounded.
        }

        setConfOpt('MIDDLETERM_LAST_RUN_TS', strval(time()), true);
        if ($gamets > 0) {
            setConfOpt('MIDDLETERM_LAST_RUN_GAMETS', strval($gamets), true);
        }

        if (!$processed) {
            stobeLogDebug('Middle-term cycle completed with no eligible NPC work', [
                'event_type' => $eventType,
                'gamets' => $gamets,
                'candidate_count' => count($candidates),
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Middle-term cycle failed', [
            'event_type' => $eventType,
            'gamets' => $gamets,
        ]);
    } finally {
        stobeMiddleTermUnlock();
    }
}
