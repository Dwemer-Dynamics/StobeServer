<?php

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/debug/db_updates.php';

try {
    $response = ['ok' => true, 'phase' => 1, 'session' => stobeAutonomyGetSession()];
    if (stobeAutonomyBool($_GET['include_npcs'] ?? false)) {
        $response['eligible_npcs'] = stobeAutonomyListEligibleNpcs();
    }
    if (stobeAutonomyBool($_GET['include_events'] ?? false)) {
        $response['events'] = stobeAutonomyListEvents(50);
    }
    stobeAutonomySendJson($response);
} catch (Throwable $exception) {
    stobeLogException($exception, 'Autonomy state endpoint failed');
    stobeAutonomySendJson(['ok' => false, 'error' => 'state_unavailable'], 500);
}

