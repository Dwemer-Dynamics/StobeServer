<?php

/**
 * Loads the read-only vanilla catalog used by ingestion, prompts, and the UI.
 */
function stobeWorldStateCatalog(): array
{
    static $catalog = null;
    if (is_array($catalog)) {
        return $catalog;
    }

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
        . 'world_state' . DIRECTORY_SEPARATOR . 'vanilla_world_state_catalog.json';
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $catalog = is_array($decoded) ? $decoded : ['queries' => []];
    return $catalog;
}

function stobeWorldStateCatalogIndex(): array
{
    static $index = null;
    if (is_array($index)) {
        return $index;
    }

    $index = [];
    foreach (stobeWorldStateCatalog()['queries'] ?? [] as $query) {
        if (!is_array($query)) {
            continue;
        }
        $queryId = trim(strval($query['query_id'] ?? ''));
        if ($queryId !== '') {
            $index[$queryId] = $query;
        }
    }
    return $index;
}

function stobeWorldStateCatalogSha256(): string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
        . 'world_state' . DIRECTORY_SEPARATOR . 'vanilla_world_state_catalog.json';
    $hash = @hash_file('sha256', $path);
    return is_string($hash) ? strtolower($hash) : '';
}

function stobeWorldStateNormalizeTopics(mixed $topics): array
{
    if (is_string($topics)) {
        $decoded = json_decode($topics, true);
        $topics = is_array($decoded) ? $decoded : preg_split('/[\r\n,]+/', $topics);
    }
    if (!is_array($topics)) {
        return [];
    }

    $normalized = [];
    $seen = [];
    foreach ($topics as $topic) {
        $topic = trim(strval($topic));
        $key = strtolower($topic);
        if ($topic === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $normalized[] = $topic;
    }
    return array_slice($normalized, 0, 64);
}

function stobeWorldStateCatalogAddendumRows(): array
{
    $rows = [];
    $catalogSha = stobeWorldStateCatalogSha256();
    foreach (stobeWorldStateCatalogIndex() as $queryId => $query) {
        $rows[$queryId] = [
            'query_id' => $queryId,
            'query_name' => trim(strval($query['query_name'] ?? '')),
            'source_mod' => trim(strval($query['source_mod'] ?? '')),
            'origin' => 'vanilla',
            'matched_topics' => stobeWorldStateNormalizeTopics(
                $query['world_knowledge']['matched_topics'] ?? []
            ),
            'when_true' => trim(strval($query['prompt_addendum']['when_true'] ?? '')),
            'when_false' => trim(strval($query['prompt_addendum']['when_false'] ?? '')),
            'enabled' => true,
            'catalog_sha256' => $catalogSha,
            'is_custom' => false,
        ];
    }
    return $rows;
}

/**
 * Refreshes generated vanilla addenda without changing custom overrides.
 */
function stobeWorldStateSeedBuiltinAddenda(): int
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        throw new RuntimeException('Database handle is unavailable');
    }

    $rows = stobeWorldStateCatalogAddendumRows();
    $txStarted = false;
    try {
        if ($db->exec('BEGIN') === false) {
            throw new RuntimeException('Could not start world-state addendum seed transaction');
        }
        $txStarted = true;

        foreach ($rows as $row) {
            $topicsJson = json_encode(
                $row['matched_topics'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $ok = $db->exec(
                'INSERT INTO world_state_addendum (
                    query_id, query_name, source_mod, origin, matched_topics,
                    when_true, when_false, enabled, catalog_sha256, created_at, updated_at
                 ) VALUES ($1, $2, $3, $4, $5::jsonb, $6, $7, TRUE, $8, NOW(), NOW())
                 ON CONFLICT (query_id) DO UPDATE SET
                    query_name = EXCLUDED.query_name,
                    source_mod = EXCLUDED.source_mod,
                    origin = EXCLUDED.origin,
                    matched_topics = EXCLUDED.matched_topics,
                    when_true = EXCLUDED.when_true,
                    when_false = EXCLUDED.when_false,
                    enabled = EXCLUDED.enabled,
                    catalog_sha256 = EXCLUDED.catalog_sha256,
                    updated_at = NOW()',
                [
                    $row['query_id'],
                    $row['query_name'],
                    $row['source_mod'],
                    $row['origin'],
                    is_string($topicsJson) ? $topicsJson : '[]',
                    $row['when_true'],
                    $row['when_false'],
                    $row['catalog_sha256'],
                ]
            );
            if ($ok === false) {
                throw new RuntimeException('Could not seed world-state addendum ' . $row['query_id']);
            }
        }

        $existingRows = $db->fetchAll(
            "SELECT query_id FROM world_state_addendum WHERE origin = 'vanilla'"
        );
        foreach ($existingRows as $existingRow) {
            $queryId = trim(strval($existingRow['query_id'] ?? ''));
            if ($queryId !== '' && !isset($rows[$queryId])) {
                $db->exec(
                    "DELETE FROM world_state_addendum WHERE query_id = $1 AND origin = 'vanilla'",
                    [$queryId]
                );
            }
        }

        if ($db->exec('COMMIT') === false) {
            throw new RuntimeException('Could not commit world-state addendum seed transaction');
        }
        $txStarted = false;
    } catch (Throwable $exception) {
        if ($txStarted) {
            $db->exec('ROLLBACK');
        }
        throw $exception;
    }

    return count($rows);
}

