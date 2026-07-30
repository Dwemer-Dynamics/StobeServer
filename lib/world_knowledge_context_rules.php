<?php

function stobeWorldKnowledgePayloadFingerprint(string $description): string
{
    $normalized = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
    if ($normalized === '') {
        return '';
    }
    $normalized = function_exists('mb_strtolower')
        ? mb_strtolower($normalized, 'UTF-8')
        : strtolower($normalized);
    return hash('sha256', $normalized);
}

function stobeWorldKnowledgeHintDescription(string $hint): string
{
    $separator = strpos($hint, ': ');
    return $separator === false ? trim($hint) : trim(substr($hint, $separator + 2));
}

function stobeWorldKnowledgeHintPayloadFingerprint(string $hint): string
{
    return stobeWorldKnowledgePayloadFingerprint(stobeWorldKnowledgeHintDescription($hint));
}

/**
 * Adds prompt hints while suppressing identical lore carried by different topics.
 */
function stobeWorldKnowledgeAppendUniqueHints(array &$target, array $hints, array &$seenPayloads): void
{
    foreach ($hints as $hint) {
        $line = trim(strval($hint));
        if ($line === '') {
            continue;
        }
        $fingerprint = stobeWorldKnowledgeHintPayloadFingerprint($line);
        if ($fingerprint !== '' && isset($seenPayloads[$fingerprint])) {
            continue;
        }
        if ($fingerprint !== '') {
            $seenPayloads[$fingerprint] = true;
        }
        $target[] = $line;
    }
}

function stobeWorldKnowledgeBuildHintLine(string $topic, string $description): string
{
    $safeTopic = trim($topic);
    $safeDescription = trim(preg_replace('/\s+/u', ' ', $description) ?? $description);
    if ($safeTopic === '' || $safeDescription === '') {
        return '';
    }

    if (function_exists('stobeWorldStatePromptAddendaForTopic')) {
        $addenda = stobeWorldStatePromptAddendaForTopic($safeTopic, 4);
        if (is_array($addenda) && count($addenda) > 0) {
            $safeDescription .= ' ' . implode(' ', $addenda);
        }
    }

    return $safeTopic . ': ' . trim(preg_replace('/\s+/u', ' ', $safeDescription) ?? $safeDescription);
}

function stobeWorldKnowledgeRuleNormalizeLabel(mixed $value): string
{
    if (function_exists('stobeWorldKnowledgeNormalizeLookupLabel')) {
        return stobeWorldKnowledgeNormalizeLookupLabel(strval($value));
    }
    $normalized = strtolower(str_replace('_', ' ', trim(strval($value))));
    $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
}

function stobeWorldKnowledgeRuleValues(mixed $value): array
{
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : (preg_split('/\s*[,|]\s*/u', $value) ?: []);
    } elseif (!is_array($value)) {
        $value = [$value];
    }

    $values = [];
    foreach ($value as $entry) {
        if (is_array($entry)) {
            foreach (stobeWorldKnowledgeRuleValues($entry) as $nested) {
                $values[$nested] = $nested;
            }
            continue;
        }
        $normalized = stobeWorldKnowledgeRuleNormalizeLabel($entry);
        if ($normalized !== '') {
            $values[$normalized] = $normalized;
        }
    }
    return array_values($values);
}

function stobeWorldKnowledgeRuleConditionFields(): array
{
    return [
        'character' => ['Character', 'Current NPC name or original name.'],
        'nearby_character' => ['Nearby Character', 'Speaker or any nearby non-animal character.'],
        'race' => ['Race', 'Current NPC race.'],
        'faction' => ['Faction', 'Current NPC faction.'],
        'profile' => ['Profile ID', 'Current NPC profile ID.'],
        'location' => ['Location', 'Any current building, town, zone, or region label.'],
        'building' => ['Building', 'Current building or indoor location.'],
        'town' => ['Town or Zone', 'Current town, settlement, city, or zone.'],
        'region' => ['Region', 'Current region.'],
        'environment' => ['Environment', 'Use indoors, outdoors, interior, exterior, or in town.'],
        'weather' => ['Weather', 'Current weather description.'],
        'event_type' => ['Event Type', 'Current request type, such as chat, rechat, or inputtext.'],
    ];
}

