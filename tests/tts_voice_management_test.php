<?php

require_once dirname(__DIR__) . '/lib/tts_voice_management.php';
require_once dirname(__DIR__) . '/lib/tts_pronunciation.php';

function expectSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

expectSame('pocket_tts', stobeVoiceProviderNormalize('PocketTTS'), 'normalizes PocketTTS');
expectSame('', stobeVoiceProviderNormalizeId('../voice'), 'rejects traversal');
expectSame('npc.voice-1', stobeVoiceProviderNormalizeId('npc.voice-1.wav'), 'normalizes WAV suffix');

$omni = stobeVoiceProviderTarget([
    'id' => 4,
    'name' => 'Omni',
    'connector_type' => 'omnivoice',
    'base_url' => 'http://localhost:8021/',
    'config' => json_encode(['language' => 'EN']),
]);
expectSame('http://localhost:8021/voices/npc.voice-1?language=en', stobeVoiceProviderDeleteUrl($omni, 'npc.voice-1'), 'builds OmniVoice delete URL');

$chatterboxDefault = stobeVoiceProviderTarget(['connector_type' => 'chatterbox']);
expectSame('http://127.0.0.1:8023', $chatterboxDefault['endpoint'], 'uses dedicated Chatterbox port');
$pocketDefault = stobeVoiceProviderTarget(['connector_type' => 'pocket_tts']);
expectSame('http://127.0.0.1:8024', $pocketDefault['endpoint'], 'uses dedicated Python PocketTTS port');
$xttsDefault = stobeVoiceProviderTarget(['connector_type' => 'xtts']);
expectSame('http://127.0.0.1:8020', $xttsDefault['endpoint'], 'keeps XTTS on port 8020');

$audioCpp = stobeVoiceProviderTarget([
    'connector_type' => 'pocket_tts',
    'base_url' => 'http://127.0.0.1:8086',
]);
expectSame(false, $audioCpp['can_manage'], 'protects audio.cpp local samples');

$cartesia = stobeVoiceProviderTarget([
    'id' => 8,
    'name' => 'Cartesia',
    'connector_type' => 'cartesia',
    'base_url' => '',
    'api_badge_key' => 'test-key',
    'config' => json_encode(['language' => 'EN']),
]);
expectSame('cartesia', $cartesia['provider'], 'normalizes Cartesia');
expectSame(true, $cartesia['cloud'], 'marks Cartesia as cloud');
expectSame(true, $cartesia['can_manage'], 'enables configured Cartesia management');

$inworldMissingWorkspace = stobeVoiceProviderTarget([
    'id' => 9,
    'name' => 'Inworld',
    'connector_type' => 'inworld',
    'api_badge_key' => 'test-key',
    'config' => json_encode(['language' => 'EN_US']),
]);
expectSame(false, $inworldMissingWorkspace['can_manage'], 'requires Inworld workspace');

$inworld = stobeVoiceProviderTarget([
    'id' => 9,
    'name' => 'Inworld',
    'connector_type' => 'inworld',
    'api_badge_key' => 'test-key',
    'config' => json_encode(['language' => 'EN_US', 'workspace' => 'workspace-1']),
]);
expectSame(true, $inworld['can_manage'], 'enables configured Inworld management');
expectSame('not found', stobeVoiceProviderResponseMessage('{"detail":"not found"}', 404), 'extracts API detail');
expectSame(
    'protected_voice: Only custom uploaded voices can be deleted.',
    stobeVoiceProviderResponseMessage('{"detail":{"error":"protected_voice","hint":"Only custom uploaded voices can be deleted."}}', 403),
    'extracts nested FastAPI detail'
);

expectSame([], stobeDefaultTtsPronunciationEntries(), 'ships with no default pronunciations');

