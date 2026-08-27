<?php

require_once __DIR__ . '/prompt_formatting.php';

/**
 * Compact Markdown formatting for recent NPC chat history.
 *
 * Current-turn prompts, retrieved memories, and response schemas remain
 * separate so enabling this setting only changes chronological history.
 */

function stobeCompactHistoryWhitespace(string $text): string
{
    return trim(strval(preg_replace('/\s+/u', ' ', $text)));
}

function stobeShouldCompactChatHistory(string $actorName): bool
{
    $actorName = trim($actorName);
    if ($actorName === '') {
        return false;
    }
    if (
        (function_exists('stobeIsNarratorName') && stobeIsNarratorName($actorName))
        || strcasecmp($actorName, 'The Narrator') === 0
    ) {
        return false;
    }
    return function_exists('getSettingBool')
        && getSettingBool('COMPACT_CHAT_HISTORY_ENABLED', false);
}

function stobeCompactHistoryDialogue(string $content, string $fallbackSpeaker = ''): string
{
    $content = trim($content);
    $speaker = stobeCompactHistoryWhitespace($fallbackSpeaker);
    $listener = '';
    $delivery = 'speaking';

    if (
        preg_match(
            '/\s*\((talking|whispering|shouting)\s+to:?\s*([^\)]+)\)\s*\.?\s*$/iu',
            $content,
            $targetMatch
        ) === 1
    ) {
        $deliveryToken = strtolower(stobeCompactHistoryWhitespace(strval($targetMatch[1] ?? 'talking')));
        $delivery = match ($deliveryToken) {
            'whispering' => 'whispering',
            'shouting' => 'shouting',
            default => 'speaking',
        };
        $listener = stobeCompactHistoryWhitespace(strval($targetMatch[2] ?? ''));
        $content = trim(strval(preg_replace(
            '/\s*\((?:talking|whispering|shouting)\s+to:?\s*[^\)]+\)\s*\.?\s*$/iu',
            '',
            $content
        )));
    }

    if (preg_match('/^([^:\r\n]{1,100}):\s*(.+)$/us', $content, $speakerMatch) === 1) {
        $speaker = stobeCompactHistoryWhitespace(strval($speakerMatch[1] ?? $speaker));
        $content = strval($speakerMatch[2] ?? '');
    }

    $content = stobeCompactHistoryWhitespace($content);
    if ($speaker === '') {
        return $content;
    }
    if ($listener !== '') {
        return "{$speaker}, {$delivery} to {$listener}: {$content}";
    }
    return "{$speaker}: {$content}";
}

function stobeCompactAssistantHistoryEntry(string $content, string $actorName): string
{
    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return stobeCompactHistoryDialogue($content, $actorName);
    }

    $speaker = stobeCompactHistoryWhitespace(strval($decoded['character'] ?? $actorName));
    $listener = stobeCompactHistoryWhitespace(strval($decoded['listener'] ?? ''));
    $message = stobeCompactHistoryWhitespace(strval($decoded['message'] ?? ''));
    $action = stobeCompactHistoryWhitespace(strval($decoded['action'] ?? ''));
    $target = stobeCompactHistoryWhitespace(strval($decoded['target'] ?? ''));

    $line = $listener !== '' ? "{$speaker}, speaking to {$listener}" : $speaker;
    if ($message !== '') {
        $line .= ': ' . $message;
    }
    if ($action !== '' && strcasecmp($action, 'Talk') !== 0 && strcasecmp($action, 'JustTalk') !== 0) {
        $line .= ' [Action: ' . $action . ($target !== '' ? ", targeting {$target}" : '') . ']';
    }
    return trim($line);
}

function stobeCompactUserHistoryEntry(string $content): string
{
    $content = trim($content);
    if (preg_match('/^\(\.\.\.\s*(.*?)\s*\.\.\.\)$/us', $content, $ambientMatch) === 1) {
        $content = trim(strval($ambientMatch[1] ?? ''));
    }

    if (
        preg_match(
            '/\((?:talking|whispering|shouting)\s+to:?\s*[^\)]+\)\s*\.?\s*$/iu',
            $content
        ) === 1
    ) {
        return stobeCompactHistoryDialogue($content);
    }

    return stobeCompactHistoryWhitespace($content);
}

function stobeCompactToolHistoryEntry(array $entry): string
{
    $content = $entry['content'] ?? '';
    if (is_string($content) || is_scalar($content)) {
        $content = stobeCompactHistoryWhitespace(strval($content));
        if ($content !== '') {
            return 'Tool result: ' . $content;
        }
    }

    $toolCalls = $entry['tool_calls'] ?? [];
    if (!is_array($toolCalls)) {
        return '';
    }

    $calls = [];
    foreach ($toolCalls as $toolCall) {
        if (!is_array($toolCall) || !is_array($toolCall['function'] ?? null)) {
            continue;
        }
        $name = stobeCompactHistoryWhitespace(strval($toolCall['function']['name'] ?? ''));
        if ($name !== '') {
            $calls[] = $name;
        }
    }
    return count($calls) > 0 ? 'Requested action: ' . implode(', ', $calls) . '.' : '';
}

function stobeFormatCompactChatHistory(array $historyMessages, string $actorName): string
{
    $lines = [];
    foreach ($historyMessages as $message) {
        if (!is_array($message)) {
            continue;
        }

        $role = strtolower(trim(strval($message['role'] ?? '')));
        if ($role === 'assistant') {
            $line = isset($message['tool_calls'])
                ? stobeCompactToolHistoryEntry($message)
                : stobeCompactAssistantHistoryEntry(strval($message['content'] ?? ''), $actorName);
        } elseif ($role === 'user') {
            $line = stobeCompactUserHistoryEntry(strval($message['content'] ?? ''));
        } elseif ($role === 'tool') {
            $line = stobeCompactToolHistoryEntry($message);
        } else {
            $line = stobeCompactHistoryWhitespace(strval($message['content'] ?? ''));
        }

        if ($line !== '') {
            $lines[] = '# ' . $line;
        }
    }
    return implode("\n", $lines);
}

function stobeApplyCompactChatHistory(
    string $systemPrompt,
    array $historyMessages,
    string $actorName,
    bool $enabled,
    bool $markdownEnabled = false
): array {
    $systemPrompt = stobeFormatPromptHeadSection($systemPrompt, $markdownEnabled);
    if (!$enabled) {
        return [
            'system_prompt' => $systemPrompt,
            'history_messages' => $historyMessages,
        ];
    }

    $historyBlock = stobeFormatCompactChatHistory($historyMessages, $actorName);
    if ($historyBlock === '') {
        return [
            'system_prompt' => $systemPrompt,
            'history_messages' => [],
        ];
    }

    if ($markdownEnabled) {
        $historyBlock = "# Conversation History\n\n" . preg_replace('/^# /m', '- ', $historyBlock);
    }

    return [
        'system_prompt' => rtrim($systemPrompt) . "\n\n" . $historyBlock,
        'history_messages' => [],
    ];
}
