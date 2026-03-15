<?php

/**
 * Regular memory pipeline for StobeServer.
 *
 * Provides Herika-style flow:
 * - Capture dialogue/events into `memory` rows.
 * - Generate embeddings through txt2vec (`/embed`).
 * - Periodically pack memory rows into summarized `memory_summary` records.
 * - Recall relevant summaries with vector search for prompt injection.
 */

function stobeRegularMemoryNormalizeKeyToken(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return $normalized;
}

function stobeRegularMemoryCursorKey(string $peopleKey): string
{
    $normalized = strtolower(trim($peopleKey));
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return 'MEMORY_CURSOR_ID_' . md5($normalized);
}

function stobeRegularMemoryAllowedEventType(string $eventType): bool
{
    $type = strtolower(trim($eventType));
    if ($type === '') {
        return false;
    }
    $allow = [
        'inputtext',
        'inputtext_s',
        'chat',
        'rechat',
        'bored',
        'action',
        'diary',
    ];
    return in_array($type, $allow, true);
}

function stobeRegularMemoryRunIntervalGamets(): int
{
    $minutes = getSettingInt('MEMORY_AUTO_CREATE_SUMMARY_INTERVAL', 10);
    if ($minutes < 1) {
        $minutes = 1;
    } elseif ($minutes > 1440) {
        $minutes = 1440;
    }
    return $minutes * 60;
}

function stobeRegularMemoryShouldRunCycle(string $eventType, int $gamets): bool
{
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return false;
    }
    if (!stobeRegularMemoryAllowedEventType($eventType)) {
        return false;
    }

    $lastRunTs = intval(getConfOpt('MEMORY_LAST_RUN_TS', '0'));
    $nowTs = time();
    if ($lastRunTs > 0 && ($nowTs - $lastRunTs) < 12) {
        return false;
    }

    if ($gamets <= 0) {
        return false;
    }

    $lastRunGamets = intval(getConfOpt('MEMORY_LAST_RUN_GAMETS', '0'));
    if ($lastRunGamets > 0) {
        $intervalGamets = stobeRegularMemoryRunIntervalGamets();
        if ($gamets >= $lastRunGamets && ($gamets - $lastRunGamets) < $intervalGamets) {
            return false;
        }
    }

    return true;
}

function stobeRegularMemoryTryLock(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }
    $row = $db->fetchOne("SELECT pg_try_advisory_lock(937463) AS locked");
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

function stobeRegularMemoryUnlock(): void
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return;
    }
    $db->exec("SELECT pg_advisory_unlock(937463)");
}

function stobeRegularMemoryTableAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }
    $row = $db->fetchOne("SELECT to_regclass('public.memory') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeRegularMemorySummaryTableAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return boolval($available);
    }
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        $available = false;
        return false;
    }
    $row = $db->fetchOne("SELECT to_regclass('public.memory_summary') AS rel");
    $available = is_array($row) && trim(strval($row['rel'] ?? '')) !== '';
    return boolval($available);
}

function stobeRegularMemoryParseDialogueData(string $eventData): array
{
    $source = trim($eventData);
    if ($source === '') {
        return ['speaker' => '', 'target' => '', 'message' => ''];
    }

    $target = '';
    if (preg_match('/\(talking to:\s*([^\)]+)\)/i', $source, $matches) === 1) {
        $target = normalizeParticipantNameToken(trim(strval($matches[1] ?? '')));
        $source = trim(preg_replace('/\s*\(talking to:\s*[^\)]+\)\s*/i', ' ', $source) ?? $source);
    }

    $speaker = '';
    $message = $source;
    $parts = explode(': ', $source, 2);
    if (count($parts) === 2) {
        $speaker = normalizeParticipantNameToken(strval($parts[0] ?? ''));
        $message = trim(strval($parts[1] ?? ''));
    } else {
        $colonPos = strpos($source, ':');
        if ($colonPos !== false && $colonPos > 0) {
            $speaker = normalizeParticipantNameToken(substr($source, 0, $colonPos));
            $message = trim(substr($source, $colonPos + 1));
        }
    }

    return [
        'speaker' => $speaker,
        'target' => $target,
        'message' => trim($message),
    ];
}

