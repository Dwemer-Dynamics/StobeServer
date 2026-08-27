<?php

function stobeAdventureEventTypes(): array
{
    return [
        'im_alive',
        'chat',
        'infoaction',
        'rpg_word',
        'rpg_lvlup',
        'rechat',
        'quest',
        'itemfound',
        'inputtext',
        'injection',
        'goodnight',
        'goodmorning',
        'ginputtext',
        'death',
        'combatendmighty',
        'combatend',
    ];
}

// Match one exact NPC token while ignoring transient audience-state suffixes.
function stobeBuildNpcEventPeopleWhereClause(string $npcPlaceholder = '$1', string $peopleColumn = 'people'): string
{
    if (!preg_match('/^(?:[A-Za-z_][A-Za-z0-9_]*\.)?[A-Za-z_][A-Za-z0-9_]*$/', $peopleColumn)) {
        $peopleColumn = 'people';
    }
    if (!preg_match('/^\$[1-9][0-9]*$/', $npcPlaceholder)) {
        $npcPlaceholder = '$1';
    }

    return "EXISTS (
        SELECT 1
        FROM jsonb_array_elements_text(
            CASE
                WHEN left(btrim(COALESCE({$peopleColumn}, '')), 1) = '['
                    THEN COALESCE(NULLIF(btrim({$peopleColumn}), ''), '[]')::jsonb
                ELSE to_jsonb(string_to_array(trim(BOTH '|' FROM COALESCE({$peopleColumn}, '')), '|'))
            END
        ) AS stobe_person(person_name)
        WHERE lower(regexp_replace(
            btrim(stobe_person.person_name),
            '( \\((busy|hostile|in combat|restrained)\\)|\\|hand_[0-9]+)+$',
            '',
            'i'
        )) = lower({$npcPlaceholder})
    )";
}

function stobeParseEventPeople(mixed $people): array
{
    $text = trim(strval($people ?? ''));
    if ($text === '') {
        return [];
    }

    $decoded = json_decode($text, true);
    $parts = is_array($decoded) ? $decoded : explode('|', trim($text, '|'));
    $recipients = [];
    foreach ($parts as $part) {
        $name = trim(strval($part));
        $name = preg_replace('/(?: \\((?:busy|hostile|in combat|restrained)\\)|\\|hand_[0-9]+)+$/i', '', $name) ?? $name;
        if ($name !== '' && !in_array($name, $recipients, true)) {
            $recipients[] = $name;
        }
    }
    return $recipients;
}

// ---------------------------------------------------------------------------
// Relationship history timeline
//
// core_npc_master_history already stores full NPC snapshots. Relationship writes
// can be captured by either the ordinary trigger or an explicit stamp, so compare
// values regardless of snapshot_reason. The helpers below pair each of those
// snapshots with the preceding snapshot for the same NPC and derive the
// affinity/type/note changes for read-only display. Nothing is copied into
// eventlog, and no schema, trigger, or AI-context allowlist changes.
// ---------------------------------------------------------------------------

function stobeRelationshipTimelineEventType(): string
{
    return 'relationship';
}

// Compare only the fields displayed by the timeline, ignoring timestamps and player notes.
function stobeRelationshipComparableMapSql(string $extendedDataExpression): string
{
    $relationships = "COALESCE((" . $extendedDataExpression . ") -> 'relationships', '{}'::jsonb)";
    $objectOnly = "CASE WHEN jsonb_typeof({$relationships}) = 'object' THEN {$relationships} ELSE '{}'::jsonb END";

    return "COALESCE((
        SELECT jsonb_object_agg(
            lower(stobe_rel_entry.key),
            CASE
                WHEN jsonb_typeof(stobe_rel_entry.value) = 'object'
                    THEN jsonb_build_object(
                        'aff', COALESCE(stobe_rel_entry.value ->> 'aff', '0'),
                        'type', lower(COALESCE(stobe_rel_entry.value ->> 'type', 'neutral')),
                        'note', btrim(COALESCE(stobe_rel_entry.value ->> 'note', ''))
                    )
                ELSE stobe_rel_entry.value
            END
        )
        FROM jsonb_each({$objectOnly}) AS stobe_rel_entry
    ), '{}'::jsonb)";
}

/**
 * Pair each relationship snapshot with its predecessor for the same NPC.
 *
 * $npcFilterSql is an optional, already-parameterised predicate (for example
 * 'npc_id = $3') applied before ordering so per-NPC views never window over the
 * whole history table.
 */
