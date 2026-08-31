<?php

// Stobe intentionally ships with an empty pronunciation dictionary.
function stobeDefaultTtsPronunciationEntries(): array
{
    return [];
}

// Create the dictionary schema without seeding game-specific pronunciations.
function stobeEnsureTtsPronunciationDictionary(): bool
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        return false;
    }

    $schemaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data'
        . DIRECTORY_SEPARATOR . 'core_tts_pronunciation.sql';
    if (!is_readable($schemaPath)) {
        return false;
    }

    $schema = file_get_contents($schemaPath);
    return is_string($schema) && trim($schema) !== '' && $db->exec($schema) !== false;
}

final class TTSPronunciationDictionary
{
    private const TABLE = 'core_tts_pronunciation';

    public function isAvailable(): bool
    {
        $db = $GLOBALS['db'] ?? null;
        if (!$db) {
            return false;
        }

        $row = $db->fetchOne("SELECT to_regclass('public." . self::TABLE . "') AS relation_name");
        return is_array($row) && trim(strval($row['relation_name'] ?? '')) !== '';
    }

    public function getRows(string $tagFilter = ''): array
    {
        if (!$this->isAvailable()) {
            return stobeDefaultTtsPronunciationEntries();
        }

        $rows = $GLOBALS['db']->fetchAll(
            'SELECT id, source_text, spoken_text, npc_names, races, oghma_tags,
                    is_builtin, enabled, created_at, updated_at
             FROM public.' . self::TABLE . '
             ORDER BY is_builtin DESC, LOWER(source_text), id
             LIMIT 1024'
        );
        $rows = is_array($rows) ? $rows : [];
        $tagFilter = strtolower(trim($tagFilter));
        if ($tagFilter === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($tagFilter): bool {
            return in_array(
                $tagFilter,
                stobeTtsPronunciationNormalizeTags($row['oghma_tags'] ?? ''),
                true
            );
        }));
    }

    public function getAvailableTags(): array
    {
        $tags = [];
        foreach ($this->getRows() as $row) {
            foreach (stobeTtsPronunciationNormalizeTags($row['oghma_tags'] ?? '') as $tag) {
                $tags[$tag] = $tag;
            }
        }
        natcasesort($tags);
        return array_values($tags);
    }

    public function saveCustom(
        ?int $id,
        string $source,
        string $spoken,
        string $npcNames,
        string $races,
        string $oghmaTags,
        bool $enabled
    ): bool {
        if (!$this->isAvailable()) {
            return false;
        }

        $source = trim($source);
        $spoken = trim($spoken);
        if ($source === '' || $spoken === '' || strlen($source) > 120 || strlen($spoken) > 240) {
            return false;
        }

        $normalizedNames = implode(', ', array_slice(stobeTtsPronunciationNormalizeScopeValues($npcNames), 0, 32));
        $normalizedRaces = implode(', ', array_slice(stobeTtsPronunciationNormalizeScopeValues($races), 0, 32));
        $normalizedTags = implode(', ', array_slice(stobeTtsPronunciationNormalizeTags($oghmaTags), 0, 32));
        $params = [
            $source,
            $spoken,
            substr($normalizedNames, 0, 512),
            substr($normalizedRaces, 0, 512),
            substr($normalizedTags, 0, 512),
            $enabled,
        ];

        if ($id !== null && $id > 0) {
            $params[] = $id;
            return $GLOBALS['db']->exec(
                'UPDATE public.' . self::TABLE . '
                 SET source_text = $1,
                     spoken_text = $2,
                     npc_names = $3,
                     races = $4,
                     oghma_tags = $5,
                     enabled = $6,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = $7 AND is_builtin = FALSE',
                $params
            ) !== false;
        }

        return $GLOBALS['db']->exec(
            'INSERT INTO public.' . self::TABLE . '
                (source_text, spoken_text, npc_names, races, oghma_tags, is_builtin, enabled, updated_at)
             VALUES ($1, $2, $3, $4, $5, FALSE, $6, CURRENT_TIMESTAMP)',
            $params
        ) !== false;
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        if ($id <= 0 || !$this->isAvailable()) {
            return false;
        }

        return $GLOBALS['db']->exec(
            'UPDATE public.' . self::TABLE . '
             SET enabled = $1, updated_at = CURRENT_TIMESTAMP
             WHERE id = $2',
            [$enabled, $id]
        ) !== false;
    }

    public function deleteCustom(int $id): bool
    {
        if ($id <= 0 || !$this->isAvailable()) {
            return false;
        }

        return $GLOBALS['db']->exec(
            'DELETE FROM public.' . self::TABLE . '
             WHERE id = $1 AND is_builtin = FALSE',
            [$id]
        ) !== false;
    }
}

