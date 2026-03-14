<?php

/**
 * Context processor - receives world state updates.
 * Nearby NPCs, player status, faction relations, etc.
 */

storeEvent($eventType, $timestamp, $gamets, $eventData);

$eventDataText = trim(strval($eventData));
if (stripos($eventDataText, 'context_update') === 0) {
    $markerPath = stobeGetLogPath('context_legacy.warn.ts');
    $now = time();
    $lastLoggedTs = 0;
    if (is_file($markerPath)) {
        $raw = @file_get_contents($markerPath);
        if (is_string($raw) && preg_match('/^[0-9]+$/', trim($raw)) === 1) {
            $lastLoggedTs = intval(trim($raw));
        }
    }

    // Throttle to at most once every 120 seconds.
    if (($now - $lastLoggedTs) >= 120) {
        @file_put_contents($markerPath, strval($now));
        stobeLogImport('Legacy context marker received', [
            'event_type' => $eventType,
            'gamets' => intval($gamets),
            'data_preview' => substr($eventDataText, 0, 80),
            'hint' => 'Structured /context JSON is not reaching npc_snapshot.php; import-only fields (storage_id/faction/gender/limbs/blood) cannot populate from context_update markers.',
        ], 'WARN');
    }
}

echo "ok";