$pronunciationRows = [
    [
        'source_text' => 'Cat-Lon',
        'spoken_text' => 'Cat Lon',
        'npc_names' => '',
        'races' => '',
        'oghma_tags' => '',
        'is_builtin' => false,
        'enabled' => true,
    ],
    [
        'source_text' => 'Cat-Lon',
        'spoken_text' => 'Cat of the Ashlands',
        'npc_names' => 'Beep, Agnu',
        'races' => 'Hive, Skeleton',
        'oghma_tags' => 'ancient, tech_hunters',
        'is_builtin' => false,
        'enabled' => true,
    ],
    [
        'source_text' => 'Kenshi',
        'spoken_text' => 'Ken-shee',
        'npc_names' => '',
        'races' => '',
        'oghma_tags' => '',
        'is_builtin' => false,
        'enabled' => true,
    ],
    [
        'source_text' => 'Ken-shee',
        'spoken_text' => 'cascaded',
        'npc_names' => '',
        'races' => '',
        'oghma_tags' => '',
        'is_builtin' => false,
        'enabled' => true,
    ],
    [
        'source_text' => 'disabled',
        'spoken_text' => 'wrong',
        'npc_names' => '',
        'races' => '',
        'oghma_tags' => '',
        'is_builtin' => false,
        'enabled' => false,
    ],
];

expectSame(
    'Visit Cat Lon in Ken-shee.',
    stobeApplyTtsPronunciationDictionary('Visit cat-lon in Kenshi.', $pronunciationRows, [], '', ''),
    'applies case-insensitive global replacements without cascading'
);
expectSame(
    'Ask Cat of the Ashlands.',
    stobeApplyTtsPronunciationDictionary('Ask Cat-Lon.', $pronunciationRows, ['ancient'], 'beep', 'hive'),
    'prefers the matching scoped pronunciation'
);
expectSame(
    'Ask Cat Lon.',
    stobeApplyTtsPronunciationDictionary('Ask Cat-Lon.', $pronunciationRows, ['ancient'], 'Beep', 'Shek'),
    'requires every populated scope to match'
);
expectSame(
    'Ask Cat of the Ashlands.',
    stobeApplyTtsPronunciationDictionary('Ask Cat-Lon.', $pronunciationRows, ['knowall'], 'Agnu', 'Skeleton'),
    'knowall bypasses only the knowledge-tag scope'
);
expectSame(
    'Cat-Longevity stays disabled.',
    stobeApplyTtsPronunciationDictionary('Cat-Longevity stays disabled.', $pronunciationRows, [], '', ''),
    'uses whole terms and ignores disabled rows'
);

$fakePronunciationDb = new class {
    public bool $builtin = true;
    public array $queries = [];

    public function fetchOne(string $query, array $params = []): array
    {
        if (str_contains($query, 'to_regclass')) {
            return ['relation_name' => 'core_tts_pronunciation'];
        }
        return ['is_builtin' => $this->builtin];
    }

    public function exec(string $query, array $params = []): bool
    {
        $this->queries[] = ['query' => $query, 'params' => $params];
        return true;
    }
};
$GLOBALS['db'] = $fakePronunciationDb;
$fakeDictionary = new TTSPronunciationDictionary();
expectSame(true, stobeUnhyphenateBuiltinTtsPronunciations(), 'removes hyphens only from built-ins');
$lastFakeQuery = $fakePronunciationDb->queries[array_key_last($fakePronunciationDb->queries)] ?? [];
expectSame(true, str_contains(strval($lastFakeQuery['query'] ?? ''), 'WHERE is_builtin = TRUE'), 'scopes unhyphenation to built-ins');
expectSame(true, $fakeDictionary->saveBuiltin(42, 'New Reading', false), 'edits a built-in spoken value');
$lastFakeQuery = $fakePronunciationDb->queries[array_key_last($fakePronunciationDb->queries)] ?? [];
$saveQuery = strval($lastFakeQuery['query'] ?? '');
expectSame(true, str_contains($saveQuery, 'is_builtin = TRUE AND deleted = FALSE'), 'limits built-in edits to active defaults');
expectSame(false, str_contains($saveQuery, 'source_text ='), 'keeps built-in written values immutable');
expectSame(true, $fakeDictionary->deleteEntry(42), 'tombstones a built-in pronunciation');
$lastFakeQuery = $fakePronunciationDb->queries[array_key_last($fakePronunciationDb->queries)] ?? [];
$builtinDeleteQuery = strval($lastFakeQuery['query'] ?? '');
expectSame(true, str_contains($builtinDeleteQuery, 'SET deleted = TRUE, enabled = FALSE'), 'preserves a built-in tombstone');
$fakePronunciationDb->builtin = false;
expectSame(true, $fakeDictionary->deleteEntry(43), 'deletes a custom pronunciation');
$lastFakeQuery = $fakePronunciationDb->queries[array_key_last($fakePronunciationDb->queries)] ?? [];
$customDeleteQuery = strval($lastFakeQuery['query'] ?? '');
expectSame(true, str_contains($customDeleteQuery, 'is_builtin = FALSE AND deleted = FALSE'), 'limits physical deletion to active custom rows');
unset($GLOBALS['db']);