function stobeWorldKnowledgeNormalizeRuleConditions(mixed $conditions): array
{
    if (is_string($conditions)) {
        $decoded = json_decode($conditions, true);
        $conditions = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($conditions)) {
        return [];
    }

    $allowedFields = array_fill_keys(array_keys(stobeWorldKnowledgeRuleConditionFields()), true);
    $normalized = [];
    foreach ($conditions as $field => $values) {
        $safeField = strtolower(trim(strval($field)));
        if (!isset($allowedFields[$safeField])) {
            continue;
        }
        $safeValues = stobeWorldKnowledgeRuleValues($values);
        if (count($safeValues) > 0) {
            $normalized[$safeField] = $safeValues;
        }
    }
    return $normalized;
}

function stobeWorldKnowledgeRuleCollectFields(mixed $value, array $fieldNames, int $depth = 0): array
{
    if ($depth > 5) {
        return [];
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }
        $value = $decoded;
    }
    if (!is_array($value)) {
        return [];
    }

    $wanted = array_fill_keys($fieldNames, true);
    $result = [];
    foreach ($value as $key => $entry) {
        $safeKey = strtolower(trim(strval($key)));
        if (isset($wanted[$safeKey]) && !is_array($entry)) {
            $result[] = $entry;
        }
        if (is_array($entry) || is_string($entry)) {
            $result = array_merge(
                $result,
                stobeWorldKnowledgeRuleCollectFields($entry, $fieldNames, $depth + 1)
            );
        }
    }
    return $result;
}

function stobeWorldKnowledgeRuleBool(mixed $value): ?bool
{
    if (function_exists('stobeParseFlexibleBool')) {
        return stobeParseFlexibleBool($value);
    }
    if (is_bool($value)) {
        return $value;
    }
    $normalized = strtolower(trim(strval($value)));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return null;
}

/**
 * Builds deterministic rule signals only from context already available to the prompt.
 */
function stobeWorldKnowledgeBuildRuleContext(
    string $npcName,
    array $npcData,
    string $speakerName,
    string $eventType
): array {
    $metadata = function_exists('normalizeNpcMetadataPayload')
        ? normalizeNpcMetadataPayload($npcData['metadata'] ?? [])
        : [];
    $extended = function_exists('normalizeNpcExtendedDataPayload')
        ? normalizeNpcExtendedDataPayload($npcData['extended_data'] ?? [])
        : [];

    $character = [
        $npcName,
        $npcData['name'] ?? '',
        $npcData['original_name'] ?? '',
        $metadata['original_name'] ?? '',
    ];
    $nearby = [$speakerName];
    $nearbyRows = function_exists('stobeExtractSceneArray')
        ? stobeExtractSceneArray($extended, 'nearby_actors')
        : [];
    if (count($nearbyRows) === 0 && function_exists('stobeExtractSceneArray')) {
        $nearbyRows = stobeExtractSceneArray($extended, 'nearby');
    }
    foreach ($nearbyRows as $entry) {
        if (!is_array($entry) || stobeWorldKnowledgeRuleBool($entry['is_animal'] ?? false) === true) {
            continue;
        }
        $nearby[] = $entry['name'] ?? '';
        $nearby[] = $entry['original_name'] ?? '';
    }
    $people = trim(strval($GLOBALS['CACHE_PEOPLE'] ?? ''));
    if ($people !== '') {
        $nearby = array_merge($nearby, preg_split('/\s*\|\s*/u', $people) ?: []);
    }

    $world = function_exists('stobeResolveWorldPromptContext')
        ? stobeResolveWorldPromptContext($npcData)
        : [];
    $geo = function_exists('getEventGeoFromNpcName') ? getEventGeoFromNpcName($npcName) : [];
    if (!is_array($geo)) {
        $geo = [];
    }

    $building = array_merge(
        stobeWorldKnowledgeRuleCollectFields($extended, ['building_name', 'indoors_name']),
        [$geo['location'] ?? '']
    );
    $town = array_merge(
        stobeWorldKnowledgeRuleCollectFields($extended, ['town_name', 'town', 'city', 'settlement', 'zone', 'zone_name']),
        [$geo['city'] ?? '', $geo['zone'] ?? '']
    );
    $region = array_merge(
        stobeWorldKnowledgeRuleCollectFields($extended, ['region']),
        [$geo['region'] ?? '']
    );
    $location = array_merge([$world['location'] ?? ''], $building, $town, $region);

    $environment = [];
    foreach (stobeWorldKnowledgeRuleCollectFields($extended, ['indoors']) as $value) {
        if (stobeWorldKnowledgeRuleBool($value) === true) {
            $environment[] = 'indoors';
            $environment[] = 'interior';
        }
    }
    foreach (stobeWorldKnowledgeRuleCollectFields($extended, ['outdoors']) as $value) {
        if (stobeWorldKnowledgeRuleBool($value) === true) {
            $environment[] = 'outdoors';
            $environment[] = 'exterior';
        }
    }
    foreach (stobeWorldKnowledgeRuleCollectFields($extended, ['in_town']) as $value) {
        if (stobeWorldKnowledgeRuleBool($value) === true) {
            $environment[] = 'in town';
        }
    }

    $factions = array_merge(
        [$npcData['faction'] ?? '', $metadata['faction'] ?? ''],
        stobeWorldKnowledgeRuleCollectFields($extended, ['faction', 'faction_name'])
    );
    $weather = array_merge(
        [$world['weather'] ?? ''],
        stobeWorldKnowledgeRuleCollectFields($extended, ['weather', 'weather_name'])
    );

    return [
        'character' => stobeWorldKnowledgeRuleValues($character),
        'nearby_character' => stobeWorldKnowledgeRuleValues($nearby),
        'race' => stobeWorldKnowledgeRuleValues([$npcData['race'] ?? '', $metadata['race'] ?? '']),
        'faction' => stobeWorldKnowledgeRuleValues($factions),
        'profile' => stobeWorldKnowledgeRuleValues([$npcData['profile_id'] ?? '', $metadata['profile_id'] ?? '']),
        'location' => stobeWorldKnowledgeRuleValues($location),
        'building' => stobeWorldKnowledgeRuleValues($building),
        'town' => stobeWorldKnowledgeRuleValues($town),
        'region' => stobeWorldKnowledgeRuleValues($region),
        'environment' => stobeWorldKnowledgeRuleValues($environment),
        'weather' => stobeWorldKnowledgeRuleValues($weather),
        'event_type' => stobeWorldKnowledgeRuleValues([$eventType]),
    ];
}