function stobeTtsPronunciationBoolean(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim(strval($value))), ['1', 't', 'true', 'yes', 'on'], true);
}

function stobeTtsPronunciationNormalizeTags(mixed $tags): array
{
    $normalized = [];
    foreach (explode(',', strval($tags)) as $tag) {
        $tag = strtolower(trim($tag));
        if ($tag === '' || strlen($tag) > 64) {
            continue;
        }
        $normalized[$tag] = $tag;
    }
    return array_values($normalized);
}

function stobeTtsPronunciationNormalizeScopeValues(mixed $values): array
{
    $normalized = [];
    foreach (explode(',', strval($values)) as $value) {
        $value = trim($value);
        if ($value === '' || strlen($value) > 120) {
            continue;
        }
        $key = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $normalized[$key] = $value;
    }
    return array_values($normalized);
}

function stobeTtsPronunciationValueMatches(string $value, array $allowedValues): bool
{
    $value = function_exists('mb_strtolower')
        ? mb_strtolower(trim($value), 'UTF-8')
        : strtolower(trim($value));
    if ($value === '') {
        return false;
    }

    foreach ($allowedValues as $allowedValue) {
        $allowedValue = function_exists('mb_strtolower')
            ? mb_strtolower(trim(strval($allowedValue)), 'UTF-8')
            : strtolower(trim(strval($allowedValue)));
        if ($value === $allowedValue) {
            return true;
        }
    }
    return false;
}

// Resolve pronunciation filters from the active server-owned NPC record.
function stobeTtsPronunciationSpeakerScope(string $actor, array|false $actorData = false): array
{
    $scope = [
        'knowledge_tags' => [],
        'npc_name' => '',
        'race' => '',
    ];
    $actor = trim($actor);
    if ($actor === '') {
        return $scope;
    }

    $isNarrator = strcasecmp($actor, 'The Narrator') === 0;
    if (function_exists('stobeIsNarratorName')) {
        $isNarrator = stobeIsNarratorName($actor);
    }
    if ($isNarrator) {
        return $scope;
    }

    static $rowCache = [];
    $cacheKey = function_exists('mb_strtolower')
        ? mb_strtolower($actor, 'UTF-8')
        : strtolower($actor);
    if (!array_key_exists($cacheKey, $rowCache)) {
        $db = $GLOBALS['db'] ?? null;
        $rowCache[$cacheKey] = $db ? $db->fetchOne(
            "SELECT name, race, world_knowledge_tags
             FROM core_npc
             WHERE LOWER(name) = LOWER($1)
             ORDER BY gamets_last_updated DESC, updated_at DESC, id DESC
             LIMIT 1",
            [$actor]
        ) : false;
    }

    $resolved = is_array($rowCache[$cacheKey] ?? null) ? $rowCache[$cacheKey] : false;
    if (!$resolved && is_array($actorData)) {
        $candidateName = trim(strval($actorData['name'] ?? $actorData['npc_name'] ?? ''));
        if ($candidateName !== '' && strcasecmp($actor, $candidateName) === 0) {
            $resolved = $actorData;
        }
    }
    if (!is_array($resolved)) {
        return $scope;
    }

    $resolvedName = trim(strval($resolved['name'] ?? $resolved['npc_name'] ?? ''));
    if ($resolvedName === '' || strcasecmp($actor, $resolvedName) !== 0) {
        return $scope;
    }

    $scope['npc_name'] = $resolvedName;
    $scope['race'] = trim(strval($resolved['race'] ?? ''));
    $scope['knowledge_tags'] = stobeTtsPronunciationNormalizeTags(
        $resolved['world_knowledge_tags'] ?? $resolved['oghma_knowledge_tags'] ?? ''
    );
    return $scope;
}