$testDatabase = trim(strval(getenv('STOBE_DB_NAME') ?: ''));
if ($testDatabase !== '' && stripos($testDatabase, 'test') === false && stripos($testDatabase, 'ci') === false) {
    fwrite(STDERR, "Refusing pronunciation CRUD checks against non-test database: {$testDatabase}\n");
    exit(2);
}
if ($testDatabase !== '') {
    require_once dirname(__DIR__) . '/lib/postgresql.class.php';
    $GLOBALS['db'] = new sql();
    expectSame(true, stobeEnsureTtsPronunciationDictionary(), 'ensures pronunciation schema');

    $dictionary = new TTSPronunciationDictionary();
    expectSame([], $dictionary->getRows(), 'database starts without pronunciation defaults');
    expectSame(
        true,
        $dictionary->saveCustom(null, 'Cat-Lon', 'Cat Lon', 'TTS Scope Fixture', 'Hive', 'ancient', true),
        'saves a scoped custom pronunciation'
    );
    $savedRows = $dictionary->getRows();
    expectSame(1, count($savedRows), 'stores one pronunciation row');
    $savedId = intval($savedRows[0]['id'] ?? 0);
    expectSame(true, $savedId > 0, 'assigns a pronunciation id');
    expectSame(
        true,
        $GLOBALS['db']->exec(
            "INSERT INTO core_npc_master (name, race, world_knowledge_tags, gamets_last_updated)
             VALUES ('TTS Scope Fixture', 'Hive', 'ancient, tech_hunters', 1)"
        ) !== false,
        'creates an active NPC scope fixture'
    );
    $speakerScope = stobeTtsPronunciationSpeakerScope('TTS Scope Fixture');
    expectSame('TTS Scope Fixture', $speakerScope['npc_name'] ?? '', 'resolves pronunciation NPC name from core_npc');
    expectSame('Hive', $speakerScope['race'] ?? '', 'resolves pronunciation race from core_npc');
    expectSame(
        ['ancient', 'tech_hunters'],
        $speakerScope['knowledge_tags'] ?? [],
        'resolves pronunciation knowledge tags from core_npc'
    );
    expectSame(
        'Ask Cat Lon.',
        stobeApplyTtsPronunciationDictionary(
            'Ask Cat-Lon.',
            $savedRows,
            $speakerScope['knowledge_tags'],
            $speakerScope['npc_name'],
            $speakerScope['race']
        ),
        'applies a stored pronunciation to its active speaker scope'
    );
    expectSame(true, $dictionary->setEnabled($savedId, false), 'disables a pronunciation');
    $disabledRows = $dictionary->getRows();
    expectSame(false, stobeTtsPronunciationBoolean($disabledRows[0]['enabled'] ?? true), 'persists disabled state');
    expectSame(true, $dictionary->deleteEntry($savedId), 'deletes a custom pronunciation');
    expectSame([], $dictionary->getRows(), 'deletion restores the blank dictionary');
    $GLOBALS['db']->exec("DELETE FROM core_npc_master WHERE name = 'TTS Scope Fixture'");
    $GLOBALS['db']->close();
    unset($GLOBALS['db']);
}

fwrite(STDOUT, "tts_voice_management_test: OK\n");
