<?php

/**
 * Diary helper functions for StobeServer.
 * Profile-driven diary generation with per-NPC cooldown.
 */

$stobeBasePath = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require_once($stobeBasePath . 'lib' . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');

function stobeBuildDiaryCooldownKey(string $npcName): string {
    $normalized = strtolower(trim($npcName));
    $normalized = preg_replace('/[^a-z0-9_]+/i', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if ($normalized === '') {
        $normalized = 'unknown';
    }
    return 'DIARY_LAST_TIMESTAMP_' . $normalized;
}

function stobeResolveKenshiDayFromGamets(int $gamets): int {
    $normalizedGamets = stobeGametsNormalize($gamets);
    if ($normalizedGamets <= 0) {
        return -1;
    }
    $parts = stobeGametsToDateParts($normalizedGamets);
    return intval($parts['day_index'] ?? -1);
}

function stobeBuildKenshiDateFromGamets(int $gamets): array {
    return stobeGametsToDateParts($gamets);
}

function stobeApplyKenshiDiaryHeader(string $diaryContent, array $kenshiDate): string {
    $header = 'Day ' . strval(intval($kenshiDate['day_number'] ?? 1)) . ', '
        . strval($kenshiDate['time_label'] ?? 'Midday') . '.';
    $content = trim($diaryContent);
    if ($content === '') {
        return $header;
    }

    $parts = preg_split('/\R+/', $content, 2);
    $firstLine = trim(strval($parts[0] ?? ''));
    $rest = isset($parts[1]) ? trim(strval($parts[1])) : '';
    $looksLikeHeader = preg_match('/^Day\s+\d+\s*,/i', $firstLine) === 1
        || preg_match('/^Day\s+\d+\b/i', $firstLine) === 1;

    if ($looksLikeHeader) {
        if ($rest === '') {
            return $header;
        }
        return $header . "\n\n" . $rest;
    }

    return $header . "\n\n" . $content;
}

function stobeGetLastDiaryGametsForNpc(string $npcName): int {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return 0;
    }

    $db = $GLOBALS["db"];
    $row = $db->fetchOne(
        "SELECT gamets
         FROM diarylog
         WHERE LOWER(people) = LOWER($1)
         ORDER BY gamets DESC, localts DESC, rowid DESC
         LIMIT 1",
        [$safeNpcName]
    );

    return intval($row['gamets'] ?? 0);
}

function stobeResolveDiaryLocationText(string $npcName): string {
    $geo = ['location' => '', 'city' => '', 'region' => ''];
    $geo = mergeEventGeoContext($geo, getEventGeoFromNpcName($npcName));
    $geo = mergeEventGeoContext($geo, getEventGeoFromPlayerSnapshot());
    $geo = mergeEventGeoContext($geo, getRecentEventGeoFallback($npcName, 1800));
    return composeEventLocationText($geo);
}

function stobeBuildDiaryHistoryText(string $npcName, int $historyLimit): string {
    $rows = DataEventLog($historyLimit, $npcName);
    $rows = stobeFilterNarratorRowsForContext($rows, $npcName, 'diary');
    if (count($rows) === 0) {
        return '';
    }

    $historyLines = [];
    foreach (array_reverse($rows) as $row) {
        $line = stobeFormatEventHistoryLine($row, true);
        if ($line === '') {
            continue;
        }
        $historyLines[] = $line;
    }
    return implode("\n", $historyLines);
}

function stobeGenerateDiaryEntryForNpc(
    string $npcName,
    int $timestamp,
    int $gamets,
    string $triggerType = 'diary',
    bool $respectAutoFlags = false,
    bool $enforceDiaryDays = false,
    bool $skipCooldown = false
): array {
    $safeNpcName = normalizeParticipantNameToken($npcName);
    if ($safeNpcName === '') {
        return ['ok' => false, 'reason' => 'missing_npc_name'];
    }

    $npcData = getNpcData($safeNpcName);
    if (!$npcData) {
        storeNpcProfile($safeNpcName, []);
        $npcData = getNpcData($safeNpcName);
    }
    if (!$npcData) {
        return ['ok' => false, 'reason' => 'npc_profile_missing', 'npc_name' => $safeNpcName];
    }

    $autoDiaryEnabled = getNpcProfileBoolSetting(
        $npcData,
        ['AUTO_DIARY_ENABLED'],
        'AUTO_DIARY_ENABLED',
        false
    );
    if ($respectAutoFlags && !$autoDiaryEnabled) {
        return ['ok' => false, 'reason' => 'auto_diary_disabled', 'npc_name' => $safeNpcName];
    }

    if ($enforceDiaryDays) {
        $diaryDays = getNpcProfileIntegerSetting(
            $npcData,
            ['DIARY_DAYS'],
            'DIARY_DAYS',
            1,
            1,
            365
        );
        $currentDay = stobeResolveKenshiDayFromGamets($gamets);
        if ($currentDay < 0) {
            return ['ok' => false, 'reason' => 'missing_gamets', 'npc_name' => $safeNpcName];
        }

        $lastDiaryGamets = stobeGetLastDiaryGametsForNpc($safeNpcName);
        if ($lastDiaryGamets > 0) {
            $lastDay = stobeResolveKenshiDayFromGamets($lastDiaryGamets);
            if ($lastDay >= 0) {
                $daysSinceLastDiary = $currentDay - $lastDay;
                if ($daysSinceLastDiary < $diaryDays) {
                    return [
                        'ok' => false,
                        'reason' => 'diary_days_not_elapsed',
                        'npc_name' => $safeNpcName,
                        'current_day' => $currentDay,
                        'last_day' => $lastDay,
                        'required_days' => $diaryDays,
                    ];
                }
            }
        }
    }

    $cooldownKey = stobeBuildDiaryCooldownKey($safeNpcName);
    $nowTs = time();
    if (!$skipCooldown) {
        $cooldownSeconds = getNpcProfileIntegerSetting(
            $npcData,
            ['DIARY_COOLDOWN'],
            'DIARY_COOLDOWN',
            120,
            10,
            3600
        );
        $lastDiaryTs = intval(getConfOpt($cooldownKey, '0'));
        if ($lastDiaryTs > 0) {
            $elapsed = $nowTs - $lastDiaryTs;
            if ($elapsed >= 0 && $elapsed < $cooldownSeconds) {
                return [
                    'ok' => false,
                    'reason' => 'cooldown_active',
                    'npc_name' => $safeNpcName,
                    'remaining_seconds' => $cooldownSeconds - $elapsed,
                ];
            }
        }
    }

    $historyLimit = getNpcProfileIntegerSetting(
        $npcData,
        ['CONTEXT_HISTORY_DIARY'],
        '',
        100,
        0,
        400
    );
    if ($historyLimit <= 0) {
        $historyLimit = getNpcProfileIntegerSetting(
            $npcData,
            ['CONTEXT_HISTORY'],
            '',
            75,
            10,
            400
        );
    }

    $historyText = stobeBuildDiaryHistoryText($safeNpcName, $historyLimit);
    $kenshiDate = stobeBuildKenshiDateFromGamets($gamets);
    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    if ($playerName === '') {
        $playerName = 'Drifter';
    }

    $systemPrompt = stobeBuildGameTimePromptBlock($gamets)
        . "\n\n"
        . buildSystemPrompt($safeNpcName, $npcData, $playerName, '', false, 'chat', intval($gamets));
    $defaultDiaryModeRules = "<diary_mode>\n"
        . "  <rule>Write as #NPC_NAME# in first person.</rule>\n"
        . "  <rule>Focus on meaningful events, emotions, and observations.</rule>\n"
        . "  <rule>Keep a concise diary tone and avoid action tags.</rule>\n"
        . "  <rule>Use Kenshi timeline only, never real-world calendar dates.</rule>\n"
        . "  <current_ingame_datetime>#CURRENT_INGAME_DATETIME#</current_ingame_datetime>\n"
        . "  <rule>Output plain diary text only.</rule>\n"
        . "</diary_mode>";
    $diaryModeRulesTemplate = function_exists('stobeGetPromptTemplateValue')
        ? stobeGetPromptTemplateValue('diary_mode_rules', $defaultDiaryModeRules)
        : $defaultDiaryModeRules;
    $diaryModeRules = strtr($diaryModeRulesTemplate, [
        '#NPC_NAME#' => stobePromptXmlEscape($safeNpcName),
        '#CURRENT_INGAME_DATETIME#' => stobePromptXmlEscape(strval($kenshiDate['date_label'] ?? '')),
    ]);
    $systemPrompt .= "\n\n" . $diaryModeRules;

    $defaultDiaryPrompt = "Please write a short summary of #PLAYER_NAME# and #NPC_NAME#'s last dialogues and events written above into #NPC_NAME#'s diary. WRITE AS IF YOU WERE #NPC_NAME#. Start the diary entry with exactly this header: \"#KENSHI_DIARY_HEADER#\".";
    $diaryPromptTemplate = getNpcProfileStringSetting(
        $npcData,
        ['DIARY_PROMPT'],
        'DIARY_PROMPT',
        $defaultDiaryPrompt
    );
    $diaryPrompt = strtr($diaryPromptTemplate, [
        '#PLAYER_NAME#' => $playerName,
        '#NPC_NAME#' => $safeNpcName,
        '#HERIKA_NAME#' => $safeNpcName,
        '#KENSHI_DAY#' => strval(intval($kenshiDate['day_number'] ?? 1)),
        '#KENSHI_TIME_LABEL#' => strval($kenshiDate['time_label'] ?? 'Midday'),
        '#KENSHI_TIME_24#' => strval($kenshiDate['clock_24'] ?? '00:00'),
        '#KENSHI_TIME_12#' => strval($kenshiDate['clock_12'] ?? '12:00 AM'),
        '#KENSHI_DATE_LABEL#' => strval($kenshiDate['date_label'] ?? 'Day 1, Midday (00:00)'),
        '#KENSHI_DIARY_HEADER#' => 'Day ' . strval(intval($kenshiDate['day_number'] ?? 1))
            . ', ' . strval($kenshiDate['time_label'] ?? 'Midday') . '.',
    ]);

    $messages = [
        [
            'role' => 'system',
            'content' => $systemPrompt,
        ],
    ];
    if ($historyText !== '') {
        $messages[] = [
            'role' => 'user',
            'content' => stobeBuildRecentContextPromptBlock($historyText, 'recent_context'),
        ];
    }
    $messages[] = [
        'role' => 'user',
        'content' => "<diary_request>\n"
            . "  <instruction>" . stobePromptXmlEscape($diaryPrompt) . "</instruction>\n"
            . "</diary_request>",
    ];

    $enginePath = $GLOBALS["ENGINE_PATH"] ?? dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR;
    require_once($enginePath . 'connector/llm_dispatcher.php');

    $llmConfig = getLlmConfigForNpcPurpose($npcData, 'diary');
    if (trim(strval($llmConfig['api_key'] ?? '')) === '') {
        return ['ok' => false, 'reason' => 'missing_api_key', 'npc_name' => $safeNpcName];
    }

    $rawResponse = stobeCallLLM($messages, $llmConfig, [
        'npc_name' => $safeNpcName,
        'event_type' => 'diary',
        'trigger_type' => strtolower(trim($triggerType)),
    ]);
    if ($rawResponse === false) {
        return ['ok' => false, 'reason' => 'llm_failed', 'npc_name' => $safeNpcName];
    }

    $diaryContent = trim(strval($rawResponse));
    if ($diaryContent === '') {
        return ['ok' => false, 'reason' => 'llm_empty', 'npc_name' => $safeNpcName];
    }
    $diaryContent = stobeApplyKenshiDiaryHeader($diaryContent, $kenshiDate);

    $location = stobeResolveDiaryLocationText($safeNpcName);
    $tags = 'diary,' . strtolower(trim($triggerType));
    $topic = 'Diary Entry';
    $db = $GLOBALS["db"];
    $db->exec(
        "INSERT INTO diarylog (ts, sess, topic, content, tags, people, localts, location, gamets)
         VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)",
        [
            strval($timestamp),
            'diary_' . strval($nowTs),
            $topic,
            $diaryContent,
            $tags,
            $safeNpcName,
            $nowTs,
            $location,
            $gamets,
        ]
    );

    setConfOpt($cooldownKey, strval($nowTs));

    stobeLogInfo('Diary entry generated', [
        'npc_name' => $safeNpcName,
        'trigger_type' => strtolower(trim($triggerType)),
        'gamets' => intval($gamets),
        'kenshi_date' => strval($kenshiDate['date_label'] ?? ''),
        'history_limit' => $historyLimit,
        'content_length' => strlen($diaryContent),
    ]);

    return [
        'ok' => true,
        'npc_name' => $safeNpcName,
        'content_length' => strlen($diaryContent),
        'history_limit' => $historyLimit,
    ];
}

