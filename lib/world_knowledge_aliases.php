<?php

/**
 * Shared normalization and seed-upgrade helpers for Stobe world knowledge aliases.
 */

if (!function_exists('stobeWorldKnowledgeComparableLabel')) {
    function stobeWorldKnowledgeComparableLabel(mixed $value): string
    {
        $normalized = strtolower(trim(strval($value)));
        return preg_replace('/[^a-z0-9]+/u', '', $normalized) ?? '';
    }
}

if (!function_exists('stobeWorldKnowledgeNormalizeLookupLabel')) {
    function stobeWorldKnowledgeNormalizeLookupLabel(string $value): string
    {
        $normalized = str_replace('_', ' ', strtolower(trim($value)));
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        return trim($normalized);
    }
}

if (!function_exists('stobeWorldKnowledgeComparableAliasKey')) {
    function stobeWorldKnowledgeComparableAliasKey(mixed $value): string
    {
        $normalized = stobeWorldKnowledgeNormalizeLookupLabel(strval($value));
        $normalized = preg_replace('/^(?:the|a|an)\s+/u', '', $normalized) ?? $normalized;
        return stobeWorldKnowledgeComparableLabel($normalized);
    }
}

if (!function_exists('stobeWorldKnowledgeSplitAliases')) {
    function stobeWorldKnowledgeSplitAliases(mixed $value): array
    {
        $parts = preg_split('/\s*,\s*/u', strval($value)) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $part): bool => $part !== ''));
    }
}

if (!function_exists('stobeWorldKnowledgeMergeAliases')) {
    function stobeWorldKnowledgeMergeAliases(string $topic, mixed $existing, mixed $approved): string
    {
        $topicKey = stobeWorldKnowledgeComparableAliasKey($topic);
        $merged = [];
        $seen = [];

        foreach (array_merge(
            stobeWorldKnowledgeSplitAliases($approved),
            stobeWorldKnowledgeSplitAliases($existing)
        ) as $alias) {
            $key = stobeWorldKnowledgeComparableAliasKey($alias);
            if ($key === '' || $key === $topicKey || isset($seen[$key])) {
                continue;
            }
            if (str_contains($topic, ',') && strlen($key) >= 4 && str_contains($topicKey, $key)) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $alias;
        }

        return implode(', ', $merged);
    }
}

if (!function_exists('stobeWorldKnowledgeUpdateNativeVector')) {
    function stobeWorldKnowledgeUpdateNativeVector(mixed $db, int $id): void
    {
        if (!$db || $id <= 0) {
            return;
        }
        $db->exec(
            "UPDATE world_knowledge
             SET native_vector =
                 setweight(to_tsvector('simple', COALESCE(topic, '')), 'A')
                 || setweight(to_tsvector('simple', COALESCE(aliases, '')), 'A')
                 || setweight(to_tsvector('simple', COALESCE(topic_desc, '')), 'B')
                 || setweight(to_tsvector('simple', COALESCE(topic_desc_basic, '')), 'C')
             WHERE id = $1",
            [$id]
        );
    }
}

if (!function_exists('stobeWorldKnowledgeFindUniqueAliasKeysInText')) {
    function stobeWorldKnowledgeFindUniqueAliasKeysInText(mixed $db, string $text, int $limit = 8): array
    {
        $normalizedText = stobeWorldKnowledgeNormalizeLookupLabel($text);
        if (!$db || $normalizedText === '') {
            return [];
        }

        $rows = $db->fetchAll(
            "SELECT id, aliases
             FROM world_knowledge
             WHERE BTRIM(COALESCE(aliases, '')) <> ''"
        );
        $owners = [];
        $labels = [];
        $haystack = ' ' . $normalizedText . ' ';
        foreach ($rows as $row) {
            $id = intval($row['id'] ?? 0);
            foreach (stobeWorldKnowledgeSplitAliases($row['aliases'] ?? '') as $alias) {
                $normalizedAlias = stobeWorldKnowledgeNormalizeLookupLabel($alias);
                $key = stobeWorldKnowledgeComparableLabel($alias);
                if (strlen($key) < 2 || $normalizedAlias === '') {
                    continue;
                }
                if (strpos($haystack, ' ' . $normalizedAlias . ' ') === false) {
                    continue;
                }
                $owners[$key][$id] = true;
                $labels[$key] = $normalizedAlias;
            }
        }

        $matches = [];
        foreach ($owners as $key => $ids) {
            if (count($ids) === 1) {
                $matches[$key] = $labels[$key] ?? $key;
            }
        }
        uasort($matches, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        return array_slice(array_keys($matches), 0, max(1, min(20, $limit)));
    }
}

if (!function_exists('stobeWorldKnowledgeReadSeedAliases')) {
    function stobeWorldKnowledgeReadSeedAliases(string $seedPath): array
    {
        $handle = @fopen($seedPath, 'r');
        if (!$handle) {
            throw new RuntimeException('Unable to open world knowledge seed: ' . $seedPath);
        }

        try {
            $header = fgetcsv($handle);
            if (!is_array($header)) {
                throw new RuntimeException('World knowledge seed has no CSV header.');
            }

            $columns = [];
            foreach ($header as $index => $name) {
                $key = preg_replace('/^\xEF\xBB\xBF/', '', strval($name));
                $key = strtolower(trim(strval($key)));
                $columns[$key] = intval($index);
            }
            if (!isset($columns['topic']) || !isset($columns['aliases'])) {
                throw new RuntimeException('World knowledge seed must contain topic and aliases columns.');
            }

            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $topic = trim(strval($row[$columns['topic']] ?? ''));
                if ($topic === '') {
                    continue;
                }
                $rows[] = [
                    'topic' => $topic,
                    'aliases' => trim(strval($row[$columns['aliases']] ?? '')),
                ];
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }
}

if (!function_exists('stobeWorldKnowledgeApplyAliasSeed')) {
    function stobeWorldKnowledgeApplyAliasSeed(mixed $db, string $seedPath, bool $manageTransaction = true): array
    {
        $stats = ['matched' => 0, 'updated' => 0, 'reindexed' => 0];
        $rows = stobeWorldKnowledgeReadSeedAliases($seedPath);

        if ($manageTransaction) {
            $db->exec('BEGIN');
        }
        try {
            foreach ($rows as $seedRow) {
                $existing = $db->fetchOne(
                    "SELECT id, topic, COALESCE(aliases, '') AS aliases
                     FROM world_knowledge
                     WHERE LOWER(topic) = LOWER($1)
                     ORDER BY id DESC
                     LIMIT 1",
                    [$seedRow['topic']]
                );
                if (!is_array($existing)) {
                    continue;
                }

                $stats['matched']++;
                $id = intval($existing['id'] ?? 0);
                $aliases = stobeWorldKnowledgeMergeAliases(
                    strval($existing['topic'] ?? $seedRow['topic']),
                    $existing['aliases'] ?? '',
                    $seedRow['aliases'] ?? ''
                );
                if ($aliases !== strval($existing['aliases'] ?? '')) {
                    $db->exec('UPDATE world_knowledge SET aliases = $1 WHERE id = $2', [$aliases, $id]);
                    $stats['updated']++;
                }
                stobeWorldKnowledgeUpdateNativeVector($db, $id);
                $stats['reindexed']++;
            }
            if ($manageTransaction) {
                $db->exec('COMMIT');
            }
        } catch (Throwable $exception) {
            if ($manageTransaction) {
                $db->exec('ROLLBACK');
            }
            throw $exception;
        }

        return $stats;
    }
}
