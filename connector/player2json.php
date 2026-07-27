<?php

/**
 * Player2 JSON connector adapter.
 * Uses OpenAI-compatible endpoint plus player2-game-key header.
 */

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'player2_config.php';

function stobePlayer2CanConnect(string $host, int $port): bool {
    if ($host === '' || $port <= 0) {
        return false;
    }
    $socket = @fsockopen($host, $port, $errno, $errstr, 0.2);
    if ($socket !== false) {
        fclose($socket);
        return true;
    }
    return false;
}

function stobePlayer2DetectWslGatewayIp(): string {
    $routeFile = '/proc/net/route';
    if (!is_readable($routeFile)) {
        return '';
    }

    $lines = @file($routeFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) < 2) {
        return '';
    }

    foreach ($lines as $index => $line) {
        if ($index === 0) {
            continue; // header
        }
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 3) {
            continue;
        }

        $destination = strtoupper(strval($parts[1] ?? ''));
        $gatewayHex = strtoupper(strval($parts[2] ?? ''));
        if ($destination !== '00000000' || !preg_match('/^[0-9A-F]{8}$/', $gatewayHex)) {
            continue;
        }

        // Gateway is stored little-endian in /proc/net/route.
        $octets = array_reverse(str_split($gatewayHex, 2));
        $gatewayIp = implode('.', array_map(static fn($hex): int => hexdec($hex), $octets));
        if (filter_var($gatewayIp, FILTER_VALIDATE_IP) !== false) {
            return $gatewayIp;
        }
    }

    return '';
}

function stobePlayer2ApplyHostOverride(string $baseUrl): string {
    $parts = @parse_url($baseUrl);
    if (!is_array($parts)) {
        return $baseUrl;
    }

    $host = strtolower(trim(strval($parts['host'] ?? '')));
    if ($host !== '127.0.0.1' && $host !== 'localhost') {
        return $baseUrl;
    }

    $hostIp = '';
    if (function_exists('getConfOpt')) {
        $hostIp = trim(getConfOpt('Network/HOST_IP', ''));
    }
    if ($hostIp === '') {
        $hostIp = stobePlayer2DetectWslGatewayIp();
    }
    if (filter_var($hostIp, FILTER_VALIDATE_IP) === false) {
        return $baseUrl;
    }

    $scheme = strtolower(trim(strval($parts['scheme'] ?? 'http')));
    if ($scheme !== 'https') {
        $scheme = 'http';
    }
    $port = intval($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port <= 0) {
        return $baseUrl;
    }

    // Only override when replacement host actually accepts connections.
    if (!stobePlayer2CanConnect($hostIp, $port)) {
        return $baseUrl;
    }

    $path = strval($parts['path'] ?? '');
    $query = strval($parts['query'] ?? '');
    $fragment = strval($parts['fragment'] ?? '');

    $rebuilt = $scheme . '://' . $hostIp;
    if (!(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
        $rebuilt .= ':' . $port;
    }
    if ($path !== '') {
        $rebuilt .= $path;
    }
    if ($query !== '') {
        $rebuilt .= '?' . $query;
    }
    if ($fragment !== '') {
        $rebuilt .= '#' . $fragment;
    }

    return $rebuilt;
}

function stobeAdapterPlayer2json(array $config): array {
    $runtime = $config;
    $runtime['connector_type'] = 'player2json';
    $baseUrl = trim(strval($runtime['base_url'] ?? ''));

    if ($baseUrl === '') {
        $baseUrl = 'http://127.0.0.1:4315/v1';
    } elseif (preg_match('#/docs/?$#i', $baseUrl) === 1) {
        $baseUrl = preg_replace('#/docs/?$#i', '/v1', $baseUrl) ?? $baseUrl;
    } elseif (preg_match('#^https?://[^/]+/?$#i', $baseUrl) === 1) {
        $baseUrl = rtrim($baseUrl, '/') . '/v1';
    }
    $runtime['base_url'] = stobePlayer2ApplyHostOverride($baseUrl);

    $runtime['model'] = trim(strval($runtime['model'] ?? ''));
    if ($runtime['model'] === '') {
        $runtime['model'] = 'openrouter/auto';
    }

    $connectorConfig = $runtime['config'] ?? [];
    if (!is_array($connectorConfig)) {
        $decoded = json_decode(strval($connectorConfig), true);
        $connectorConfig = is_array($decoded) ? $decoded : [];
    }

    $gameKey = trim(strval($connectorConfig['player2_game_key'] ?? $connectorConfig['game_key'] ?? ''));
    if ($gameKey === '') {
        $gameKey = trim(strval($runtime['api_key'] ?? ''));
    }
    if ($gameKey === '' || strcasecmp($gameKey, 'STOBE') === 0) {
        $gameKey = STOBE_PLAYER2_GAME_CLIENT_ID;
    }
    $connectorConfig['player2_game_key'] = $gameKey;
    $runtime['config'] = $connectorConfig;

    return $runtime;
}

function stobeCallLLMPlayer2json(array $messages, array $config, array $meta = []): string|false {
    $runtime = stobeAdapterPlayer2json($config);
    if (function_exists('stobePlayer2HealthMarkUsed')) {
        stobePlayer2HealthMarkUsed(strval($runtime['base_url'] ?? ''));
    }
    return callLLM($messages, $runtime, $meta);
}

function stobeCallLLMStreamPlayer2json(
    array $messages,
    array $config,
    callable $onTextDelta,
    array $meta = []
): string|false {
    $runtime = stobeAdapterPlayer2json($config);
    if (function_exists('stobePlayer2HealthMarkUsed')) {
        stobePlayer2HealthMarkUsed(strval($runtime['base_url'] ?? ''));
    }
    return callLLMStream($messages, $runtime, $onTextDelta, $meta);
}
