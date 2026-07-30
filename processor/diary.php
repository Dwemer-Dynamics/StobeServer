<?php

/**
 * Diary processor - profile-driven diary generation for NPCs.
 */

storeEvent($eventType, $timestamp, $gamets, $eventData, 'pending', '', '', false);

$normalizedEventType = strtolower(trim(strval($eventType)));
$respectAutoFlags = false;

$response = [
    'ok' => false,
    'event_type' => $normalizedEventType,
    'attempted' => 0,
    'generated' => 0,
    'skipped' => 0,
    'failed' => 0,
    'reason' => '',
    'status_message' => '',
    'results' => [],
];

$eventDataPreview = $eventData;
if (strlen($eventDataPreview) > 180) {
    $eventDataPreview = substr($eventDataPreview, 0, 180) . '...';
}
stobeLogInfo('Diary event start', [
    'event_type' => $normalizedEventType,
    'profile' => strval($_GET['profile'] ?? ''),
    'people' => strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? '')),
    'data_preview' => $eventDataPreview,
]);

if (!in_array($normalizedEventType, ['diary', 'diary_narrator'], true)) {
    $response['reason'] = 'manual_only';
    $response['status_message'] = 'Diary skipped: manual diary trigger only.';
    stobeLogInfo('Diary event skipped: unsupported trigger type', [
        'event_type' => $normalizedEventType,
        'profile' => strval($_GET['profile'] ?? ''),
    ]);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$candidates = $normalizedEventType === 'diary_narrator'
    ? [stobeNarratorName()]
    : stobeExtractDiaryCandidates($normalizedEventType, $eventData);
if (!$respectAutoFlags) {
    $explicitProfile = normalizeParticipantNameToken(strval($_GET['profile'] ?? ''));
    if ($explicitProfile !== '') {
        $candidates = [$explicitProfile];
    } elseif (count($candidates) > 1) {
        // Manual diary trigger should target one NPC.
        $candidates = [strval($candidates[0])];
    }
}

if (count($candidates) === 0) {
    stobeLogInfo('Diary event skipped: no candidates', [
        'event_type' => $normalizedEventType,
        'profile' => strval($_GET['profile'] ?? ''),
        'people' => strval($GLOBALS["CACHE_PEOPLE"] ?? ($_GET['people'] ?? '')),
        'data_preview' => substr($eventData, 0, 180),
    ]);
    $response['reason'] = 'no_candidates';
    $response['status_message'] = 'Diary skipped: no candidates.';
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

$attempted = 0;
$generated = 0;
$skipped = 0;
$failed = 0;
$results = [];

foreach ($candidates as $candidateName) {
    $attempted++;
    $result = stobeGenerateDiaryEntryForNpc(
        strval($candidateName),
        intval($timestamp),
        intval($gamets),
        $normalizedEventType !== '' ? $normalizedEventType : 'diary',
        $respectAutoFlags
    );
    $results[] = $result;
    if (boolval($result['ok'] ?? false)) {
        $generated++;
        continue;
    }
    $reason = strtolower(trim(strval($result['reason'] ?? 'unknown')));
    if (
        $reason === 'auto_diary_disabled' ||
        $reason === 'diary_days_not_elapsed' ||
        $reason === 'cooldown_active'
    ) {
        $skipped++;
    } else {
        $failed++;
    }
}

$response['attempted'] = $attempted;
$response['generated'] = $generated;
$response['skipped'] = $skipped;
$response['failed'] = $failed;
$response['results'] = $results;

$firstReason = '';
foreach ($results as $result) {
    $reason = trim(strval($result['reason'] ?? ''));
    if ($reason !== '') {
        $firstReason = $reason;
        break;
    }
}
$response['reason'] = $firstReason;

if ($generated > 0) {
    $response['ok'] = true;
    $response['status_message'] = 'Diary written for ' . strval($generated) . ' NPC(s).';
} elseif ($failed === 0 && $skipped > 0) {
    $response['ok'] = true;
    $response['status_message'] = 'Diary skipped (' . strval($skipped) . ' skipped).';
} else {
    $response['ok'] = false;
    $response['status_message'] = 'Diary generation failed.';
}

stobeLogInfo('Diary event processed', [
    'event_type' => $normalizedEventType,
    'attempted' => $attempted,
    'generated' => $generated,
    'skipped' => $skipped,
    'failed' => $failed,
    'results' => $results,
]);

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