function stobeRelationshipHistoryTimelineCte(string $npcFilterSql = ''): string
{
    $npcWhere = trim($npcFilterSql) === '' ? '' : 'WHERE ' . trim($npcFilterSql);
    $currentMap = stobeRelationshipComparableMapSql('extended_data');
    $previousMap = stobeRelationshipComparableMapSql('previous_extended_data');

    return "WITH stobe_ordered_relationship_history AS (
        SELECT
            history_id,
            npc_id,
            name,
            snapshot_reason,
            extended_data,
            gamets_last_updated,
            created,
            LAG(extended_data) OVER (
                PARTITION BY npc_id
                ORDER BY gamets_last_updated ASC NULLS FIRST, created ASC, history_id ASC
            ) AS previous_extended_data
        FROM core_npc_master_history
        {$npcWhere}
    ), stobe_visible_relationship_history AS (
        SELECT *
        FROM stobe_ordered_relationship_history
        WHERE {$currentMap} IS DISTINCT FROM {$previousMap}
    )";
}

// Projection for the eventlog half of the merged timeline UNION.
function stobeMergedTimelineEventSelectSql(string $alias = 'a'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        $alias = 'a';
    }

    return "'event' AS timeline_source,
            {$alias}.rowid AS event_rowid,
            0::bigint AS history_id,
            {$alias}.type AS type,
            {$alias}.data AS data,
            {$alias}.people AS people,
            {$alias}.location AS location,
            {$alias}.gamets AS gamets,
            {$alias}.localts AS localts,
            {$alias}.ts AS ts,
            {$alias}.sess AS sess,
            NULL::jsonb AS extended_data,
            NULL::jsonb AS previous_extended_data,
            '' AS owner_name,
            0 AS owner_npc_id";
}

// Projection for the derived relationship half of the merged timeline UNION.
function stobeMergedTimelineRelationshipSelectSql(): string
{
    return "'relationship' AS timeline_source,
            0::bigint AS event_rowid,
            history_id AS history_id,
            'relationship' AS type,
            '' AS data,
            '' AS people,
            '' AS location,
            COALESCE(gamets_last_updated, 0) AS gamets,
            EXTRACT(EPOCH FROM created AT TIME ZONE current_setting('TimeZone'))::bigint AS localts,
            EXTRACT(EPOCH FROM created AT TIME ZONE current_setting('TimeZone'))::bigint AS ts,
            '' AS sess,
            extended_data,
            previous_extended_data,
            name AS owner_name,
            npc_id AS owner_npc_id";
}

// Keep the established eventlog ordering; each source breaks ties on its own cursor.
function stobeMergedTimelineOrderSql(): string
{
    return "ORDER BY COALESCE(NULLIF(localts, 0), ts, 0) DESC,
                     ts DESC,
                     event_rowid DESC,
                     history_id DESC";
}

/**
 * Normalize one snapshot's relationship map, memoized for the current request.
 *
 * Consecutive snapshots for the same NPC share JSON: row N's previous state is
 * row N+1's current state. Normalizing is not free (it resolves the player name
 * per target), so a small bounded cache keeps a full timeline page cheap.
 */
function stobeRelationshipSnapshotMap(mixed $extendedData): array
{
    if (!function_exists('stobeNormalizeRelationshipMap')) {
        return [];
    }
    if (is_string($extendedData)) {
        $cacheKey = md5($extendedData);
        if (isset($GLOBALS['STOBE_RELATIONSHIP_SNAPSHOT_MAP_CACHE'][$cacheKey])) {
            return $GLOBALS['STOBE_RELATIONSHIP_SNAPSHOT_MAP_CACHE'][$cacheKey];
        }
        $map = stobeRelationshipSnapshotMapUncached($extendedData);
        if (!isset($GLOBALS['STOBE_RELATIONSHIP_SNAPSHOT_MAP_CACHE'])
            || count($GLOBALS['STOBE_RELATIONSHIP_SNAPSHOT_MAP_CACHE']) >= 512) {
            $GLOBALS['STOBE_RELATIONSHIP_SNAPSHOT_MAP_CACHE'] = [];
        }
        $GLOBALS['STOBE_RELATIONSHIP_SNAPSHOT_MAP_CACHE'][$cacheKey] = $map;
        return $map;
    }

    return stobeRelationshipSnapshotMapUncached($extendedData);
}