function stobeRegularMemoryDecodePeopleKey(string $peopleKey): array
{
    $value = trim($peopleKey);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded)) {
        $namesByKey = [];
        foreach ($decoded as $entry) {
            if (!is_scalar($entry) || $entry === null) {
                continue;
            }
            $name = normalizeParticipantNameToken(strval($entry));
            if ($name === '') {
                continue;
            }
            $namesByKey[strtolower($name)] = $name;
        }
        if (count($namesByKey) > 0) {
            ksort($namesByKey);
            return array_values($namesByKey);
        }
    }

    $single = normalizeParticipantNameToken($value);
    return $single !== '' ? [$single] : [];
}

function stobeRegularMemoryBuildPeopleNames(string $peopleRaw, string $speaker, string $target): array
{
    $namesByKey = [];
    $addName = static function (string $rawName) use (&$namesByKey): void {
        $name = normalizeParticipantNameToken($rawName);
        if ($name === '' || strcasecmp($name, 'The Narrator') === 0) {
            return;
        }
        $namesByKey[strtolower($name)] = $name;
    };

    if (trim($peopleRaw) !== '') {
        $decoded = json_decode($peopleRaw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $entry) {
                if (!is_scalar($entry) || $entry === null) {
                    continue;
                }
                $addName(strval($entry));
            }
        } else {
            $addName($peopleRaw);
        }
    }

    $addName($speaker);
    $addName($target);

    if (count($namesByKey) === 0) {
        return [];
    }

    ksort($namesByKey);
    return array_values($namesByKey);
}

function stobeRegularMemoryBuildPeopleKey(array $names): string
{
    $clean = [];
    foreach ($names as $name) {
        if (!is_scalar($name) || $name === null) {
            continue;
        }
        $normalized = normalizeParticipantNameToken(strval($name));
        if ($normalized === '') {
            continue;
        }
        $clean[strtolower($normalized)] = $normalized;
    }

    if (count($clean) === 0) {
        return '[]';
    }

    ksort($clean);
    $ordered = array_values($clean);
    $encoded = json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || trim($encoded) === '') {
        return '[]';
    }
    return $encoded;
}

function stobeRegularMemoryPeopleLabel(string $peopleKey): string
{
    $names = stobeRegularMemoryDecodePeopleKey($peopleKey);
    if (count($names) === 0) {
        return '';
    }
    return implode(', ', $names);
}

function stobeRegularMemoryNormalizeEmbeddingVector(mixed $decoded): array
{
    $vector = [];
    if (is_array($decoded)) {
        if (isset($decoded['embedding']) && is_array($decoded['embedding'])) {
            $vector = $decoded['embedding'];
        } else {
            $vector = $decoded;
        }
    }

    $normalized = [];
    foreach ($vector as $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $floatVal = floatval($value);
        if (!is_finite($floatVal)) {
            continue;
        }
        $normalized[] = $floatVal;
    }

    if (count($normalized) < 8) {
        return [];
    }
    return $normalized;
}