function stobeWorldKnowledgeContextRuleMatches(
    array $conditions,
    array $context,
    ?array &$reasons = null
): bool {
    $reasons = [];
    foreach (stobeWorldKnowledgeNormalizeRuleConditions($conditions) as $field => $expected) {
        $actual = stobeWorldKnowledgeRuleValues($context[$field] ?? []);
        $matched = array_values(array_intersect($expected, $actual));
        if (count($matched) === 0) {
            return false;
        }
        $reasons[] = $field . '=' . implode('|', $matched);
    }
    return true;
}

function stobeWorldKnowledgeFindRowsForRuleSelector(
    mixed $db,
    string $selectorType,
    string $selectorValue,
    int $limit
): array {
    if (!$db || !method_exists($db, 'fetchAll')) {
        return [];
    }
    $safeType = strtolower(trim($selectorType));
    if (!in_array($safeType, ['topic', 'tag'], true)) {
        return [];
    }
    $comparable = function_exists('stobeWorldKnowledgeComparableLabel')
        ? stobeWorldKnowledgeComparableLabel($selectorValue)
        : (preg_replace('/[^a-z0-9]+/', '', strtolower($selectorValue)) ?? '');
    if ($comparable === '') {
        return [];
    }

    if ($safeType === 'topic') {
        $condition = "regexp_replace(lower(COALESCE(wk.topic, '')), '[^a-z0-9]+', '', 'g') = $1
            OR EXISTS (
                SELECT 1
                FROM regexp_split_to_table(COALESCE(wk.aliases, ''), ',') AS selector_value(value)
                WHERE regexp_replace(lower(BTRIM(selector_value.value)), '[^a-z0-9]+', '', 'g') = $1
            )";
    } else {
        $condition = "EXISTS (
            SELECT 1
            FROM regexp_split_to_table(COALESCE(wk.tags, ''), ',') AS selector_value(value)
            WHERE regexp_replace(lower(BTRIM(selector_value.value)), '[^a-z0-9]+', '', 'g') = $1
        )";
    }

    $safeLimit = max(1, min(5, $limit));
    try {
        $rows = $db->fetchAll(
            "SELECT
                wk.id, wk.topic, wk.topic_desc,
                COALESCE(wk.topic_desc_basic, '') AS topic_desc_basic,
                COALESCE(wk.knowledge_class, '') AS knowledge_class,
                COALESCE(wk.knowledge_class_basic, '') AS knowledge_class_basic,
                COALESCE(wk.aliases, '') AS aliases,
                COALESCE(wk.tags, '') AS tags
             FROM world_knowledge wk
             WHERE ({$condition})
             ORDER BY LOWER(wk.topic), wk.id
             LIMIT " . intval($safeLimit),
            [$comparable]
        );
        return is_array($rows) ? $rows : [];
    } catch (Throwable $exception) {
        if (function_exists('stobeLogWarn')) {
            stobeLogWarn('World Knowledge context rule selector failed', ['error' => $exception->getMessage()]);
        }
        return [];
    }
}