// Require every populated scope while allowing alternatives within one field.
function stobeTtsPronunciationEntryAllows(
    array $entry,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): bool {
    $entryNames = stobeTtsPronunciationNormalizeScopeValues($entry['npc_names'] ?? '');
    if (!empty($entryNames) && !stobeTtsPronunciationValueMatches($npcName, $entryNames)) {
        return false;
    }

    $entryRaces = stobeTtsPronunciationNormalizeScopeValues($entry['races'] ?? '');
    if (!empty($entryRaces) && !stobeTtsPronunciationValueMatches($race, $entryRaces)) {
        return false;
    }

    $entryTags = stobeTtsPronunciationNormalizeTags($entry['oghma_tags'] ?? '');
    if (empty($entryTags)) {
        return true;
    }

    $knowledgeTags = array_values(array_unique(array_map(
        static fn($tag): string => strtolower(trim(strval($tag))),
        $knowledgeTags ?? []
    )));
    return in_array('knowall', $knowledgeTags, true)
        || !empty(array_intersect($entryTags, $knowledgeTags));
}

function stobeTtsPronunciationRows(): array
{
    static $cachedRows = null;
    if ($cachedRows === null) {
        $cachedRows = (new TTSPronunciationDictionary())->getRows();
    }
    return $cachedRows;
}

// Resolve one active replacement per written form, preferring scoped custom rows.
function stobeTtsPronunciationEntries(
    ?array $rows = null,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): array {
    if ($rows === null) {
        $rows = stobeTtsPronunciationRows();
    }

    $resolved = [];
    foreach (array_slice($rows, 0, 1024) as $row) {
        if (!stobeTtsPronunciationBoolean($row['enabled'] ?? true)
            || !stobeTtsPronunciationEntryAllows($row, $knowledgeTags, $npcName, $race)) {
            continue;
        }

        $source = trim(strval($row['source_text'] ?? $row['source'] ?? ''));
        $spoken = trim(strval($row['spoken_text'] ?? $row['spoken'] ?? ''));
        if ($source === '' || $spoken === '' || strlen($source) > 120 || strlen($spoken) > 240) {
            continue;
        }

        $normalizedSource = function_exists('mb_strtolower')
            ? mb_strtolower($source, 'UTF-8')
            : strtolower($source);
        $specificity = 0;
        $specificity += !empty(stobeTtsPronunciationNormalizeScopeValues($row['npc_names'] ?? '')) ? 1 : 0;
        $specificity += !empty(stobeTtsPronunciationNormalizeScopeValues($row['races'] ?? '')) ? 1 : 0;
        $specificity += !empty(stobeTtsPronunciationNormalizeTags($row['oghma_tags'] ?? '')) ? 1 : 0;
        $priority = (stobeTtsPronunciationBoolean($row['is_builtin'] ?? false) ? 0 : 10) + $specificity;
        if (isset($resolved[$normalizedSource]) && $resolved[$normalizedSource]['priority'] > $priority) {
            continue;
        }

        $resolved[$normalizedSource] = [
            'source' => $source,
            'spoken' => $spoken,
            'priority' => $priority,
        ];
    }

    $entries = array_values($resolved);
    usort($entries, static function (array $left, array $right): int {
        return strlen($right['source']) <=> strlen($left['source']);
    });
    return array_slice($entries, 0, 256);
}

// Rewrite only synthesized speech using whole-term, non-cascading replacements.
function stobeApplyTtsPronunciationDictionary(
    string $text,
    ?array $rows = null,
    ?array $knowledgeTags = null,
    string $npcName = '',
    string $race = ''
): string {
    if ($text === '' || !empty($GLOBALS['STOBE_TTS_PRONUNCIATION_BYPASS'])) {
        return $text;
    }

    $entries = stobeTtsPronunciationEntries($rows, $knowledgeTags, $npcName, $race);
    if (empty($entries)) {
        return $text;
    }

    $replacements = [];
    $patterns = [];
    foreach ($entries as $entry) {
        $source = strval($entry['source'] ?? '');
        if ($source === '') {
            continue;
        }
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($source, 'UTF-8')
            : strtolower($source);
        $replacements[$normalized] = strval($entry['spoken'] ?? '');
        $patterns[] = preg_quote($source, '~');
    }
    if (empty($patterns)) {
        return $text;
    }

    $pattern = '~(?<![\p{L}\p{N}_])(?:' . implode('|', $patterns) . ')(?![\p{L}\p{N}_])~iu';
    $replaced = preg_replace_callback($pattern, static function (array $match) use ($replacements): string {
        $matched = strval($match[0] ?? '');
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($matched, 'UTF-8')
            : strtolower($matched);
        return $replacements[$normalized] ?? $matched;
    }, $text);

    return is_string($replaced) ? $replaced : $text;
}
