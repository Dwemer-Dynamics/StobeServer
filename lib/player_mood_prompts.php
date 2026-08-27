<?php

// Fixed keys keep client mood selections separate from editable prompt identifiers.
function stobePlayerMoodPromptCatalog(): array {
    return [
        'happy' => 'speaks in a happy tone.',
        'sad' => 'speaks in a sad tone.',
        'angry' => 'speaks in an angry tone.',
        'annoyed' => 'speaks in an annoyed tone.',
        'scared' => 'speaks in a frightened tone.',
        'surprised' => 'speaks in a surprised tone.',
        'confused' => 'speaks in a confused tone.',
        'suspicious' => 'speaks in a suspicious tone.',
        'playful' => 'speaks in a playful tone.',
        'flirty' => 'speaks in a flirtatious tone.',
        'custom' => 'speaks {CUSTOM_MOOD}.',
    ];
}

function stobeNormalizeCustomPlayerMood(mixed $value): string {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
        return '';
    }
    $value = preg_replace('/[\p{C}\s]+/u', ' ', $value) ?? '';
    return trim(mb_substr(trim($value), 0, 80, 'UTF-8'));
}

// Return a non-spoken cue for history and prompts; never modify the literal speech text.
function stobeResolvePlayerMoodCue(array $request, string $mode, string $speaker): string {
    if (!in_array($mode, ['talk', 'whisper', 'shout'], true)) {
        return '';
    }
    $mood = $request['player_mood'] ?? '';
    if (!is_string($mood)) {
        return '';
    }
    $mood = strtolower(trim($mood));
    $catalog = stobePlayerMoodPromptCatalog();
    if (!isset($catalog[$mood])) {
        return '';
    }
    $custom = stobeNormalizeCustomPlayerMood($request['custom_mood'] ?? '');
    if ($mood === 'custom' && $custom === '') {
        return '';
    }
    $prompt = stobeGetPromptTemplateValue('player_mood_' . $mood . '_prompt', $catalog[$mood]);
    $prompt = strtr($prompt, ['{PLAYER_NAME}' => $speaker, '{MOOD}' => $mood, '{CUSTOM_MOOD}' => $custom]);
    // Keep the history marker unambiguous for display and duplicate speech suppression.
    $prompt = preg_replace('/[\p{C}\s]+/u', ' ', strip_tags($prompt)) ?? '';
    $prompt = trim(str_replace(['[', ']'], ['(', ')'], $prompt));
    return $prompt === '' ? '' : ' [Player tone: ' . $prompt . ']';
}