function stobeRelationshipSnapshotMapUncached(mixed $extendedData): array
{
    if (is_string($extendedData)) {
        $extendedData = trim($extendedData) === '' ? [] : json_decode($extendedData, true);
    }
    if (!is_array($extendedData)) {
        return [];
    }

    return stobeNormalizeRelationshipMap($extendedData['relationships'] ?? []);
}

function stobeRelationshipEntryTier(array $entry): string
{
    $tier = trim(strval($entry['tier'] ?? ''));
    if ($tier !== '') {
        return $tier;
    }
    if (function_exists('stobeRelationshipTierLabel')) {
        return stobeRelationshipTierLabel(intval($entry['aff'] ?? 0));
    }
    return '';
}

/**
 * Break one relationship snapshot into per-target change details.
 *
 * Volatile fields are ignored: 'updated_at' is rewritten on every normalize
 * pass and 'tier' is derived from affinity, so only affinity, type and note
 * decide whether a target actually moved. This mirrors
 * stobeRelationshipComparableMapSql() so the SQL prefilter and this derivation
 * agree on which snapshots are worth showing.
 */
function stobeBuildRelationshipChangeDetails(array $snapshot): array
{
    $newMap = stobeRelationshipSnapshotMap($snapshot['extended_data'] ?? null);
    $oldMap = stobeRelationshipSnapshotMap($snapshot['previous_extended_data'] ?? null);

    $indexByTarget = static function (array $map): array {
        $indexed = [];
        foreach ($map as $target => $entry) {
            $name = trim(strval($target));
            if ($name === '') {
                continue;
            }
            $indexed[strtolower($name)] = ['target' => $name, 'entry' => is_array($entry) ? $entry : []];
        }
        return $indexed;
    };
    $oldIndexed = $indexByTarget($oldMap);
    $newIndexed = $indexByTarget($newMap);

    $details = [];
    foreach (array_keys($newIndexed + $oldIndexed) as $key) {
        $hasNew = array_key_exists($key, $newIndexed);
        $hasOld = array_key_exists($key, $oldIndexed);
        $new = $hasNew ? $newIndexed[$key]['entry'] : [];
        $old = $hasOld ? $oldIndexed[$key]['entry'] : [];
        $target = $hasNew ? $newIndexed[$key]['target'] : $oldIndexed[$key]['target'];

        $newAffinity = intval($new['aff'] ?? 0);
        $oldAffinity = intval($old['aff'] ?? 0);
        $newType = strtolower(trim(strval($new['type'] ?? '')));
        $oldType = strtolower(trim(strval($old['type'] ?? '')));
        $newNote = trim(strval($new['note'] ?? ''));
        $oldNote = trim(strval($old['note'] ?? ''));

        if ($hasNew && $hasOld) {
            if ($newAffinity === $oldAffinity && $newType === $oldType && $newNote === $oldNote) {
                continue;
            }
            $state = 'updated';
        } elseif ($hasNew) {
            // The first snapshot for a target is a real change, not an empty diff.
            $state = 'added';
        } else {
            $state = 'removed';
        }

        $details[] = [
            'target' => $target,
            'state' => $state,
            'delta' => ($hasNew && $hasOld) ? ($newAffinity - $oldAffinity) : 0,
            'affinity_from' => $hasOld ? $oldAffinity : null,
            'affinity_to' => $hasNew ? $newAffinity : null,
            'tier_from' => $hasOld ? stobeRelationshipEntryTier($old) : '',
            'tier_to' => $hasNew ? stobeRelationshipEntryTier($new) : '',
            'type_from' => $oldType,
            'type_to' => $newType,
            'type_changed' => ($hasNew && $hasOld && $newType !== $oldType),
            // Only surface a note that was actually rewritten for this change.
            'note' => ($newNote !== '' && $newNote !== $oldNote) ? $newNote : '',
        ];
    }

    return $details;
}

function stobeTruncateRelationshipNote(string $note, int $maxLength = 140): string
{
    if (function_exists('mb_strlen')) {
        if (mb_strlen($note, 'UTF-8') <= $maxLength) {
            return $note;
        }
        return rtrim(mb_substr($note, 0, $maxLength, 'UTF-8')) . '...';
    }
    if (strlen($note) <= $maxLength) {
        return $note;
    }
    return rtrim(substr($note, 0, $maxLength)) . '...';
}

