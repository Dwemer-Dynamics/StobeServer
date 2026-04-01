<?php

/**
 * Infoaction processor - Herika-compatible world action event.
 * Stored in eventlog for context and timeline recall.
 */

storeEvent($eventType, $timestamp, $gamets, $eventData);
if (function_exists('stobeApplyCarryMetadataFromActionEvent')) {
    stobeApplyCarryMetadataFromActionEvent(strval($eventData), intval($gamets), strval($eventType));
}

echo "ok";
