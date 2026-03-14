<?php

/**
 * Groq JSON connector adapter.
 */

function stobeAdapterGroqjson(array $config): array {
    $runtime = $config;
    $runtime['connector_type'] = 'groqjson';
    $runtime['base_url'] = trim(strval($runtime['base_url'] ?? ''));
    if ($runtime['base_url'] === '') {
        $runtime['base_url'] = 'https://api.groq.com/openai/v1';
    }
    $runtime['model'] = trim(strval($runtime['model'] ?? ''));
    if ($runtime['model'] === '') {
        $runtime['model'] = 'llama-3.3-70b-versatile';
    }
    return $runtime;
}

function stobeCallLLMGroqjson(array $messages, array $config, array $meta = []): string|false {
    return callLLM($messages, stobeAdapterGroqjson($config), $meta);
}

function stobeCallLLMStreamGroqjson(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    return callLLMStream($messages, stobeAdapterGroqjson($config), $onTextDelta, $meta);
}