function stobeWorldStateAddendumRows(bool $enabledOnly = false): array
{
    try {
        $sql = 'SELECT query_id, query_name, source_mod, origin, matched_topics,
                       when_true, when_false, enabled, catalog_sha256, is_custom
                FROM combined_world_state_addendum';
        if ($enabledOnly) {
            $sql .= ' WHERE enabled = TRUE';
        }
        $sql .= ' ORDER BY LOWER(query_name), query_id';
        $rows = $GLOBALS['db']->fetchAll($sql);
    } catch (Throwable $exception) {
        $rows = array_values(stobeWorldStateCatalogAddendumRows());
        if ($enabledOnly) {
            $rows = array_values(array_filter(
                $rows,
                static fn(array $row): bool => boolval($row['enabled'] ?? false)
            ));
        }
    }

    foreach ($rows as &$row) {
        $row['matched_topics'] = stobeWorldStateNormalizeTopics($row['matched_topics'] ?? []);
        $row['enabled'] = stobeWorldStateParseBool($row['enabled'] ?? false) === true;
        $row['is_custom'] = stobeWorldStateParseBool($row['is_custom'] ?? false) === true;
    }
    unset($row);
    return $rows;
}

function stobeWorldStateAddendumIndex(): array
{
    $index = [];
    foreach (stobeWorldStateAddendumRows() as $row) {
        $queryId = trim(strval($row['query_id'] ?? ''));
        if ($queryId !== '') {
            $index[$queryId] = $row;
        }
    }
    return $index;
}

function stobeWorldStateSaveCustomAddendum(
    string $queryId,
    array $topics,
    string $whenTrue,
    string $whenFalse,
    bool $enabled
): bool {
    $queryId = trim($queryId);
    if ($queryId === '') {
        return false;
    }

    $base = $GLOBALS['db']->fetchOne(
        'SELECT query_name, source_mod
         FROM world_state_addendum
         WHERE query_id = $1
         LIMIT 1',
        [$queryId]
    );
    if (!$base) {
        return false;
    }

    $topicsJson = json_encode(
        stobeWorldStateNormalizeTopics($topics),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    return $GLOBALS['db']->exec(
        'INSERT INTO world_state_addendum_custom (
            query_id, query_name, source_mod, origin, matched_topics,
            when_true, when_false, enabled, created_at, updated_at
         ) VALUES ($1, $2, $3, $4, $5::jsonb, $6, $7, $8, NOW(), NOW())
         ON CONFLICT (query_id) DO UPDATE SET
            query_name = EXCLUDED.query_name,
            source_mod = EXCLUDED.source_mod,
            origin = EXCLUDED.origin,
            matched_topics = EXCLUDED.matched_topics,
            when_true = EXCLUDED.when_true,
            when_false = EXCLUDED.when_false,
            enabled = EXCLUDED.enabled,
            updated_at = NOW()',
        [
            $queryId,
            trim(strval($base['query_name'] ?? '')),
            trim(strval($base['source_mod'] ?? '')),
            'custom',
            is_string($topicsJson) ? $topicsJson : '[]',
            trim($whenTrue),
            trim($whenFalse),
            $enabled,
        ]
    ) !== false;
}