function stobeRegularMemoryEmbedText(string $text): array
{
    if (!getMemoryUseText2Vec()) {
        return [];
    }

    $payload = trim($text);
    if ($payload === '') {
        return [];
    }
    $payload = truncatePromptValue($payload, 2200);

    $url = rtrim(getMemoryTxtaiUrl(), '/') . '/embed';
    $body = json_encode(['text' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body) || $body === '') {
        return [];
    }

    $timeout = isset($GLOBALS['HTTP_TIMEOUT']) ? intval($GLOBALS['HTTP_TIMEOUT']) : 15;
    if ($timeout < 4) {
        $timeout = 4;
    } elseif ($timeout > 30) {
        $timeout = 30;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if (!is_string($raw) || trim($raw) === '') {
        stobeLogWarn('Regular memory embedding request failed', ['url' => $url]);
        return [];
    }

    $decoded = json_decode($raw, true);
    $vector = stobeRegularMemoryNormalizeEmbeddingVector($decoded);
    if (count($vector) === 0) {
        stobeLogWarn('Regular memory embedding response empty/invalid', [
            'url' => $url,
            'response_preview' => substr($raw, 0, 180),
        ]);
    }
    return $vector;
}

function stobeRegularMemoryVectorLiteral(array $embedding): string
{
    if (count($embedding) === 0) {
        return '';
    }
    $parts = [];
    foreach ($embedding as $value) {
        if (!is_numeric($value)) {
            continue;
        }
        $parts[] = rtrim(rtrim(sprintf('%.10F', floatval($value)), '0'), '.');
    }
    if (count($parts) === 0) {
        return '';
    }
    return '[' . implode(',', $parts) . ']';
}

function stobeRegularMemoryStoreRow(
    string $peopleKey,
    string $content,
    string $eventType,
    int $gamets,
    int $localts
): bool {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        return false;
    }

    $safePeople = trim($peopleKey);
    $safeContent = trim(sanitizeForKenshi($content));
    $safeEventType = strtolower(trim($eventType));
    if ($safePeople === '' || $safePeople === '[]' || $safeContent === '' || $safeEventType === '') {
        return false;
    }

    $dupeCutoff = max(0, $localts - 2);
    $duplicate = $db->fetchOne(
        "SELECT id
         FROM memory
         WHERE LOWER(people) = LOWER($1)
           AND event_type = $2
           AND content = $3
           AND localts >= $4
         ORDER BY id DESC
         LIMIT 1",
        [$safePeople, $safeEventType, $safeContent, $dupeCutoff]
    );
    if (is_array($duplicate) && intval($duplicate['id'] ?? 0) > 0) {
        return false;
    }

    $embedding = stobeRegularMemoryEmbedText($safeContent);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);

    $result = $db->exec(
        "INSERT INTO memory (
            people,
            content,
            embedding,
            event_type,
            gamets,
            localts,
            created_at
        ) VALUES (
            $1,
            $2,
            CASE WHEN $3 = '' THEN NULL ELSE $3::vector END,
            $4,
            $5,
            $6,
            NOW()
        )",
        [
            $safePeople,
            truncatePromptValue($safeContent, 2800),
            $vectorLiteral,
            $safeEventType,
            max(0, $gamets),
            max(0, $localts),
        ]
    );

    return $result !== false;
}

function stobeMemoryTrackEvent(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData,
    string $people = '',
    string $location = '',
    int $localts = 0
): int {
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return 0;
    }
    if (!stobeRegularMemoryAllowedEventType($eventType)) {
        return 0;
    }

    $safeType = strtolower(trim($eventType));
    $parsed = stobeRegularMemoryParseDialogueData($eventData);
    $speaker = normalizeParticipantNameToken(strval($parsed['speaker'] ?? ''));
    $target = normalizeParticipantNameToken(strval($parsed['target'] ?? ''));
    $message = trim(strval($parsed['message'] ?? ''));
    if ($message === '') {
        $message = trim(sanitizeForKenshi($eventData));
    }
    if ($message === '') {
        return 0;
    }

    $line = $message;
    if ($speaker !== '') {
        $line = $speaker . ': ' . $message;
    }
    if ($target !== '') {
        $line .= ' (talking to: ' . $target . ')';
    }

    $peopleNames = stobeRegularMemoryBuildPeopleNames($people, $speaker, $target);
    if (count($peopleNames) === 0) {
        return 0;
    }
    $peopleKey = stobeRegularMemoryBuildPeopleKey($peopleNames);
    if ($peopleKey === '' || $peopleKey === '[]') {
        return 0;
    }

    $safeLocalTs = $localts > 0 ? $localts : time();
    $stored = stobeRegularMemoryStoreRow($peopleKey, $line, $safeType, $gamets, $safeLocalTs) ? 1 : 0;

    if ($stored > 0) {
        stobeLogDebug('Regular memory rows stored from event', [
            'event_type' => $safeType,
            'stored' => $stored,
            'people' => $peopleKey,
            'people_count' => count($peopleNames),
            'speaker' => $speaker,
            'target' => $target,
            'gamets' => $gamets,
        ]);
    }

    return $stored;
}

function stobeRegularMemoryFetchPeopleCandidates(int $limit = 64): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        return [];
    }
    if ($limit < 1) {
        $limit = 1;
    } elseif ($limit > 256) {
        $limit = 256;
    }

    return $db->fetchAll(
        "SELECT people, MAX(id) AS last_memory_id, COUNT(*) AS row_count
         FROM memory
         WHERE COALESCE(BTRIM(people), '') <> ''
           AND BTRIM(people) <> '[]'
         GROUP BY people
         ORDER BY MAX(id) DESC
         LIMIT " . intval($limit)
    );
}