// One compact, plain-text clause describing a single target's movement.
function stobeRelationshipChangeClause(array $change): string
{
    $target = trim(strval($change['target'] ?? ''));
    if ($target === '') {
        return '';
    }

    $state = strval($change['state'] ?? 'updated');
    $tierTo = trim(strval($change['tier_to'] ?? ''));
    $tierFrom = trim(strval($change['tier_from'] ?? ''));
    $typeTo = trim(strval($change['type_to'] ?? ''));
    $typeFrom = trim(strval($change['type_from'] ?? ''));

    $parts = [];
    if ($state === 'removed') {
        $parts[] = 'relationship cleared';
    } elseif ($state === 'added') {
        $opened = sprintf('new at %+d', intval($change['affinity_to'] ?? 0));
        if ($tierTo !== '') {
            $opened .= ' (' . $tierTo . ')';
        }
        $parts[] = $opened;
        if ($typeTo !== '' && $typeTo !== 'neutral') {
            $parts[] = 'type ' . $typeTo;
        }
    } else {
        $delta = intval($change['delta'] ?? 0);
        if ($delta !== 0) {
            $parts[] = sprintf(
                '%+d (%d to %d)',
                $delta,
                intval($change['affinity_from'] ?? 0),
                intval($change['affinity_to'] ?? 0)
            );
        }
        if ($tierTo !== '' && $tierTo !== $tierFrom) {
            $parts[] = ($tierFrom !== '' ? $tierFrom . ' to ' : '') . $tierTo;
        }
        if (!empty($change['type_changed'])) {
            $parts[] = 'type ' . ($typeFrom !== '' ? $typeFrom . ' to ' : '')
                . ($typeTo !== '' ? $typeTo : 'neutral');
        }
    }

    $note = stobeTruncateRelationshipNote(trim(strval($change['note'] ?? '')));
    if (empty($parts)) {
        $parts[] = $note !== '' ? 'note updated' : 'relationship updated';
    }

    $clause = $target . ': ' . implode(', ', $parts);
    if ($note !== '') {
        $clause .= ' - ' . $note;
    }
    return $clause;
}

function stobeRelationshipChangeText(string $ownerName, array $changes): string
{
    $owner = trim($ownerName);
    $clauses = [];
    $hidden = 0;
    foreach ($changes as $change) {
        $clause = stobeRelationshipChangeClause(is_array($change) ? $change : []);
        if ($clause === '') {
            continue;
        }
        if (count($clauses) >= 8) {
            $hidden++;
            continue;
        }
        $clauses[] = $clause;
    }

    if (empty($clauses)) {
        return $owner !== ''
            ? $owner . ' - relationship snapshot recorded'
            : 'Relationship snapshot recorded';
    }

    $summary = implode('; ', $clauses);
    if ($hidden > 0) {
        $summary .= ' (+' . strval($hidden) . ' more)';
    }
    return $owner !== '' ? $owner . ' - ' . $summary : $summary;
}

// Owner first, then every target the snapshot touched.
function stobeRelationshipChangeParticipants(string $ownerName, array $changes): array
{
    $people = [];
    $owner = trim($ownerName);
    if ($owner !== '') {
        $people[] = $owner;
    }
    foreach ($changes as $change) {
        if (!is_array($change)) {
            continue;
        }
        $target = trim(strval($change['target'] ?? ''));
        if ($target !== '' && !in_array($target, $people, true)) {
            $people[] = $target;
        }
    }
    return $people;
}

/**
 * Turn one merged-timeline relationship row into a displayable, read-only row.
 *
 * The derived text lands in 'data' and 'people' so existing timeline renderers
 * need no special-casing beyond suppressing their delete controls.
 */
function stobeDecorateRelationshipTimelineRow(array $row): array
{
    $changes = stobeBuildRelationshipChangeDetails($row);
    $ownerName = trim(strval($row['owner_name'] ?? ''));
    $participants = stobeRelationshipChangeParticipants($ownerName, $changes);

    $row['type'] = stobeRelationshipTimelineEventType();
    $row['changes'] = $changes;
    $row['data'] = stobeRelationshipChangeText($ownerName, $changes);
    $row['people'] = empty($participants) ? '' : '|' . implode('|', $participants) . '|';
    $row['participants'] = $participants;
    return $row;
}
