<?php

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/debug/db_updates.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    stobeAutonomySendJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
    $result = stobeAutonomyApplyPluginReport(stobeAutonomyReadRequestPayload());
    $status = intval($result['status'] ?? 500);
    unset($result['status']);
    stobeAutonomySendJson($result, $status);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Autonomy observation endpoint failed');
    stobeAutonomySendJson(['ok' => false, 'error' => 'observation_failed'], 500);
}