function stobeRegularMemoryFetchRowsAfterCursor(string $peopleKey, int $afterId, int $limit = 240): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemoryTableAvailable()) {
        return [];
    }
    $safePeople = trim($peopleKey);
    if ($safePeople === '' || $safePeople === '[]') {
        return [];
    }
    if ($limit < 10) {
        $limit = 10;
    } elseif ($limit > 500) {
        $limit = 500;
    }

    return $db->fetchAll(
        "SELECT id, people, content, event_type, gamets, localts, created_at
         FROM memory
         WHERE LOWER(people) = LOWER($1)
           AND id > $2
         ORDER BY id ASC
         LIMIT " . intval($limit),
        [$safePeople, max(0, $afterId)]
    );
}

function stobeRegularMemoryBuildPackedMessage(array $rows): string
{
    $lines = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $content = trim(sanitizeForKenshi(strval($row['content'] ?? '')));
        if ($content === '') {
            continue;
        }
        $eventType = strtolower(trim(strval($row['event_type'] ?? 'memory')));
        $gamets = intval($row['gamets'] ?? 0);
        $line = '[' . $eventType . ' @ ' . stobeGametsDateLabel($gamets) . '] ' . $content;
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

function stobeRegularMemoryGenerateSummary(string $peopleKey, array $rows): string
{
    $packed = trim(stobeRegularMemoryBuildPackedMessage($rows));
    if ($packed === '') {
        return '';
    }

    $previous = '';
    $db = $GLOBALS['db'] ?? null;
    if ($db && stobeRegularMemorySummaryTableAvailable()) {
        $prevRow = $db->fetchOne(
            "SELECT summary
             FROM memory_summary
             WHERE LOWER(people) = LOWER($1)
             ORDER BY id DESC
             LIMIT 1",
            [$peopleKey]
        );
        if (is_array($prevRow)) {
            $previous = trim(sanitizeForKenshi(strval($prevRow['summary'] ?? '')));
        }
    }

    $peopleLabel = stobeRegularMemoryPeopleLabel($peopleKey);
    if ($peopleLabel === '') {
        $peopleLabel = '(unknown group)';
    }

    $defaultSystemPrompt = "Focus on key events, tagging characters, locations, and factions accurately. "
        . "Ensure memories align and maintain chronological order while foreshadowing future arcs.";
    $systemPrompt = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('regular_memory_summarizer', $defaultSystemPrompt)
        : $defaultSystemPrompt;

    $userPrompt = "<regular_memory_request>\n"
        . "  <people>" . (function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($peopleLabel) : $peopleLabel) . "</people>\n";
    if ($previous !== '') {
        $escapedPrev = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($previous) : $previous;
        $userPrompt .= "  <previous_summary>{$escapedPrev}</previous_summary>\n";
    }
    $escapedPacked = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($packed) : $packed;
    $userPrompt .= "  <packed_events>{$escapedPacked}</packed_events>\n"
        . "  <instruction>Create an updated concise memory summary for future retrieval.</instruction>\n"
        . "</regular_memory_request>";

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector' . DIRECTORY_SEPARATOR . 'llm_dispatcher.php');
    $llmConfig = getLlmConfigForNpcPurpose(false, 'middleterm');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return truncatePromptValue($packed, 2500);
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $userPrompt],
    ];
    $raw = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $peopleLabel,
        'event_type' => 'memory_summary_generate',
    ]);
    if ($raw === false) {
        return truncatePromptValue($packed, 2500);
    }

    $summary = trim(sanitizeForKenshi(strval($raw)));
    if ($summary === '') {
        return truncatePromptValue($packed, 2500);
    }
    return truncatePromptValue($summary, 3500);
}

function stobeRegularMemoryPersistSummary(string $peopleKey, array $rows, string $summary): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return false;
    }
    if (trim($summary) === '' || count($rows) === 0) {
        return false;
    }

    $first = $rows[0];
    $last = $rows[count($rows) - 1];
    $fromId = intval($first['id'] ?? 0);
    $toId = intval($last['id'] ?? 0);
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
    $gametsStart = max(0, intval($first['gamets'] ?? 0));
    $gametsEnd = max($gametsStart, intval($last['gamets'] ?? $gametsStart));
    $packedMessage = stobeRegularMemoryBuildPackedMessage($rows);
    $packedMessage = truncatePromptValue($packedMessage, 12000);
    $embedding = stobeRegularMemoryEmbedText($summary);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);

    $result = $db->exec(
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
            $summary,
            $vectorLiteral,
            $periodStart,
            $periodEnd,
            count($rows),
            $packedMessage,
            $fromId,
            $toId,
            $lastLocalTs,
            $gametsStart,
            $gametsEnd,
        ]
    );

    return $result !== false;
}

