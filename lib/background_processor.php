<?php

/**
 * Background processor helpers for StobeServer.
 *
 * Mirrors the Herika helper-daemon pattern:
 * - Lightweight TCP heartbeat listener on a dedicated port.
 * - `service/start.sh` bootstraps a loop that runs `service/manager.php`.
 */

function stobeBackgroundProcessorPort(): int
{
    return 12346;
}

function stobeBackgroundProcessorStartScriptPath(): string
{
    $enginePath = $GLOBALS['ENGINE_PATH'] ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR);
    $enginePath = rtrim($enginePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return $enginePath . 'service' . DIRECTORY_SEPARATOR . 'start.sh';
}

function stobeBackgroundProcessorIsRunning(float $timeoutSeconds = 0.8): bool
{
    $port = stobeBackgroundProcessorPort();
    if ($port < 1 || $port > 65535) {
        return false;
    }

    if ($timeoutSeconds < 0.1) {
        $timeoutSeconds = 0.1;
    } elseif ($timeoutSeconds > 2.0) {
        $timeoutSeconds = 2.0;
    }

    // Probe multiple times to avoid false negatives during startup.
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, $timeoutSeconds);
        if (is_resource($socket)) {
            @fclose($socket);
            return true;
        }
        usleep(100000);
    }

    // Fallback: if the daemon process exists, consider it running even if the
    // heartbeat port has not opened yet.
    if (function_exists('shell_exec')) {
        $startScript = stobeBackgroundProcessorStartScriptPath();
        $normalizedStartScript = str_replace('\\', '/', $startScript);
        $processCheck = @shell_exec('pgrep -f ' . escapeshellarg($normalizedStartScript) . ' 2>/dev/null');
        if (is_string($processCheck) && trim($processCheck) !== '') {
            return true;
        }
    }

    return false;
}

function stobeEnsureBackgroundProcessorRunning(bool $logFailures = true): bool
{
    if (stobeBackgroundProcessorIsRunning()) {
        return true;
    }

    $startScript = stobeBackgroundProcessorStartScriptPath();
    if (!is_file($startScript)) {
        if ($logFailures) {
            stobeLogWarn('Background processor start script missing', [
                'path' => $startScript,
            ]);
        }
        return false;
    }

    if (!function_exists('shell_exec')) {
        if ($logFailures) {
            stobeLogWarn('shell_exec unavailable; cannot auto-start background processor');
        }
        return false;
    }

    $command = 'nohup bash ' . escapeshellarg($startScript) . ' > /dev/null 2>&1 < /dev/null &';
    @shell_exec($command);

    $running = false;
    for ($attempt = 0; $attempt < 20; $attempt++) {
        usleep(150000);
        if (stobeBackgroundProcessorIsRunning(0.25)) {
            $running = true;
            break;
        }
    }
    if ($running) {
        stobeLogInfo('Background processor started', [
            'port' => stobeBackgroundProcessorPort(),
            'script' => $startScript,
        ]);
        return true;
    }

    if ($logFailures) {
        stobeLogWarn('Background processor failed to start', [
            'port' => stobeBackgroundProcessorPort(),
            'script' => $startScript,
        ]);
    }

    return false;
}