function stobeWorldStateResetCustomAddendum(string $queryId): bool
{
    $queryId = trim($queryId);
    if ($queryId === '') {
        return false;
    }
    return $GLOBALS['db']->exec(
        'DELETE FROM world_state_addendum_custom WHERE query_id = $1',
        [$queryId]
    ) !== false;
}

/**
 * Parses a mod world-state CSV into validated custom addendum rows.
 */
function stobeWorldStateReadModAddendaCsv(string $path): array
{
    $size = @filesize($path);
    if ($size === false || $size <= 0) {
        return ['ok' => false, 'error' => 'The CSV file is empty or could not be read.'];
    }
    if ($size > 5 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'The CSV file exceeds the 5 MB limit.'];
    }

    $csvData = @file_get_contents($path);
    if (!is_string($csvData)) {
        return ['ok' => false, 'error' => 'The CSV file could not be read.'];
    }
    if (substr($csvData, 0, 3) === "\xEF\xBB\xBF") {
        $csvData = substr($csvData, 3);
    }
    if (strpos($csvData, "\x00") !== false) {
        if (!function_exists('mb_convert_encoding')) {
            return ['ok' => false, 'error' => 'UTF-16 CSV files are not supported by this server.'];
        }
        $csvData = mb_convert_encoding($csvData, 'UTF-8', 'UTF-16');
    } elseif (
        function_exists('mb_check_encoding')
        && !mb_check_encoding($csvData, 'UTF-8')
    ) {
        $csvData = mb_convert_encoding($csvData, 'UTF-8', 'Windows-1252');
    }

    $stream = fopen('php://memory', 'r+');
    if ($stream === false) {
        return ['ok' => false, 'error' => 'The CSV file could not be opened.'];
    }
    fwrite($stream, $csvData);
    rewind($stream);

    $header = fgetcsv($stream, 0, ',');
    if (!is_array($header) || count($header) === 0) {
        fclose($stream);
        return ['ok' => false, 'error' => 'The CSV header is missing.'];
    }

    $headerMap = [];
    foreach ($header as $index => $column) {
        $key = strtolower(trim(strval($column)));
        if ($key !== '') {
            $headerMap[$key] = intval($index);
        }
    }
    $columnIndex = static function (array $aliases) use ($headerMap): ?int {
        foreach ($aliases as $alias) {
            if (array_key_exists($alias, $headerMap)) {
                return intval($headerMap[$alias]);
            }
        }
        return null;
    };

    $queryIdIndex = $columnIndex(['query_id', 'queryid', 'id', 'stringid']);
    if ($queryIdIndex === null) {
        fclose($stream);
        return ['ok' => false, 'error' => 'The CSV must include a query_id column.'];
    }
    $topicsIndex = $columnIndex(['world_knowledge_topics', 'matched_topics', 'topics']);
    $whenTrueIndex = $columnIndex(['when_true', 'true_addendum']);
    $whenFalseIndex = $columnIndex(['when_false', 'false_addendum']);
    $enabledIndex = $columnIndex(['enabled']);

    $rows = [];
    $invalid = [];
    $seen = [];
    $lineNumber = 1;
    while (($csvRow = fgetcsv($stream, 0, ',')) !== false) {
        $lineNumber++;
        if (count($rows) + count($invalid) >= 5000) {
            fclose($stream);
            return ['ok' => false, 'error' => 'The CSV exceeds the 5,000 row limit.'];
        }
        if (count(array_filter(
            $csvRow,
            static fn(mixed $value): bool => trim(strval($value)) !== ''
        )) === 0) {
            continue;
        }

        $queryId = stobeWorldStateValidQueryId($csvRow[$queryIdIndex] ?? '');
        if ($queryId === '') {
            $invalid[] = ['line' => $lineNumber, 'reason' => 'invalid query_id'];
            continue;
        }
        if (isset($seen[$queryId])) {
            $invalid[] = ['line' => $lineNumber, 'reason' => 'duplicate query_id'];
            continue;
        }
        $seen[$queryId] = true;

        $enabled = null;
        if ($enabledIndex !== null) {
            $enabledRaw = trim(strval($csvRow[$enabledIndex] ?? ''));
            if ($enabledRaw !== '') {
                $enabled = stobeWorldStateParseBool($enabledRaw);
                if ($enabled === null) {
                    $invalid[] = ['line' => $lineNumber, 'reason' => 'invalid enabled value'];
                    continue;
                }
            }
        }

        $topics = null;
        if ($topicsIndex !== null) {
            $topicsRaw = trim(strval($csvRow[$topicsIndex] ?? ''));
            $topics = str_contains($topicsRaw, '|')
                ? stobeWorldStateNormalizeTopics(preg_split('/\s*\|\s*/', $topicsRaw))
                : stobeWorldStateNormalizeTopics($topicsRaw);
        }
        $whenTrue = $whenTrueIndex === null
            ? null
            : trim(strval($csvRow[$whenTrueIndex] ?? ''));
        $whenFalse = $whenFalseIndex === null
            ? null
            : trim(strval($csvRow[$whenFalseIndex] ?? ''));
        if (
            ($whenTrue !== null && strlen($whenTrue) > 20000)
            || ($whenFalse !== null && strlen($whenFalse) > 20000)
        ) {
            $invalid[] = ['line' => $lineNumber, 'reason' => 'addendum text exceeds 20,000 characters'];
            continue;
        }

        $rows[] = [
            'query_id' => $queryId,
            'matched_topics' => $topics,
            'when_true' => $whenTrue,
            'when_false' => $whenFalse,
            'enabled' => $enabled,
        ];
    }
    fclose($stream);

    return [
        'ok' => true,
        'error' => '',
        'rows' => $rows,
        'invalid' => $invalid,
    ];
}

