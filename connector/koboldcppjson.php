<?php

/**
 * KoboldCPP JSON connector adapter.
 * Uses Kobold's OpenAI-compatible endpoint when available.
 */

function stobeAdapterKoboldcppjson(array $config): array {
    $runtime = $config;
    $runtime['connector_type'] = 'koboldcppjson';

    $baseUrl = trim(strval($runtime['base_url'] ?? ''));
    if ($baseUrl === '') {
        $baseUrl = 'http://127.0.0.1:5001/v1';
    }
    $baseUrl = preg_replace('#/api/extra/generate/stream/?$#i', '/v1', $baseUrl) ?? $baseUrl;
    $runtime['base_url'] = $baseUrl;

    $runtime['model'] = trim(strval($runtime['model'] ?? ''));
    if ($runtime['model'] === '') {
        $runtime['model'] = 'koboldcpp';
    }
    return $runtime;
}

function stobeCallLLMKoboldcppjson(array $messages, array $config, array $meta = []): string|false {
    return callLLM($messages, stobeAdapterKoboldcppjson($config), $meta);
}

function stobeCallLLMStreamKoboldcppjson(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    return callLLMStream($messages, stobeAdapterKoboldcppjson($config), $onTextDelta, $meta);
}