function stobeWorldKnowledgeLoadContextRules(mixed $db, bool $enabledOnly = true): array
{
    if (!$db || !method_exists($db, 'fetchAll')) {
        return [];
    }
    $where = $enabledOnly ? 'WHERE enabled = TRUE' : '';
    try {
        $rows = $db->fetchAll(
            "SELECT id, label, enabled, priority, selector_type, selector_value, conditions, max_articles
             FROM world_knowledge_context_rule
             {$where}
             ORDER BY priority, id"
        );
        return is_array($rows) ? $rows : [];
    } catch (Throwable $exception) {
        if (function_exists('stobeLogWarn')) {
            stobeLogWarn('World Knowledge context rules unavailable', ['error' => $exception->getMessage()]);
        }
        return [];
    }
}

/**
 * Resolves all matching rules before ranked retrieval and reports matches to its audit row.
 */
function stobeWorldKnowledgeResolveContextRuleHints(
    string $npcName,
    array $npcData,
    string $speakerName,
    string $eventType,
    array $excludedPayloads = [],
    ?array &$auditNotes = null
): array {
    $auditNotes = [];
    if (!function_exists('stobeWorldKnowledgeRetrieverEnabled')
        || !stobeWorldKnowledgeRetrieverEnabled()
        || !function_exists('stobeWorldKnowledgeEventAllowed')
        || !stobeWorldKnowledgeEventAllowed($eventType)) {
        return [];
    }

    $db = $GLOBALS['db'] ?? null;
    $rules = stobeWorldKnowledgeLoadContextRules($db, true);
    if (count($rules) === 0) {
        return [];
    }

    $context = stobeWorldKnowledgeBuildRuleContext($npcName, $npcData, $speakerName, $eventType);
    $knowledgeTags = function_exists('parseNpcKnowledgeTags')
        ? parseNpcKnowledgeTags($npcData, $npcName)
        : [];
    $isKnowAll = in_array('knowall', $knowledgeTags, true);
    $seenPayloads = $excludedPayloads;
    $hints = [];

    foreach ($rules as $rule) {
        $conditions = stobeWorldKnowledgeNormalizeRuleConditions($rule['conditions'] ?? []);
        $reasons = [];
        if (!stobeWorldKnowledgeContextRuleMatches($conditions, $context, $reasons)) {
            continue;
        }

        $ruleId = intval($rule['id'] ?? 0);
        $ruleLabel = trim(strval($rule['label'] ?? 'Context Rule'));
        $limit = max(1, min(5, intval($rule['max_articles'] ?? 1)));
        $rows = stobeWorldKnowledgeFindRowsForRuleSelector(
            $db,
            strval($rule['selector_type'] ?? 'topic'),
            strval($rule['selector_value'] ?? ''),
            $limit
        );
        $added = 0;
        foreach ($rows as $row) {
            if ($added >= $limit || !function_exists('stobeWorldKnowledgeSelectKnowledgePayload')) {
                break;
            }
            $payload = stobeWorldKnowledgeSelectKnowledgePayload($row, $knowledgeTags, $isKnowAll);
            if (!boolval($payload['allowed'] ?? false)) {
                continue;
            }
            $line = stobeWorldKnowledgeBuildHintLine(
                strval($payload['topic'] ?? ''),
                strval($payload['desc'] ?? '')
            );
            $fingerprint = stobeWorldKnowledgeHintPayloadFingerprint($line);
            if ($line === '' || ($fingerprint !== '' && isset($seenPayloads[$fingerprint]))) {
                continue;
            }
            if ($fingerprint !== '') {
                $seenPayloads[$fingerprint] = true;
            }
            $hints[] = $line;
            $added++;
        }

        $reasonText = count($reasons) > 0 ? implode('|', $reasons) : 'always';
        $auditNotes[] = 'context rule ' . $ruleId . ' ' . $ruleLabel . ' (' . $reasonText . '):' . $added;
        if (function_exists('stobeLogInfo')) {
            stobeLogInfo('World Knowledge context rule matched', [
                'rule_id' => $ruleId,
                'label' => $ruleLabel,
                'reasons' => $reasons,
                'articles_added' => $added,
            ]);
        }
    }

    return $hints;
}

