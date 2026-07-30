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

    $personTopic = 'UT Context Person ' . $seed;
    $animalTopic = 'UT Context Animal ' . $seed;
    $locationTopic = 'UT Context Location ' . $seed;
    $wrongCategoryTopic = 'UT Context Item ' . $seed;
    $db->exec(
        "INSERT INTO world_knowledge (topic, topic_desc, topic_desc_basic, aliases, tags)
         VALUES
         ($1, 'Person context description.', 'Person context description.', '', 'Characters, Unique'),
         ($2, 'Animal context description.', 'Animal context description.', '', 'Animals, Characters'),
         ($3, 'Location context description.', 'Location context description.', '', 'Locations'),
         ($4, 'Item context description.', 'Item context description.', '', 'Items')",
        [$personTopic, $animalTopic, $locationTopic, $wrongCategoryTopic]
    );
    $db->exec(
        "INSERT INTO general_settings (id, value, description, updated_at)
         VALUES
            ('ALWAYS_INSERT_PEOPLE', 'true', 'Test setting', NOW()),
            ('ALWAYS_INSERT_LOCATION', 'true', 'Test setting', NOW())
         ON CONFLICT (id) DO UPDATE
         SET value = EXCLUDED.value,
             updated_at = NOW()"
    );

    $contextNpcData = [
        'name' => $personTopic . ' [Original]',
        'is_animal' => false,
        'world_knowledge_tags' => 'knowall',
        'extended_data' => [
            'nearby_actors' => [
                ['name' => $personTopic, 'is_animal' => false],
                ['name' => $animalTopic, 'is_animal' => true],
                ['name' => $wrongCategoryTopic, 'is_animal' => false],
            ],
            'points_of_interest' => [
                ['name' => $locationTopic, 'type' => 'town'],
                ['name' => $wrongCategoryTopic, 'type' => 'shop'],
            ],
        ],
    ];

    $peopleSignals = stobeWorldKnowledgeCollectForcedPeopleSignals(
        $personTopic . ' [Original]',
        $contextNpcData,
        'UT Context Speaker ' . $seed
    );
    worldKnowledgeAliasAssert(
        in_array(stobeWorldKnowledgeNormalizeLookupLabel($personTopic), $peopleSignals, true),
        'character context should normalize bracket-renamed NPCs to their base names'
    );
    worldKnowledgeAliasAssert(
        !in_array(stobeWorldKnowledgeNormalizeLookupLabel($animalTopic), $peopleSignals, true),
        'character context should skip nearby animals'
    );

    $peopleHints = stobeWorldKnowledgeResolveForcedPeopleHints(
        $personTopic . ' [Original]',
        $contextNpcData,
        'UT Context Speaker ' . $seed,
        'chat'
    );
    worldKnowledgeAliasAssert(
        count($peopleHints) === 1 && str_starts_with(strval($peopleHints[0]), $personTopic . ':'),
        'forced character knowledge should include matching character articles and reject non-character categories'
    );

    $locationSignals = stobeWorldKnowledgeCollectForcedLocationSignals($contextNpcData);
    worldKnowledgeAliasAssert(
        in_array(stobeWorldKnowledgeNormalizeLookupLabel($locationTopic), $locationSignals, true),
        'location context should include rendered points of interest'
    );
    $locationHints = stobeWorldKnowledgeResolveForcedLocationHints(
        $personTopic . ' [Original]',
        $contextNpcData,
        'chat'
    );
    worldKnowledgeAliasAssert(
        count($locationHints) === 1 && str_starts_with(strval($locationHints[0]), $locationTopic . ':'),
        'forced location knowledge should include matching location articles and reject non-location categories'
    );

    $duplicateTopicA = 'UT Duplicate Lore A ' . $seed;
    $duplicateTopicB = 'UT Duplicate Lore B ' . $seed;
    $duplicateDescription = "Identical lore with\n variable whitespace.";
    $db->exec(
        "INSERT INTO world_knowledge (topic, topic_desc, topic_desc_basic, aliases, tags)
         VALUES
            ($1, $3, $3, '', 'Test'),
            ($2, '  Identical lore with variable   whitespace. ', 'Identical lore with variable whitespace.', '', 'Test')",
        [$duplicateTopicA, $duplicateTopicB, $duplicateDescription]
    );
    $duplicateRows = $db->fetchAll(
        "SELECT id, topic, topic_desc, topic_desc_basic, knowledge_class, knowledge_class_basic, aliases, tags
         FROM world_knowledge
         WHERE topic IN ($1, $2)
         ORDER BY topic",
        [$duplicateTopicA, $duplicateTopicB]
    );
    $duplicateHints = [];
    $duplicateSeen = [];
    foreach ($duplicateRows as $duplicateRow) {
        $duplicatePayload = stobeWorldKnowledgeSelectKnowledgePayload($duplicateRow, ['knowall'], true);
        stobeWorldKnowledgeAppendUniqueHints(
            $duplicateHints,
            [stobeWorldKnowledgeBuildHintLine(
                strval($duplicatePayload['topic'] ?? ''),
                strval($duplicatePayload['desc'] ?? '')
            )],
            $duplicateSeen
        );
    }
    worldKnowledgeAliasAssert(
        count($duplicateHints) === 1,
        'different topics with whitespace-equivalent final lore should be inserted once'
    );
    $rankedDuplicateHints = queryWorldKnowledgeForNpc(
        'UT Duplicate Tester ' . $seed,
        'Tell me about ' . $duplicateTopicB,
        1,
        [
            'name' => 'UT Duplicate Tester ' . $seed,
            'world_knowledge_tags' => 'knowall',
            'extended_data' => [],
        ],
        'chat',
        $duplicateSeen
    );
    worldKnowledgeAliasAssert(
        $rankedDuplicateHints === [],
        'ranked retrieval should skip lore already inserted by forced or rule retrieval'
    );

    $ruleTopic = 'UT Rule Topic ' . $seed;
    $ruleTagTopic = 'UT Rule Tag Topic ' . $seed;
    $db->exec(
        "INSERT INTO world_knowledge (topic, topic_desc, topic_desc_basic, aliases, tags)
         VALUES
            ($1, 'Rule topic description.', 'Rule topic description.', 'UT Rule Alias', 'Rules'),
            ($2, 'Rule tag description.', 'Rule tag description.', '', 'Rule Group')",
        [$ruleTopic, $ruleTagTopic]
    );
    $savedRule = stobeWorldKnowledgeSaveContextRule($db, [
        'context_rule_label' => 'Shek in The Hub',
        'context_rule_enabled' => '1',
        'context_rule_priority' => '10',
        'context_rule_selector_type' => 'topic',
        'context_rule_selector_value' => 'UT Rule Alias',
        'context_rule_max_articles' => '1',
        'condition_race' => 'Shek, Greenlander',
        'condition_town' => 'The Hub',
        'condition_event_type' => 'chat',
    ]);
    worldKnowledgeAliasAssert(boolval($savedRule['ok'] ?? false), 'context rule should save');
    $ruleContextNpcData = [
        'name' => 'UT Rule NPC ' . $seed,
        'race' => 'Shek',
        'faction' => 'Player Faction',
        'profile_id' => 7,
        'world_knowledge_tags' => 'knowall',
        'extended_data' => [
            'environment' => [
                'town_name' => 'The Hub',
                'region' => 'Border Zone',
                'indoors' => false,
                'outdoors' => true,
                'weather' => 'Dust storm',
            ],
            'nearby_actors' => [
                ['name' => 'Nearby Tester', 'is_animal' => false],
            ],
        ],
    ];
    $ruleContext = stobeWorldKnowledgeBuildRuleContext(
        'UT Rule NPC ' . $seed,
        $ruleContextNpcData,
        'Player',
        'chat'
    );
    worldKnowledgeAliasAssert(
        stobeWorldKnowledgeContextRuleMatches(
            ['race' => ['Shek'], 'town' => ['The Hub'], 'event_type' => ['chat']],
            $ruleContext,
            $ruleReasons
        ),
        'all populated rule conditions should match current Kenshi context'
    );
    worldKnowledgeAliasAssert(
        !stobeWorldKnowledgeContextRuleMatches(['race' => ['Hive']], $ruleContext),
        'a missing condition value should reject the rule'
    );
    worldKnowledgeAliasAssert(
        count(stobeWorldKnowledgeFindRowsForRuleSelector($db, 'topic', 'UT Rule Alias', 1)) === 1,
        'topic selectors should resolve exact aliases'
    );
    worldKnowledgeAliasAssert(
        count(stobeWorldKnowledgeFindRowsForRuleSelector($db, 'tag', 'Rule Group', 5)) === 1,
        'tag selectors should resolve bounded article rows'
    );
    $ruleAuditNotes = [];
    $ruleHints = stobeWorldKnowledgeResolveContextRuleHints(
        'UT Rule NPC ' . $seed,
        $ruleContextNpcData,
        'Player',
        'chat',
        [],
        $ruleAuditNotes
    );
    worldKnowledgeAliasAssert(
        count($ruleHints) === 1 && str_starts_with(strval($ruleHints[0]), $ruleTopic . ':'),
        'matching context rules should insert permitted World Knowledge'
    );
    worldKnowledgeAliasAssert(
        count($ruleAuditNotes) === 1 && str_contains(strval($ruleAuditNotes[0]), 'Shek in The Hub'),
        'matching context rules should report their reason to retrieval audit notes'
    );
    $ruleAuditInput = 'Tell me about ' . $ruleTopic;
    queryWorldKnowledgeForNpc(
        'UT Rule NPC ' . $seed,
        $ruleAuditInput,
        1,
        $ruleContextNpcData,
        'chat',
        [],
        $ruleAuditNotes
    );
    $ruleAuditRow = $db->fetchOne(
        'SELECT keywords FROM audit_memory WHERE input = $1 ORDER BY created_at DESC LIMIT 1',
        [$ruleAuditInput]
    );
    worldKnowledgeAliasAssert(
        str_contains(strval($ruleAuditRow['keywords'] ?? ''), 'context rule')
            && str_contains(strval($ruleAuditRow['keywords'] ?? ''), 'Shek in The Hub'),
        'ranked retrieval audit should persist matched context rule details'
    );
    $excludedRulePayload = [
        stobeWorldKnowledgeHintPayloadFingerprint(strval($ruleHints[0])) => true,
    ];
    worldKnowledgeAliasAssert(
        stobeWorldKnowledgeResolveContextRuleHints(
            'UT Rule NPC ' . $seed,
            $ruleContextNpcData,
            'Player',
            'chat',
            $excludedRulePayload
        ) === [],
        'context rules should not repeat a payload already inserted by forced retrieval'
    );

    $db->exec("UPDATE general_settings SET value = 'false' WHERE id = 'ALWAYS_INSERT_PEOPLE'");
    worldKnowledgeAliasAssert(
        stobeWorldKnowledgeCollectForcedPeopleSignals($personTopic, $contextNpcData, '') === [],
        'disabled forced character knowledge should collect no context signals'
    );
    $db->exec("UPDATE general_settings SET value = 'false' WHERE id = 'ALWAYS_INSERT_LOCATION'");
    worldKnowledgeAliasAssert(
        stobeWorldKnowledgeCollectForcedLocationSignals($contextNpcData) === [],
        'disabled forced location knowledge should collect no context signals'
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
