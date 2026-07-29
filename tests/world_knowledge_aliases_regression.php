<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/world_knowledge_aliases.php';

$db = $GLOBALS['db'];

function worldKnowledgeAliasFail(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function worldKnowledgeAliasAssert(bool $condition, string $message): void
{
    if (!$condition) {
        worldKnowledgeAliasFail($message);
    }
}

worldKnowledgeAliasAssert(
    stobeWorldKnowledgeComparableLabel("World's_End") === 'worldsend',
    'comparable labels should ignore punctuation and underscores'
);
worldKnowledgeAliasAssert(
    stobeWorldKnowledgeNormalizeLookupLabel("World's_End") === 'world s end',
    'lookup labels should normalize punctuation and underscores to spaces'
);
worldKnowledgeAliasAssert(
    stobeWorldKnowledgeMergeAliases('United Cities', 'United_Cities, Empire', 'UC, empire') === 'UC, empire',
    'alias merge should remove canonical variants and deduplicate aliases'
);
worldKnowledgeAliasAssert(
    stobeWorldKnowledgeMergeAliases(
        'The United Cities, A United Power',
        'The United Cities, A United Power',
        ''
    ) === '',
    'alias merge should remove comma-split fragments of canonical titles'
);

$seedRows = stobeWorldKnowledgeReadSeedAliases(__DIR__ . '/../data/import/world_knowledge_v1.csv');
$canonicalOwners = [];
foreach ($seedRows as $seedRow) {
    $canonicalOwners[stobeWorldKnowledgeComparableLabel($seedRow['topic'] ?? '')] = strval($seedRow['topic'] ?? '');
}
$aliasOwners = [];
$seedAliasCount = 0;
foreach ($seedRows as $seedRow) {
    $topic = strval($seedRow['topic'] ?? '');
    $topicKey = stobeWorldKnowledgeComparableLabel($topic);
    foreach (stobeWorldKnowledgeSplitAliases($seedRow['aliases'] ?? '') as $alias) {
        $aliasKey = stobeWorldKnowledgeComparableLabel($alias);
        worldKnowledgeAliasAssert($aliasKey !== $topicKey, 'seed alias must not duplicate its canonical topic: ' . $topic);
        worldKnowledgeAliasAssert(
            !isset($canonicalOwners[$aliasKey]) || $canonicalOwners[$aliasKey] === $topic,
            'seed alias must not collide with another canonical topic: ' . $topic . ' -> ' . $alias
        );
        worldKnowledgeAliasAssert(
            !isset($aliasOwners[$aliasKey]) || $aliasOwners[$aliasKey] === $topic,
            'seed alias must not be shared across topics: ' . $topic . ' -> ' . $alias
        );
        $aliasOwners[$aliasKey] = $topic;
        $seedAliasCount++;
    }
}
worldKnowledgeAliasAssert(count($seedRows) === 558, 'world knowledge seed should retain all 558 source rows');
worldKnowledgeAliasAssert($seedAliasCount === 48, 'world knowledge seed should contain the 48 reviewed aliases');
worldKnowledgeAliasAssert(($aliasOwners['uc'] ?? '') === 'United Cities', 'reviewed seed should include UC for United Cities');

$seed = strval(time()) . '_' . strval(random_int(1000, 9999));
$topic = 'UT Alias Topic ' . $seed;
$uniqueAlias = 'QZ';
$sharedAlias = 'UT Shared Alias ' . $seed;
$csvPath = tempnam(sys_get_temp_dir(), 'stobe_wk_alias_');
if ($csvPath === false) {
    worldKnowledgeAliasFail('could not create temporary alias seed');
}

$db->exec('BEGIN');
try {
    $inserted = $db->fetchOne(
        "INSERT INTO world_knowledge (topic, topic_desc, topic_desc_basic, aliases, tags)
         VALUES ($1, $2, $2, $3, 'Test')
         RETURNING id",
        [$topic, 'Alias regression description.', $topic . ', Custom Lore Name']
    );
    $topicId = intval($inserted['id'] ?? 0);
    worldKnowledgeAliasAssert($topicId > 0, 'test article should be inserted');

    $canonicalCollisionTopic = $sharedAlias;
    $db->exec(
        "INSERT INTO world_knowledge (topic, topic_desc, topic_desc_basic, aliases, tags)
         VALUES
         ($1, 'First shared alias article.', 'First shared alias article.', $2, 'Test'),
         ($3, 'Canonical shared alias article.', 'Canonical shared alias article.', '', 'Test'),
         ($4, 'Second shared alias article.', 'Second shared alias article.', $2, 'Test')",
        [
            'UT Shared Owner A ' . $seed,
            $sharedAlias,
            $canonicalCollisionTopic,
            'UT Shared Owner B ' . $seed,
        ]
    );

    file_put_contents(
        $csvPath,
        "topic,topic_desc,topic_desc_basic,knowledge_class,knowledge_class_basic,aliases,tags\n"
        . '"' . $topic . '",,,,,"' . $uniqueAlias . ', Custom Lore Name",Test' . "\n"
    );
    $stats = stobeWorldKnowledgeApplyAliasSeed($db, $csvPath, false);
    worldKnowledgeAliasAssert(intval($stats['matched'] ?? 0) === 1, 'alias seed should match the test article');
    worldKnowledgeAliasAssert(intval($stats['updated'] ?? 0) === 1, 'alias seed should update aliases once');

    $row = $db->fetchOne('SELECT aliases, native_vector::text AS native_vector FROM world_knowledge WHERE id = $1', [$topicId]);
    $aliases = strval($row['aliases'] ?? '');
    worldKnowledgeAliasAssert($aliases === $uniqueAlias . ', Custom Lore Name', 'upgrade should add approved aliases and preserve custom aliases');
    worldKnowledgeAliasAssert(stripos(strval($row['native_vector'] ?? ''), strtolower($uniqueAlias)) !== false, 'native vector should index aliases');

    $rerun = stobeWorldKnowledgeApplyAliasSeed($db, $csvPath, false);
    worldKnowledgeAliasAssert(intval($rerun['updated'] ?? -1) === 0, 'alias seed should be idempotent');

    $uniqueMatch = stobeWorldKnowledgeLookupTopicRowByLabel($uniqueAlias);
    worldKnowledgeAliasAssert(intval($uniqueMatch['id'] ?? 0) === $topicId, 'unique short alias should resolve to its article');
    worldKnowledgeAliasAssert(
        stobeWorldKnowledgeFindUniqueAliasKeysInText($db, 'Tell me about ' . $uniqueAlias . ' today') === [strtolower($uniqueAlias)],
        'unique short aliases should be detected as whole phrases inside a message'
    );

    $canonicalMatch = stobeWorldKnowledgeLookupTopicRowByLabel($canonicalCollisionTopic);
    worldKnowledgeAliasAssert(
        strval($canonicalMatch['topic'] ?? '') === $canonicalCollisionTopic,
        'canonical topic should win when another article uses the same label as an alias'
    );

    $ambiguousOnly = 'UT Ambiguous Only ' . $seed;
    $db->exec(
        'UPDATE world_knowledge SET aliases = $1 WHERE topic IN ($2, $3)',
        [$ambiguousOnly, 'UT Shared Owner A ' . $seed, 'UT Shared Owner B ' . $seed]
    );
    worldKnowledgeAliasAssert(
        stobeWorldKnowledgeLookupTopicRowByLabel($ambiguousOnly) === null,
        'ambiguous aliases should not resolve arbitrarily'
    );

    $hints = queryWorldKnowledgeForNpc(
        'UT Alias Tester ' . $seed,
        'Tell me about ' . $uniqueAlias . ' today',
        1,
        [
            'name' => 'UT Alias Tester ' . $seed,
            'world_knowledge_tags' => 'knowall',
            'extended_data' => [],
        ],
        'chat'
    );
    worldKnowledgeAliasAssert(
        count($hints) === 1 && str_starts_with(strval($hints[0]), $topic . ':'),
        'normal retrieval should resolve a two-character exact alias inside a sentence'
    );

    $db->exec('ROLLBACK');
} catch (Throwable $exception) {
    $db->exec('ROLLBACK');
    throw $exception;
} finally {
    @unlink($csvPath);
}

echo 'All world knowledge alias regression tests passed.' . PHP_EOL;
exit(0);