/**
 * Immediately merges parsed mod rows into custom world-state addenda.
 */
function stobeWorldStateImportModAddendaRows(array $rows): array
{
    $db = $GLOBALS['db'] ?? null;
    if (!$db) {
        throw new RuntimeException('Database handle is unavailable');
    }

    $knownRows = $db->fetchAll(
        'SELECT d.query_id, d.is_vanilla,
                e.matched_topics, e.when_true, e.when_false, e.enabled,
                CASE WHEN c.query_id IS NULL THEN FALSE ELSE TRUE END AS is_custom
         FROM world_state_definition d
         INNER JOIN combined_world_state_addendum e ON e.query_id = d.query_id
         LEFT JOIN world_state_addendum_custom c ON c.query_id = d.query_id'
    );
    $knownByQuery = [];
    foreach ($knownRows as $knownRow) {
        $queryId = stobeWorldStateValidQueryId($knownRow['query_id'] ?? '');
        if ($queryId !== '') {
            $knownByQuery[$queryId] = $knownRow;
        }
    }

    $result = [
        'imported' => 0,
        'inserted' => 0,
        'updated' => 0,
        'unknown' => 0,
        'vanilla' => 0,
        'unknown_ids' => [],
        'vanilla_ids' => [],
    ];
    $txStarted = false;
    try {
        if ($db->exec('BEGIN') === false) {
            throw new RuntimeException('Could not start world-state CSV import transaction');
        }
        $txStarted = true;

        foreach ($rows as $row) {
            $queryId = stobeWorldStateValidQueryId($row['query_id'] ?? '');
            $known = $knownByQuery[$queryId] ?? null;
            if (!is_array($known)) {
                $result['unknown']++;
                $result['unknown_ids'][] = $queryId;
                continue;
            }
            if (stobeWorldStateParseBool($known['is_vanilla'] ?? false) === true) {
                $result['vanilla']++;
                $result['vanilla_ids'][] = $queryId;
                continue;
            }

            $topics = is_array($row['matched_topics'] ?? null)
                ? $row['matched_topics']
                : stobeWorldStateNormalizeTopics($known['matched_topics'] ?? []);
            $whenTrue = array_key_exists('when_true', $row) && $row['when_true'] !== null
                ? strval($row['when_true'])
                : strval($known['when_true'] ?? '');
            $whenFalse = array_key_exists('when_false', $row) && $row['when_false'] !== null
                ? strval($row['when_false'])
                : strval($known['when_false'] ?? '');
            $enabled = array_key_exists('enabled', $row) && $row['enabled'] !== null
                ? boolval($row['enabled'])
                : true;

            if (!stobeWorldStateSaveCustomAddendum(
                $queryId,
                $topics,
                $whenTrue,
                $whenFalse,
                $enabled
            )) {
                throw new RuntimeException('Could not import world-state addendum ' . $queryId);
            }

            $wasCustom = stobeWorldStateParseBool($known['is_custom'] ?? false) === true;
            $result[$wasCustom ? 'updated' : 'inserted']++;
            $result['imported']++;
        }

        if ($db->exec('COMMIT') === false) {
            throw new RuntimeException('Could not commit world-state CSV import transaction');
        }
        $txStarted = false;
    } catch (Throwable $exception) {
        if ($txStarted) {
            $db->exec('ROLLBACK');
        }
        throw $exception;
    }

    stobeLogInfo('World-state mod addenda CSV imported', [
        'imported' => $result['imported'],
        'inserted' => $result['inserted'],
        'updated' => $result['updated'],
        'unknown' => $result['unknown'],
        'vanilla' => $result['vanilla'],
    ]);
    return $result;
}

