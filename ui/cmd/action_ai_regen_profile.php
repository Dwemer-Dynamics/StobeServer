<?php
/**
 * AI profile regeneration for Stobe NPCs.
 * Uses the NPC profile response_connector as the sole LLM source.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

$enginePath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'npc_master.class.php');
require_once($enginePath . 'lib' . DIRECTORY_SEPARATOR . 'core' . DIRECTORY_SEPARATOR . 'llm_connector.class.php');

if (!function_exists('stobeAiRegenRespond')) {
    function stobeAiRegenRespond(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('stobeAiNormalizeWhitespace')) {
    function stobeAiNormalizeWhitespace(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace("/\n{3,}/u", "\n\n", $value) ?? $value;
        return trim($value);
    }
}

if (!function_exists('stobeAiValueIsMeaningful')) {
    function stobeAiValueIsMeaningful(string $value): bool
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return false;
        }
        $collapsed = strtolower(preg_replace('/\s+/u', ' ', $trimmed) ?? $trimmed);
        $blocked = [
            'unknown',
            'none',
            'n/a',
            'na',
            'null',
            '(none)',
            'not specified',
            'no data',
            '{}',
            '[]',
        ];
        if (in_array($collapsed, $blocked, true)) {
            return false;
        }
        if (str_starts_with($collapsed, 'no notable ')) {
            return false;
        }
        return true;
    }
}

if (!function_exists('stobeAiDecodeJsonObject')) {
    function stobeAiDecodeJsonObject(string $raw): array
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
}

if (!function_exists('stobeAiParseXmlFallback')) {
    function stobeAiParseXmlFallback(string $raw): array
    {
        $fields = [
            'backstory' => ['backstory', 'npc_static_bio', 'bio'],
            'personality' => ['personality'],
            'occupation' => ['occupation'],
            'speechstyle' => ['speechstyle', 'speech_style'],
            'goals' => ['goals'],
        ];

        $out = [];
        foreach ($fields as $canonical => $tags) {
            foreach ($tags as $tag) {
                if (preg_match('/<' . preg_quote($tag, '/') . '>\s*(.*?)\s*<\/' . preg_quote($tag, '/') . '>/is', $raw, $m) === 1) {
                    $out[$canonical] = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    break;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('stobeAiNormalizeGeneratedFields')) {
    function stobeAiNormalizeGeneratedFields(array $parsed): array
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
            $value = '';
            foreach ($keys as $key) {
                if (!array_key_exists($key, $parsed)) {
                    continue;
                }
                $candidate = is_string($parsed[$key]) ? $parsed[$key] : json_encode($parsed[$key], JSON_UNESCAPED_UNICODE);
                if (!is_string($candidate)) {
                    continue;
                }
                $candidate = stobeAiNormalizeWhitespace($candidate);
                if (!stobeAiValueIsMeaningful($candidate)) {
                    continue;
                }
                $value = $candidate;
                break;
            }
            if ($value === '') {
                continue;
            }
            if (function_exists('sanitizeForKenshi')) {
                $value = sanitizeForKenshi($value);
            }
            if (strlen($value) > 6000) {
                $value = substr($value, 0, 6000);
            }
            $updates[$targetField] = $value;
        }
        return $updates;
    }
}

if (!function_exists('stobeAiParseJsonLikePairsFallback')) {
    function stobeAiParseJsonLikePairsFallback(string $raw): array
    {
        $text = trim($raw);
        if ($text === '') {
            return [];
        }
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $text, $fenced) === 1) {
            $text = trim($fenced[1]);
        }

        $keys = [
            'npc_static_bio',
            'personality',
            'occupation',
            'speechstyle',
            'speech_style',
            'goals',
            'backstory',
        ];

        $out = [];
        foreach ($keys as $key) {
            $pattern = '/"' . preg_quote($key, '/') . '"\s*:\s*"((?:\\\\.|[^"\\\\])*)"/s';
            if (preg_match($pattern, $text, $m) !== 1) {
                continue;
            }
            $decoded = json_decode('"' . $m[1] . '"', true);
            $value = is_string($decoded) ? $decoded : stripcslashes($m[1]);
            $value = trim($value);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}

if (!function_exists('stobeAiParseLabelFallback')) {
    function stobeAiParseLabelFallback(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($raw));
        if ($text === '') {
            return [];
        }

        $labelMap = [
            'npc_static_bio' => ['npc static bio', 'backstory', 'bio'],
            'personality' => ['personality'],
            'occupation' => ['occupation'],
            'speechstyle' => ['speechstyle', 'speech style'],
            'goals' => ['goals'],
        ];

        $out = [];
        foreach ($labelMap as $canonical => $labels) {
            foreach ($labels as $label) {
                $pattern = '/^' . preg_quote($label, '/') . '\s*:\s*(.+)$/im';
                if (preg_match($pattern, $text, $m) !== 1) {
                    continue;
                }
                $value = trim($m[1]);
                if ($value !== '') {
                    $out[$canonical] = $value;
                    break;
                }
            }
        }
        return $out;
    }
}

if (!function_exists('stobeAiBuildRecentContext')) {
    function stobeAiBuildRecentContext(array $rows): string
    {
        if (count($rows) === 0) {
            return '(none)';
        }
        $lines = [];
        foreach (array_reverse($rows) as $row) {
            $lineType = strtoupper(trim(strval($row['type'] ?? 'event')));
            $data = trim(strval($row['data'] ?? ''));
            if ($data === '') {
                continue;
            }
            $gamets = intval($row['gamets'] ?? 0);
            $timeLabel = $gamets > 0 && function_exists('stobeGametsDateLabel') ? stobeGametsDateLabel($gamets) : '';
            $prefix = '[' . $lineType;
            if ($timeLabel !== '') {
                $prefix .= ' @ ' . $timeLabel;
            }
            $prefix .= ']';
            $line = $prefix . ' ' . preg_replace('/\s+/u', ' ', $data);
            if (!is_string($line)) {
                continue;
            }
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
}

$name = trim(strval($_REQUEST['name'] ?? ''));
if ($name === '') {
    stobeAiRegenRespond(['done' => false, 'error' => 'NPC name is required.']);
}
$userPrompt = trim(strval($_REQUEST['user_prompt'] ?? ''));

try {
    $npcMaster = new NpcMaster();
    $npcData = $npcMaster->getByName($name);
    if (!$npcData) {
        stobeAiRegenRespond(['done' => false, 'error' => 'NPC not found.']);
    }

    $npcId = intval($npcData['id'] ?? 0);
    if ($npcId <= 0) {
        stobeAiRegenRespond(['done' => false, 'error' => 'NPC id is invalid.']);
    }

    $connectorRow = getProfileLlmConnectorForNpcByPurpose($npcData, 'response');
    $llmConfig = getLlmConfigForNpcPurpose($npcData, 'response');
    $apiKey = trim(strval($llmConfig['api_key'] ?? ''));
    if ($apiKey === '') {
        stobeAiRegenRespond(['done' => false, 'error' => 'Response connector API key is missing for this NPC profile.']);
    }

    $db = $GLOBALS['db'];
    $historyRows = $db->fetchAll(
        "SELECT type, data, gamets
         FROM eventlog
         WHERE data ILIKE $1
         ORDER BY id DESC
         LIMIT 24",
        ['%' . $name . ':%']
    );
    $recentContext = stobeAiBuildRecentContext(is_array($historyRows) ? $historyRows : []);

    $currentState = [
        'backstory' => trim(strval($npcData['backstory'] ?? '')),
        'personality' => trim(strval($npcData['personality'] ?? '')),
        'occupation' => trim(strval($npcData['occupation'] ?? '')),
        'speechstyle' => trim(strval($npcData['speechstyle'] ?? '')),
        'goals' => trim(strval($npcData['goals'] ?? '')),
    ];

    $systemPrompt = implode("\n", [
        'You generate Kenshi NPC profile fields.',
        'Return STRICT JSON only (no markdown, no prose) with these keys:',
        '{"backstory":"","personality":"","occupation":"","speechstyle":"","goals":""}',
        'Write grounded in-world roleplay text.',
        'Keep each field concise but specific.',
        'If uncertain, keep existing intent and avoid placeholders like "unknown".',
    ]);

    $userSections = [];
    $userSections[] = '<npc_name>' . $name . '</npc_name>';
    $userSections[] = '<race>' . trim(strval($npcData['race'] ?? 'Unknown')) . '</race>';
    $userSections[] = '<faction>' . trim(strval($npcData['faction'] ?? 'Unknown')) . '</faction>';
    $userSections[] = '<current_profile_fields>';
    foreach ($currentState as $key => $value) {
        $safeValue = $value === '' ? '(empty)' : $value;
        $userSections[] = '  <' . $key . '>' . stobePromptXmlEscape($safeValue) . '</' . $key . '>';
    }
    $userSections[] = '</current_profile_fields>';
    $userSections[] = '<recent_context>';
    $userSections[] = stobePromptXmlEscape($recentContext);
    $userSections[] = '</recent_context>';
    if ($userPrompt !== '') {
        $userSections[] = '<user_instructions>' . stobePromptXmlEscape($userPrompt) . '</user_instructions>';
    }

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => implode("\n", $userSections)],
    ];

    // Profile generation needs more output budget than many runtime dialogue turns.
    $llmConfigForGeneration = $llmConfig;
    $configuredMax = intval($llmConfigForGeneration['max_tokens'] ?? 0);
    if ($configuredMax < 900) {
        $llmConfigForGeneration['max_tokens'] = 900;
    }

    $rawResponse = stobeCallLLM($messages, $llmConfigForGeneration, [
        'npc_name' => $name,
        'event_type' => 'profile_generation',
        'response_format' => ['type' => 'json_object'],
    ]);

    if ($rawResponse === false || trim(strval($rawResponse)) === '') {
        stobeAiRegenRespond(['done' => false, 'error' => 'LLM request failed. Check connector/API key and server logs.']);
    }

    $parsed = stobeAiDecodeJsonObject(strval($rawResponse));
    if (count($parsed) === 0) {
        $parsed = stobeAiParseXmlFallback(strval($rawResponse));
    }
    if (count($parsed) === 0) {
        $parsed = stobeAiParseJsonLikePairsFallback(strval($rawResponse));
    }
    if (count($parsed) === 0) {
        $parsed = stobeAiParseLabelFallback(strval($rawResponse));
    }
    if (count($parsed) === 0) {
        stobeLogWarn('AI profile generation parse failed', [
            'npc_name' => $name,
            'response_preview' => substr(strval($rawResponse), 0, 700),
        ]);
        stobeAiRegenRespond(['done' => false, 'error' => 'Could not parse AI output into profile fields.']);
    }

    $updates = stobeAiNormalizeGeneratedFields($parsed);
    if (count($updates) === 0) {
        stobeAiRegenRespond(['done' => false, 'error' => 'AI output did not contain usable profile updates.']);
    }

    updateNpcById($npcId, $updates);

    $connectorLabel = trim(strval($connectorRow['name'] ?? ''));
    if ($connectorLabel === '') {
        $connectorLabel = 'Profile response connector';
    }
    $model = trim(strval($llmConfig['model'] ?? ($connectorRow['model'] ?? '')));

    stobeLogInfo('AI profile generated', [
        'npc_name' => $name,
        'npc_id' => $npcId,
        'fields_updated' => array_keys($updates),
        'connector' => $connectorLabel,
        'model' => $model,
    ]);

    stobeAiRegenRespond([
        'done' => true,
        'npc_name' => $name,
        'fields_updated' => count($updates),
        'updated_fields' => array_keys($updates),
        'connector' => $connectorLabel,
        'model' => $model,
    ]);
} catch (Throwable $e) {
    stobeLogException($e, 'AI profile generation failed', ['npc_name' => $name]);
    stobeAiRegenRespond([
        'done' => false,
        'error' => 'AI profile generation failed: ' . $e->getMessage(),
    ]);
}