function stobeMaybeTriggerAutoDiaryOnNewDay(
    string $eventType,
    int $timestamp,
    int $gamets,
    string $eventData
): void {
    $normalizedType = strtolower(trim($eventType));
    if ($normalizedType === 'diary' || $normalizedType === 'diary_nearby') {
        return;
    }
    if ($gamets <= 0) {
        return;
    }

    $currentDay = stobeResolveKenshiDayFromGamets($gamets);
    if ($currentDay < 0) {
        return;
    }

    $dayKey = 'AUTO_DIARY_LAST_GAMEDAY';
    $lastProcessedDay = intval(getConfOpt($dayKey, '-1'));
    if ($lastProcessedDay === $currentDay) {
        return;
    }

    $candidates = stobeExtractDiaryCandidates($normalizedType, $eventData);
    if (count($candidates) === 0) {
        return;
    }

    $attempted = 0;
    $generated = 0;
    $skipped = 0;
    $failed = 0;
    $results = [];

    foreach ($candidates as $candidateName) {
        $attempted++;
        $result = stobeGenerateDiaryEntryForNpc(
            strval($candidateName),
            intval($timestamp),
            intval($gamets),
            'auto_diary_day',
            true,
            true,
            true
        );
        $results[] = $result;
        if (boolval($result['ok'] ?? false)) {
            $generated++;
            continue;
        }

        $reason = strtolower(trim(strval($result['reason'] ?? 'unknown')));
        if (
            $reason === 'auto_diary_disabled' ||
            $reason === 'diary_days_not_elapsed' ||
            $reason === 'cooldown_active' ||
            $reason === 'missing_gamets'
        ) {
            $skipped++;
        } else {
            $failed++;
        }
    }

    if ($attempted > 0) {
        setConfOpt($dayKey, strval($currentDay), true);
    }

    stobeLogInfo('Auto diary day check processed', [
        'event_type' => $normalizedType,
        'current_day' => $currentDay,
        'last_processed_day' => $lastProcessedDay,
        'attempted' => $attempted,
        'generated' => $generated,
        'skipped' => $skipped,
        'failed' => $failed,
        'results' => $results,
    ]);
}

