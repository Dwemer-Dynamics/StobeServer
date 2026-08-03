<?php

require_once __DIR__ . DIRECTORY_SEPARATOR . 'player2_config.php';

const STOBE_PLAYER2_HEALTH_ACTIVITY_TTL = 180;
const STOBE_PLAYER2_HEALTH_ACTIVITY_WRITE_INTERVAL = 15;
const STOBE_PLAYER2_HEALTH_USE_TTL = 300;
const STOBE_PLAYER2_HEALTH_INTERVAL = 60;
const STOBE_PLAYER2_HEALTH_LOCK_ID = 873421;

function stobePlayer2HealthNormalizeUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        $url = 'http://127.0.0.1:4315';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'http://' . ltrim($url, '/');
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return 'http://127.0.0.1:4315/v1/health';
    }

    $scheme = strtolower(strval($parts['scheme'] ?? 'http')) === 'https' ? 'https' : 'http';
    $healthUrl = $scheme . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $healthUrl .= ':' . intval($parts['port']);
    }

    return $healthUrl . '/v1/health';
}

function stobePlayer2HealthGetOption(string $id, string $default = ''): string
{
    if (!function_exists('getConfOpt')) {
        return $default;
    }

    return getConfOpt($id, $default);
}

function stobePlayer2HealthSetOption(string $id, string $value): bool
{
    if (!function_exists('setConfOpt')) {
        return false;
    }

    setConfOpt($id, $value, true);
    return true;
}

function stobePlayer2HealthMarkGameActivity(?int $now = null): bool
{
    $now = $now ?? time();
    $lastActivity = intval(stobePlayer2HealthGetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', '0'));
    $newSession = $lastActivity <= 0 || ($now - $lastActivity) > STOBE_PLAYER2_HEALTH_ACTIVITY_TTL;

    if ($newSession) {
        stobePlayer2HealthSetOption('PLAYER2_GAME_SESSION_STARTED_TS', strval($now));
    }
    if ($newSession || ($now - $lastActivity) >= STOBE_PLAYER2_HEALTH_ACTIVITY_WRITE_INTERVAL) {
        stobePlayer2HealthSetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', strval($now));
    }
    $GLOBALS['PLAYER2_GAME_REQUEST_ACTIVE'] = true;

    return $newSession;
}

function stobePlayer2HealthMarkUsed(string $connectorUrl, ?int $now = null): bool
{
    if (empty($GLOBALS['PLAYER2_GAME_REQUEST_ACTIVE'])) {
        return false;
    }

    $now = $now ?? time();
    $sessionStarted = intval(stobePlayer2HealthGetOption('PLAYER2_GAME_SESSION_STARTED_TS', '0'));
    $lastActivity = intval(stobePlayer2HealthGetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', '0'));
    if ($sessionStarted <= 0 || $lastActivity <= 0 || ($now - $lastActivity) > STOBE_PLAYER2_HEALTH_ACTIVITY_TTL) {
        return false;
    }

    stobePlayer2HealthSetOption('PLAYER2_HEALTH_ACTIVE_SESSION_TS', strval($sessionStarted));
    stobePlayer2HealthSetOption('PLAYER2_HEALTH_LAST_USED_TS', strval($now));
    stobePlayer2HealthSetOption('PLAYER2_HEALTH_URL', stobePlayer2HealthNormalizeUrl($connectorUrl));
    return true;
}

function stobePlayer2HealthShouldPing(array $state, int $now): bool
{
    $lastActivity = intval($state['last_activity'] ?? 0);
    $sessionStarted = intval($state['session_started'] ?? 0);
    $activeSession = intval($state['active_session'] ?? 0);
    $lastUsed = intval($state['last_used'] ?? 0);
    $lastAttempt = intval($state['last_attempt'] ?? 0);
    $healthUrl = trim(strval($state['health_url'] ?? ''));

    return $healthUrl !== ''
        && $lastActivity > 0
        && ($now - $lastActivity) <= STOBE_PLAYER2_HEALTH_ACTIVITY_TTL
        && $sessionStarted > 0
        && $activeSession === $sessionStarted
        && $lastUsed > 0
        && ($now - $lastUsed) <= STOBE_PLAYER2_HEALTH_USE_TTL
        && ($lastAttempt <= 0 || ($now - $lastAttempt) >= STOBE_PLAYER2_HEALTH_INTERVAL);
}

function stobePlayer2HealthRequest(string $url): array
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [stobePlayer2GameKeyHeader()],
        ]);
        $body = curl_exec($handle);
        $error = curl_error($handle);
        $status = intval(curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
        curl_close($handle);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 300,
            'http_code' => $status,
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => stobePlayer2GameKeyHeader() . "\r\n",
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $matches)) {
            $status = intval($matches[1]);
            break;
        }
    }

    return [
        'ok' => $body !== false && $status >= 200 && $status < 300,
        'http_code' => $status,
        'error' => $body === false ? 'Player2 health request failed' : '',
    ];
}

