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
    if (!boolval($result['ok'] ?? false)) {
        stobeAutonomySendJson($result, $status);
    }
    $result['phase'] = 1;
    $result['decision'] = null;
    $result['action'] = null;
    $result['reason'] = 'phase_1_control_plane_only';
    stobeAutonomySendJson($result);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Autonomy tick endpoint failed');
    stobeAutonomySendJson(['ok' => false, 'error' => 'tick_failed'], 500);
}