function stobeWorldKnowledgeRuleConditionsFromInput(array $input): array
{
    $conditions = [];
    foreach (array_keys(stobeWorldKnowledgeRuleConditionFields()) as $field) {
        $values = stobeWorldKnowledgeRuleValues($input['condition_' . $field] ?? '');
        if (count($values) > 0) {
            $conditions[$field] = $values;
        }
    }
    return $conditions;
}

function stobeWorldKnowledgeSaveContextRule(mixed $db, array $input): array
{
    $id = max(0, intval($input['context_rule_id'] ?? 0));
    $label = trim(strval($input['context_rule_label'] ?? ''));
    $enabled = isset($input['context_rule_enabled'])
        && in_array(strtolower(trim(strval($input['context_rule_enabled']))), ['1', 'true', 'yes', 'on'], true);
    $priority = max(-100000, min(100000, intval($input['context_rule_priority'] ?? 100)));
    $selectorType = strtolower(trim(strval($input['context_rule_selector_type'] ?? 'topic')));
    $selectorValue = trim(strval($input['context_rule_selector_value'] ?? ''));
    $maxArticles = max(1, min(5, intval($input['context_rule_max_articles'] ?? 1)));

    if ($label === '' || $selectorValue === '' || !in_array($selectorType, ['topic', 'tag'], true)) {
        return ['ok' => false, 'message' => 'Rule name, selector type, and selector value are required.', 'id' => $id];
    }
    $conditionsJson = json_encode(
        stobeWorldKnowledgeRuleConditionsFromInput($input),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($conditionsJson)) {
        return ['ok' => false, 'message' => 'Rule conditions could not be encoded.', 'id' => $id];
    }

    try {
        if ($id > 0) {
            $db->exec(
                "UPDATE world_knowledge_context_rule
                 SET label = $1,
                     enabled = $2,
                     priority = $3,
                     selector_type = $4,
                     selector_value = $5,
                     conditions = $6::jsonb,
                     max_articles = $7,
                     updated_at = NOW()
                 WHERE id = $8",
                [$label, $enabled, $priority, $selectorType, $selectorValue, $conditionsJson, $maxArticles, $id]
            );
            return ['ok' => true, 'message' => 'Context rule updated.', 'id' => $id];
        }
        $row = $db->fetchOne(
            "INSERT INTO world_knowledge_context_rule
                (label, enabled, priority, selector_type, selector_value, conditions, max_articles)
             VALUES ($1, $2, $3, $4, $5, $6::jsonb, $7)
             RETURNING id",
            [$label, $enabled, $priority, $selectorType, $selectorValue, $conditionsJson, $maxArticles]
        );
        $newId = intval($row['id'] ?? 0);
        return ['ok' => $newId > 0, 'message' => $newId > 0 ? 'Context rule created.' : 'Unable to create context rule.', 'id' => $newId];
    } catch (Throwable $exception) {
        if (function_exists('stobeLogWarn')) {
            stobeLogWarn('World Knowledge context rule save failed', ['error' => $exception->getMessage()]);
        }
        return ['ok' => false, 'message' => 'Unable to save context rule.', 'id' => $id];
    }
}

function stobeWorldKnowledgeDeleteContextRule(mixed $db, int $id): bool
{
    if ($id <= 0) {
        return false;
    }
    try {
        $db->exec('DELETE FROM world_knowledge_context_rule WHERE id = $1', [$id]);
        return true;
    } catch (Throwable $exception) {
        if (function_exists('stobeLogWarn')) {
            stobeLogWarn('World Knowledge context rule delete failed', ['error' => $exception->getMessage()]);
        }
        return false;
    }
}
