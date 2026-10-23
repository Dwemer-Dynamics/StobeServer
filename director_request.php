<?php

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/director_functions.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    stobeDirectorSendJson(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

try {
    $result = stobeDirectorPlan(stobeDirectorReadPayload());
    $status = intval($result['status'] ?? 500);
    unset($result['status']);
    stobeDirectorSendJson($result, $status);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Kenshi Director endpoint failed');
    stobeDirectorSendJson(['ok' => false, 'error' => 'director_failed'], 500);
}