function stobeMaybeRunRegularMemoryCycle(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData = ''
): void {
    if (!stobeRegularMemoryShouldRunCycle($eventType, $gamets)) {
        return;
    }
    if (!stobeRegularMemoryTryLock()) {
        return;
    }

    try {
        $minRows = 10;
        $processed = false;
        $candidates = stobeRegularMemoryFetchPeopleCandidates(72);
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $peopleKey = trim(strval($candidate['people'] ?? ''));
            if ($peopleKey === '' || $peopleKey === '[]') {
                continue;
            }

            $cursorKey = stobeRegularMemoryCursorKey($peopleKey);
            $cursor = intval(getConfOpt($cursorKey, '0'));
            $rows = stobeRegularMemoryFetchRowsAfterCursor($peopleKey, $cursor, 260);
            if (count($rows) < $minRows) {
                continue;
            }

            $summary = stobeRegularMemoryGenerateSummary($peopleKey, $rows);
            if ($summary === '') {
                continue;
            }

            if (!stobeRegularMemoryPersistSummary($peopleKey, $rows, $summary)) {
                stobeLogWarn('Regular memory summary persist failed', [
                    'people' => $peopleKey,
                    'rows' => count($rows),
                    'cursor_before' => $cursor,
                ]);
                continue;
            }

            $last = $rows[count($rows) - 1];
            $lastId = intval($last['id'] ?? 0);
            if ($lastId > 0) {
                setConfOpt($cursorKey, strval($lastId), true);
            }

            stobeLogInfo('Regular memory summary generated', [
                'people' => $peopleKey,
                'people_count' => count(stobeRegularMemoryDecodePeopleKey($peopleKey)),
                'rows' => count($rows),
                'cursor_before' => $cursor,
                'cursor_after' => $lastId,
                'summary_length' => strlen($summary),
                'gamets' => $gamets,
                'event_type' => $eventType,
            ]);

            $processed = true;
            break; // Keep latency bounded.
        }

        setConfOpt('MEMORY_LAST_RUN_TS', strval(time()), true);
        if ($gamets > 0) {
            setConfOpt('MEMORY_LAST_RUN_GAMETS', strval($gamets), true);
        }

        if (!$processed) {
            stobeLogDebug('Regular memory cycle completed with no eligible NPC work', [
                'event_type' => $eventType,
                'gamets' => $gamets,
                'candidate_count' => count($candidates),
            ]);
        }
    } catch (Throwable $exception) {
        stobeLogException($exception, 'Regular memory cycle failed', [
            'event_type' => $eventType,
            'gamets' => $gamets,
        ]);
    } finally {
        stobeRegularMemoryUnlock();
    }
}

