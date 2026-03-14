<?php

/**
 * OpenRouter JSON connector adapter.
 */

function stobeAdapterOpenrouterjson(array $config): array {
    $runtime = $config;
    $runtime['connector_type'] = 'openrouterjson';
    $runtime['base_url'] = trim(strval($runtime['base_url'] ?? ''));
    if ($runtime['base_url'] === '') {
        $runtime['base_url'] = 'https://openrouter.ai/api/v1';
    }
    $runtime['model'] = trim(strval($runtime['model'] ?? ''));
    if ($runtime['model'] === '') {
        $runtime['model'] = 'openrouter/auto';
    }
    return $runtime;
}

function stobeCallLLMOpenrouterjson(array $messages, array $config, array $meta = []): string|false {
    return callLLM($messages, stobeAdapterOpenrouterjson($config), $meta);
}

function stobeCallLLMStreamOpenrouterjson(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    return callLLMStream($messages, stobeAdapterOpenrouterjson($config), $onTextDelta, $meta);
}