function stobeExtractDiaryCandidates(string $eventType, string $eventData): array {
    $profileName = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
    $dialogueData = parseDialogueEventData($eventData);
    $speaker = normalizeParticipantNameToken(strval($dialogueData['speaker'] ?? ''));
    $target = normalizeParticipantNameToken(strval($dialogueData['target'] ?? ''));

    $participants = extractParticipantNames([
        'people' => strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? '')),
        'profile' => $profileName,
        'speaker' => $speaker,
        'npcs' => [$target],
    ]);

    $eventNpc = normalizeParticipantNameToken($eventData);
    if (
        $eventNpc !== '' &&
        strpos($eventNpc, ':') === false &&
        strpos($eventNpc, '{') === false &&
        strpos($eventNpc, '[') !== 0
    ) {
        $participants[] = $eventNpc;
    }

    $playerName = normalizeParticipantNameToken(getSetting('PLAYER_NAME', 'Drifter'));
    $namesByKey = [];
    foreach ($participants as $participant) {
        $name = normalizeParticipantNameToken($participant);
        if ($name === '') {
            continue;
        }
        if ($playerName !== '' && strcasecmp($name, $playerName) === 0) {
            continue;
        }
        $key = strtolower($name);
        if (!isset($namesByKey[$key])) {
            $namesByKey[$key] = $name;
        }
    }

    if (count($namesByKey) === 0 && $profileName !== '') {
        $namesByKey[strtolower($profileName)] = $profileName;
    }

    return array_values($namesByKey);
}