function stobeWorldStateParseBool(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return intval($value) !== 0;
    }
    $normalized = strtolower(trim(strval($value)));
    if (in_array($normalized, ['1', 't', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'f', 'false', 'no', 'off'], true)) {
        return false;
    }
    return null;
}

function stobeWorldStateValidQueryId(mixed $value): string
{
    $queryId = trim(strval($value));
    if (
        $queryId === ''
        || strlen($queryId) > 255
        || preg_match('/[\x00-\x1F\x7F]/', $queryId) === 1
    ) {
        return '';
    }
    return $queryId;
}

function stobeWorldStateNormalizeRuntimeRules(mixed $rules): array
{
    if (is_string($rules)) {
        $decoded = json_decode($rules, true);
        $rules = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($rules)) {
        return [];
    }

    $normalized = [];
    foreach (array_slice($rules, 0, 64) as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $normalized[] = [
            'category' => substr(trim(strval($rule['category'] ?? '')), 0, 64),
            'target_id' => substr(trim(strval($rule['target_id'] ?? '')), 0, 255),
            'target_name' => substr(trim(strval($rule['target_name'] ?? '')), 0, 255),
            'target_type' => substr(trim(strval($rule['target_type'] ?? 'UNKNOWN')), 0, 64),
            'expected_value' => intval($rule['expected_value'] ?? 0),
            'condition_text' => substr(trim(strval($rule['condition_text'] ?? '')), 0, 1000),
            'inverse_text' => substr(trim(strval($rule['inverse_text'] ?? '')), 0, 1000),
        ];
    }
    return $normalized;
}

/**
 * Stores the loaded query catalog and creates disabled addendum shells for mods.
 */
