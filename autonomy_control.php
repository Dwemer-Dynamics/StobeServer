<?php

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/debug/db_updates.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    stobeAutonomySendJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
    $payload = stobeAutonomyReadRequestPayload();
    $result = stobeAutonomyApplyControl(strval($payload['action'] ?? ''), $payload);
    $status = intval($result['status'] ?? 500);
    unset($result['status']);
    stobeAutonomySendJson($result, $status);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Autonomy control endpoint failed');
    stobeAutonomySendJson(['ok' => false, 'error' => 'control_failed'], 500);
}

