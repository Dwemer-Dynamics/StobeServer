<?php

/**
 * Lockpicked processor - lockpicking activity telemetry.
 * Logged to eventlog so NPC context can reference recent lockpick activity.
 */

storeEvent($eventType, $timestamp, $gamets, $eventData);

echo "ok";