function stobeStoreWorldStateDefinitions(array $payload): array
{
    $definitions = $payload['definitions'] ?? [];
    if (!is_array($definitions)) {
        return ['processed' => 0, 'rejected' => 0, 'deactivated' => 0];
    }

    $catalog = stobeWorldStateCatalogIndex();
    $runtimeCatalogId = substr(trim(strval($payload['runtime_catalog_id'] ?? '')), 0, 128);
    $fullSnapshot = stobeWorldStateParseBool(
        $payload['definitions_full_snapshot'] ?? false
    ) === true;
    $entries = [];
    $rejected = max(0, count($definitions) - 4096);

    foreach (array_slice($definitions, 0, 4096) as $definition) {
        if (!is_array($definition)) {
            $rejected++;
            continue;
        }
        $queryId = stobeWorldStateValidQueryId($definition['query_id'] ?? '');
        if ($queryId === '') {
            $rejected++;
            continue;
        }
        $queryName = substr(trim(strval($definition['query_name'] ?? '')), 0, 255);
        if ($queryName === '') {
            $queryName = trim(strval($catalog[$queryId]['query_name'] ?? $queryId));
        }
        $sourceMod = substr(trim(strval($definition['source_mod'] ?? '')), 0, 255);
        $entries[$queryId] = [
            'query_id' => $queryId,
            'query_name' => $queryName,
            'source_mod' => $sourceMod,
            'player_involvement' => stobeWorldStateParseBool(
                $definition['player_involvement'] ?? false
            ) === true,
            'rules' => stobeWorldStateNormalizeRuntimeRules($definition['rules'] ?? []),
            'is_vanilla' => isset($catalog[$queryId]),
        ];
    }

    if ($fullSnapshot && count($entries) === 0) {
        return ['processed' => -1, 'rejected' => $rejected, 'deactivated' => 0];
    }

    $db = $GLOBALS['db'];
    $txStarted = false;
    $deactivated = 0;
    try {
        if ($db->exec('BEGIN') === false) {
            throw new RuntimeException('Could not start world-state definition transaction');
        }
        $txStarted = true;

        if ($fullSnapshot) {
            $deactivateResult = $db->exec(
                'UPDATE world_state_definition SET active = FALSE, updated_at = NOW()
                 WHERE active = TRUE'
            );
            if ($deactivateResult === false) {
                throw new RuntimeException('Could not deactivate prior world-state definitions');
            }
            $deactivated = $db->affectedRows($deactivateResult);
        }

        foreach ($entries as $entry) {
            $rulesJson = json_encode(
                $entry['rules'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $ok = $db->exec(
                'INSERT INTO world_state_definition (
                    query_id, query_name, source_mod, player_involvement, rules,
                    runtime_catalog_id, is_vanilla, active,
                    first_seen_at, last_seen_at, updated_at
                 ) VALUES ($1, $2, $3, $4, $5::jsonb, $6, $7, TRUE, NOW(), NOW(), NOW())
                 ON CONFLICT (query_id) DO UPDATE SET
                    query_name = EXCLUDED.query_name,
                    source_mod = EXCLUDED.source_mod,
                    player_involvement = EXCLUDED.player_involvement,
                    rules = EXCLUDED.rules,
                    runtime_catalog_id = EXCLUDED.runtime_catalog_id,
                    is_vanilla = EXCLUDED.is_vanilla,
                    active = TRUE,
                    last_seen_at = NOW(),
                    updated_at = NOW()',
                [
                    $entry['query_id'],
                    $entry['query_name'],
                    $entry['source_mod'],
                    $entry['player_involvement'],
                    is_string($rulesJson) ? $rulesJson : '[]',
                    $runtimeCatalogId,
                    $entry['is_vanilla'],
                ]
            );
            if ($ok === false) {
                throw new RuntimeException('Could not upsert world-state definition');
            }

            if (!$entry['is_vanilla']) {
                $ok = $db->exec(
                    "INSERT INTO world_state_addendum (
                        query_id, query_name, source_mod, origin, matched_topics,
                        when_true, when_false, enabled, catalog_sha256,
                        created_at, updated_at
                     ) VALUES ($1, $2, $3, 'mod', '[]'::jsonb, '', '', FALSE, '', NOW(), NOW())
                     ON CONFLICT (query_id) DO UPDATE SET
                        query_name = EXCLUDED.query_name,
                        source_mod = EXCLUDED.source_mod,
                        updated_at = NOW()
                     WHERE world_state_addendum.origin <> 'vanilla'",
                    [$entry['query_id'], $entry['query_name'], $entry['source_mod']]
                );
                if ($ok === false) {
                    throw new RuntimeException('Could not create mod world-state addendum');
                }
            }
        }

        if ($db->exec('COMMIT') === false) {
            throw new RuntimeException('Could not commit world-state definition transaction');
        }
        $txStarted = false;
    } catch (Throwable $exception) {
        if ($txStarted) {
            $db->exec('ROLLBACK');
        }
        stobeLogException($exception, 'world_state definition persistence failed');
        return ['processed' => -1, 'rejected' => $rejected, 'deactivated' => 0];
    }

    return [
        'processed' => count($entries),
        'rejected' => $rejected,
        'deactivated' => $deactivated,
    ];
}

