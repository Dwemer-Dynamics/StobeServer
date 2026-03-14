<?php

/**
 * Google OpenAI-compatible JSON connector adapter.
 */

function stobeAdapterGoogleOpenaijson(array $config): array {
    $runtime = $config;
    $runtime['connector_type'] = 'google_openaijson';
    $runtime['base_url'] = trim(strval($runtime['base_url'] ?? ''));
    if ($runtime['base_url'] === '') {
        $runtime['base_url'] = 'https://generativelanguage.googleapis.com/v1beta/openai';
    }
    $runtime['model'] = trim(strval($runtime['model'] ?? ''));
    if ($runtime['model'] === '') {
        $runtime['model'] = 'gemini-2.5-flash';
    }
    return $runtime;
}

function stobeCallLLMGoogleOpenaijson(array $messages, array $config, array $meta = []): string|false {
    return callLLM($messages, stobeAdapterGoogleOpenaijson($config), $meta);
}

function stobeCallLLMStreamGoogleOpenaijson(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    return callLLMStream($messages, stobeAdapterGoogleOpenaijson($config), $onTextDelta, $meta);
}
