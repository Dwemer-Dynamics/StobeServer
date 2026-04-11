<?php

/**
 * Location processor - handles location change events.
 * Tracks where the player is in the Kenshi world.
 */

$geo = resolveEventGeoContext('location', $eventData);
$locationText = composeEventLocationText($geo);
if ($locationText !== '') {
    $GLOBALS['CACHE_LOCATION'] = $locationText;
}

$locationPayload = [];
$decodedEventData = json_decode($eventData, true);
if (is_array($decodedEventData)) {
    $locationPayload = $decodedEventData;
}
$locationEnvironment = [];
if (isset($locationPayload['environment']) && is_array($locationPayload['environment'])) {
    $locationEnvironment = $locationPayload['environment'];
}
if (!array_key_exists('zone_name', $locationEnvironment)) {
    $fallbackZone = trim(strval($geo['zone'] ?? ''));
    if ($fallbackZone === '') {
        $fallbackZone = trim(strval($geo['city'] ?? ''));
    }
    $locationEnvironment['zone_name'] = $fallbackZone;
}
if (!array_key_exists('region', $locationEnvironment)) {
    $locationEnvironment['region'] = trim(strval($geo['region'] ?? ''));
}
if (!array_key_exists('town_name', $locationEnvironment)) {
    $locationEnvironment['town_name'] = trim(strval($geo['zone'] ?? ($geo['city'] ?? '')));
}
foreach (['x', 'y', 'z'] as $axis) {
    if (!array_key_exists($axis, $locationEnvironment) && isset($_GET[$axis])) {
        $locationEnvironment[$axis] = $_GET[$axis];
    }
}
stobeUpsertLocationZoneFromSnapshot([
    'is_player_character' => true,
    'environment' => $locationEnvironment,
], intval($gamets ?? 0), 'location_event');

storeEvent($eventType, $timestamp, $gamets, $eventData);

echo "ok";