function stobeWorldStateDefinitionRows(bool $activeOnly = true): array
{
    try {
        $sql = 'SELECT query_id, query_name, source_mod, player_involvement,
                       rules, runtime_catalog_id, is_vanilla, active,
                       first_seen_at, last_seen_at
                FROM world_state_definition';
        if ($activeOnly) {
            $sql .= ' WHERE active = TRUE';
        }
        $sql .= ' ORDER BY LOWER(query_name), query_id';
        $rows = $GLOBALS['db']->fetchAll($sql);
    } catch (Throwable $exception) {
        return [];
    }

    foreach ($rows as &$row) {
        $row['rules'] = stobeWorldStateNormalizeRuntimeRules($row['rules'] ?? []);
        $row['player_involvement'] = stobeWorldStateParseBool(
            $row['player_involvement'] ?? false
        ) === true;
        $row['is_vanilla'] = stobeWorldStateParseBool($row['is_vanilla'] ?? false) === true;
        $row['active'] = stobeWorldStateParseBool($row['active'] ?? false) === true;
    }
    unset($row);
    return $rows;
}

/**
 * Persists evaluated values separately from the static query definitions.
 */
function stobeStoreWorldStateQueryResults(array $payload): array
{
    $db = $GLOBALS['db'];
    $catalog = stobeWorldStateCatalogIndex();
    $definitionNames = [];
    try {
        foreach ($db->fetchAll(
            'SELECT query_id, query_name FROM world_state_definition WHERE active = TRUE'
        ) as $definition) {
            $queryId = stobeWorldStateValidQueryId($definition['query_id'] ?? '');
            if ($queryId !== '') {
                $definitionNames[$queryId] = trim(strval($definition['query_name'] ?? ''));
            }
        }
    } catch (Throwable $exception) {
        $definitionNames = [];
    }
    $results = $payload['results'] ?? [];
    if (!is_array($results)) {
        return ['processed' => 0, 'changed' => 0, 'rejected' => 0];
    }

    $catalogSha = strtolower(trim(strval($payload['catalog_sha256'] ?? '')));
    $payloadGameTs = max(0, intval($payload['game_ts'] ?? 0));
    $fullSnapshot = stobeWorldStateParseBool($payload['full_snapshot'] ?? false) === true;
    $entries = [];
    $rejected = max(0, count($results) - 4096);
    foreach (array_slice($results, 0, 4096) as $result) {
        if (!is_array($result)) {
            $rejected++;
            continue;
        }
        $queryId = stobeWorldStateValidQueryId($result['query_id'] ?? '');
        $isTrue = stobeWorldStateParseBool($result['result'] ?? null);
        if (
            $queryId === ''
            || $isTrue === null
            || (!isset($catalog[$queryId]) && !isset($definitionNames[$queryId]))
        ) {
            $rejected++;
            continue;
        }

        $queryName = trim(strval($result['query_name'] ?? ''));
        if ($queryName === '') {
            $queryName = trim(strval(
                $definitionNames[$queryId]
                ?? ($catalog[$queryId]['query_name'] ?? '')
            ));
        }
        $entries[$queryId] = [
            'query_id' => $queryId,
            'query_name' => $queryName,
            'is_true' => $isTrue,
            'game_ts' => max(0, intval($result['game_ts'] ?? $payloadGameTs)),
        ];
    }

    $changed = 0;
    $txStarted = false;
    try {
        if ($db->exec('BEGIN') === false) {
            throw new RuntimeException('Could not start world-state result transaction');
        }
        $txStarted = true;

        // A full sweep is authoritative for the currently loaded save.
        if ($fullSnapshot && $db->exec('DELETE FROM world_state_query_result') === false) {
            throw new RuntimeException('Could not replace full world-state snapshot');
        }

        foreach ($entries as $entry) {
            $previous = $db->fetchOne(
                'SELECT is_true, game_ts
                 FROM world_state_query_result
                 WHERE query_id = $1
                 LIMIT 1',
                [$entry['query_id']]
            );
            $acceptsIncoming = !$previous
                || $entry['game_ts'] >= intval($previous['game_ts'] ?? 0);
            if (
                $acceptsIncoming
                && (
                    !$previous
                    || stobeWorldStateParseBool($previous['is_true'] ?? null) !== $entry['is_true']
                )
            ) {
                $changed++;
            }

            $ok = $db->exec(
                'INSERT INTO world_state_query_result (
                    query_id, query_name, is_true, game_ts, catalog_sha256,
                    first_observed_at, last_evaluated_at, changed_at, updated_at
                 ) VALUES ($1, $2, $3, $4, $5, NOW(), NOW(), NOW(), NOW())
                 ON CONFLICT (query_id) DO UPDATE SET
                    query_name = EXCLUDED.query_name,
                    is_true = EXCLUDED.is_true,
                    game_ts = EXCLUDED.game_ts,
                    catalog_sha256 = EXCLUDED.catalog_sha256,
                    last_evaluated_at = NOW(),
                    changed_at = CASE
                        WHEN world_state_query_result.is_true IS DISTINCT FROM EXCLUDED.is_true
                        THEN NOW()
                        ELSE world_state_query_result.changed_at
                    END,
                    updated_at = NOW()
                 WHERE EXCLUDED.game_ts >= world_state_query_result.game_ts',
                [
                    $entry['query_id'],
                    $entry['query_name'],
                    $entry['is_true'],
                    $entry['game_ts'],
                    $catalogSha,
                ]
            );
            if ($ok === false) {
                throw new RuntimeException('Could not upsert world-state result');
            }
        }

        if ($db->exec('COMMIT') === false) {
            throw new RuntimeException('Could not commit world-state result transaction');
        }
        $txStarted = false;
    } catch (Throwable $exception) {
        if ($txStarted) {
            $db->exec('ROLLBACK');
        }
        stobeLogException($exception, 'world_state result persistence failed');
        return ['processed' => -1, 'changed' => 0, 'rejected' => $rejected];
    }

    return [
        'processed' => count($entries),
        'changed' => $changed,
        'rejected' => $rejected,
    ];
}

