<?php

/**
 * StobeServer dialogue mode endpoint.
 *
 * GET  -> returns default normalized mode.
 * POST -> validates request mode and echoes normalized mode.
 */

require_once(__DIR__ . '/lib/bootstrap.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

function readPostedMode(): string {
    if (isset($_POST['mode'])) {
        return strval($_POST['mode']);
    }

    $rawInput = file_get_contents('php://input');
    if (!is_string($rawInput) || trim($rawInput) === '') {
        return '';
    }

    $decoded = json_decode($rawInput, true);
    if (is_array($decoded) && isset($decoded['mode'])) {
        return strval($decoded['mode']);
    }

    parse_str($rawInput, $parsed);
    if (is_array($parsed) && isset($parsed['mode'])) {
        return strval($parsed['mode']);
    }

    return '';
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $currentMode = getDialogueMode();
        stobeLogInfo('chat_mode read', ['mode' => $currentMode]);
        echo json_encode(
            [
                'ok' => true,
                'mode' => $currentMode,
            ],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if ($method === 'POST') {
        $postedMode = readPostedMode();
        $savedMode = setDialogueMode($postedMode);
        stobeLogInfo('chat_mode updated', ['requested_mode' => $postedMode, 'saved_mode' => $savedMode]);
        echo json_encode(
            [
                'ok' => true,
                'mode' => $savedMode,
            ],
            JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    stobeLogWarn('chat_mode rejected method', ['method' => $method]);
    http_response_code(405);
    echo json_encode(
        [
            'ok' => false,
            'error' => 'Method not allowed',
        ],
        JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    stobeLogException($e, 'chat_mode endpoint failed');
    http_response_code(500);
    echo json_encode(
        [
            'ok' => false,
            'error' => 'Internal server error',
        ],
        JSON_UNESCAPED_SLASHES
    );
}