function stobeRegularMemoryRecallRows(
    string $npcName,
    string $queryText,
    int $currentGamets,
    int $maxEntries
): array {
    $db = $GLOBALS['db'] ?? null;
    if (!$db || !stobeRegularMemorySummaryTableAvailable()) {
        return [];
    }

    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '') {
        return [];
    }
    if ($maxEntries < 1) {
        $maxEntries = 1;
    } elseif ($maxEntries > 8) {
        $maxEntries = 8;
    }

    $memoryDelayMinutes = getSettingInt('MEMORY_TIME_DELAY', 12);
    if ($memoryDelayMinutes < 0) {
        $memoryDelayMinutes = 0;
    } elseif ($memoryDelayMinutes > 1440) {
        $memoryDelayMinutes = 1440;
    }
    $oldestAllowedGamets = 0;
    if ($currentGamets > 0 && $memoryDelayMinutes > 0) {
        $oldestAllowedGamets = max(0, $currentGamets - ($memoryDelayMinutes * 60));
    }

    $queryBasis = trim($queryText);
    if ($queryBasis === '') {
        $queryBasis = 'recent dialogue context';
    }
    $embedding = stobeRegularMemoryEmbedText($queryBasis);
    $vectorLiteral = stobeRegularMemoryVectorLiteral($embedding);
    $jsonQuotedNpc = strtolower(json_encode($safeNpc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    if ($jsonQuotedNpc === '') {
        $jsonQuotedNpc = '"' . strtolower($safeNpc) . '"';
    }

    if ($vectorLiteral === '') {
        return $db->fetchAll(
            "SELECT summary, gamets_end, created_at, people
             FROM memory_summary
             WHERE (
                    LOWER(people) = LOWER($1)
                    OR POSITION($2 IN LOWER(COALESCE(people, ''))) > 0
                   )
               AND summary IS NOT NULL
               AND BTRIM(summary) <> ''
               AND ($3::BIGINT <= 0 OR COALESCE(gamets_end, 0) <= $3::BIGINT OR COALESCE(gamets_end, 0) = 0)
             ORDER BY COALESCE(gamets_end, 0) DESC, created_at DESC
             LIMIT " . intval($maxEntries),
            [$safeNpc, $jsonQuotedNpc, $oldestAllowedGamets]
        );
    }

    $rows = $db->fetchAll(
        "SELECT
            summary,
            gamets_end,
            people,
            embedding <-> $2::vector AS distance,
            created_at
         FROM memory_summary
         WHERE (
                LOWER(people) = LOWER($1)
                OR POSITION($3 IN LOWER(COALESCE(people, ''))) > 0
               )
           AND embedding IS NOT NULL
           AND summary IS NOT NULL
           AND BTRIM(summary) <> ''
           AND ($4::BIGINT <= 0 OR COALESCE(gamets_end, 0) <= $4::BIGINT OR COALESCE(gamets_end, 0) = 0)
         ORDER BY embedding <-> $2::vector ASC, created_at DESC
         LIMIT " . intval($maxEntries * 6),
        [$safeNpc, $vectorLiteral, $jsonQuotedNpc, $oldestAllowedGamets]
    );

    if (!is_array($rows) || count($rows) === 0) {
        return [];
    }

    $biasA = getSettingInt('MEMORY_BIAS_A', 33);
    $biasB = getSettingInt('MEMORY_BIAS_B', 66);
    $distanceLimit = max(0.2, min(1.5, max($biasA, $biasB) / 100.0));

    $selected = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $distance = floatval($row['distance'] ?? 9.9);
        if ($distance <= $distanceLimit) {
            $selected[] = $row;
        }
        if (count($selected) >= $maxEntries) {
            break;
        }
    }

    if (count($selected) === 0 && count($rows) > 0) {
        $selected[] = $rows[0];
    }

    return array_values($selected);
}

function stobeBuildRegularMemoryPromptBlock(
    array $npcData,
    string $npcName,
    string $queryText = '',
    int $currentGamets = 0
): string {
    $safeNpc = normalizeParticipantNameToken($npcName);
    if ($safeNpc === '' || strcasecmp($safeNpc, 'The Narrator') === 0) {
        return '';
    }
    if (!getSettingBool('MEMORY_ENABLED', true)) {
        return '';
    }

    $maxEntries = getNpcProfileIntegerSetting(
        $npcData,
        ['MEMORY_CONTEXT_SIZE'],
        'MEMORY_CONTEXT_SIZE',
        1,
        1,
        8
    );
    $rows = stobeRegularMemoryRecallRows($safeNpc, $queryText, $currentGamets, $maxEntries);
    if (count($rows) === 0) {
        return '';
    }

    $lines = [];
    $lines[] = '<memory>';
    $lines[] = 'Past recalled memories';
    foreach ($rows as $row) {
        $summary = trim(sanitizeForKenshi(strval($row['summary'] ?? '')));
        if ($summary === '') {
            continue;
        }
        $summaryEscaped = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($summary) : $summary;
        $peopleLabel = stobeRegularMemoryPeopleLabel(strval($row['people'] ?? ''));
        $peoplePrefix = '';
        if ($peopleLabel !== '') {
            $escapedPeople = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($peopleLabel) : $peopleLabel;
            $peoplePrefix = ' (' . $escapedPeople . ')';
        }
        $gametsEnd = intval($row['gamets_end'] ?? 0);
        if ($gametsEnd > 0) {
            $dateLabel = stobeGametsDateLabel($gametsEnd);
            $dateEscaped = function_exists('stobePromptXmlEscape') ? stobePromptXmlEscape($dateLabel) : $dateLabel;
            $lines[] = '- [' . $dateEscaped . ']' . $peoplePrefix . ' ' . $summaryEscaped;
        } else {
            $lines[] = '- ' . ($peoplePrefix !== '' ? ($peoplePrefix . ' ') : '') . $summaryEscaped;
        }
    }
    $lines[] = '</memory>';

    return implode("\n", $lines);
}