function stobeWorldStateComparableTopic(string $topic): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', trim($topic)) ?? '');
}

/**
 * Returns enabled database-backed addenda for the exact selected knowledge topic.
 */
function stobeWorldStatePromptAddendaForTopic(string $topic, int $limit = 4): array
{
    $topicKey = stobeWorldStateComparableTopic($topic);
    if ($topicKey === '') {
        return [];
    }

    $addendumMatches = [];
    foreach (stobeWorldStateAddendumRows(true) as $row) {
        $queryId = trim(strval($row['query_id'] ?? ''));
        $topics = $row['matched_topics'] ?? [];
        foreach ($topics as $matchedTopic) {
            if (stobeWorldStateComparableTopic(strval($matchedTopic)) === $topicKey) {
                $addendumMatches[$queryId] = $row;
                break;
            }
        }
    }
    if (count($addendumMatches) === 0) {
        return [];
    }

    $params = array_keys($addendumMatches);
    $placeholders = [];
    foreach ($params as $index => $_queryId) {
        $placeholders[] = '$' . strval($index + 1);
    }

    try {
        $rows = $GLOBALS['db']->fetchAll(
            'SELECT query_id, is_true
             FROM world_state_query_result
             WHERE query_id IN (' . implode(',', $placeholders) . ')
             ORDER BY changed_at DESC, query_id ASC',
            $params
        );
    } catch (Throwable $exception) {
        return [];
    }

    $addenda = [];
    $seen = [];
    foreach ($rows as $row) {
        $queryId = trim(strval($row['query_id'] ?? ''));
        if (!isset($addendumMatches[$queryId])) {
            continue;
        }
        $isTrue = stobeWorldStateParseBool($row['is_true'] ?? null);
        if ($isTrue === null) {
            continue;
        }
        $key = $isTrue ? 'when_true' : 'when_false';
        $text = trim(strval($addendumMatches[$queryId][$key] ?? ''));
        if ($text === '') {
            continue;
        }
        $dedupe = strtolower($text);
        if (isset($seen[$dedupe])) {
            continue;
        }
        $seen[$dedupe] = true;
        $addenda[] = $text;
        if (count($addenda) >= max(1, min(8, $limit))) {
            break;
        }
    }
    return $addenda;
}