function stobePlayer2HealthTick(?int $now = null, ?callable $transport = null): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $now = $now ?? time();
    $state = [
        'last_activity' => stobePlayer2HealthGetOption('PLAYER2_GAME_LAST_ACTIVITY_TS', '0'),
        'session_started' => stobePlayer2HealthGetOption('PLAYER2_GAME_SESSION_STARTED_TS', '0'),
        'active_session' => stobePlayer2HealthGetOption('PLAYER2_HEALTH_ACTIVE_SESSION_TS', '0'),
        'last_used' => stobePlayer2HealthGetOption('PLAYER2_HEALTH_LAST_USED_TS', '0'),
        'last_attempt' => stobePlayer2HealthGetOption('PLAYER2_HEALTH_LAST_ATTEMPT_TS', '0'),
        'health_url' => stobePlayer2HealthGetOption('PLAYER2_HEALTH_URL', ''),
    ];
    if (!stobePlayer2HealthShouldPing($state, $now)) {
        return false;
    }

    $lockRow = $db->fetchOne(
        'SELECT pg_try_advisory_lock($1) AS locked',
        [STOBE_PLAYER2_HEALTH_LOCK_ID]
    );
    $locked = in_array(strtolower(strval($lockRow['locked'] ?? '')), ['1', 't', 'true'], true);
    if (!$locked) {
        return false;
    }

    try {
        $lastAttempt = intval(stobePlayer2HealthGetOption('PLAYER2_HEALTH_LAST_ATTEMPT_TS', '0'));
        if ($lastAttempt > 0 && ($now - $lastAttempt) < STOBE_PLAYER2_HEALTH_INTERVAL) {
            return false;
        }

        stobePlayer2HealthSetOption('PLAYER2_HEALTH_LAST_ATTEMPT_TS', strval($now));
        $result = $transport
            ? $transport(strval($state['health_url']))
            : stobePlayer2HealthRequest(strval($state['health_url']));
        $httpCode = intval($result['http_code'] ?? 0);
        $error = trim(strval($result['error'] ?? ''));

        stobePlayer2HealthSetOption('PLAYER2_HEALTH_LAST_HTTP_CODE', strval($httpCode));
        stobePlayer2HealthSetOption('PLAYER2_HEALTH_LAST_ERROR', substr($error, 0, 500));
        if (!empty($result['ok'])) {
            stobePlayer2HealthSetOption('PLAYER2_HEALTH_LAST_SUCCESS_TS', strval($now));
            if (function_exists('stobeLogInfo')) {
                stobeLogInfo("[Player2 Health] Heartbeat succeeded ({$httpCode})");
            }
        } elseif (function_exists('stobeLogWarn')) {
            stobeLogWarn("[Player2 Health] Heartbeat failed ({$httpCode}) {$error}");
        }

        return !empty($result['ok']);
    } finally {
        $db->fetchOne(
            'SELECT pg_advisory_unlock($1) AS unlocked',
            [STOBE_PLAYER2_HEALTH_LOCK_ID]
        );
    }
}
