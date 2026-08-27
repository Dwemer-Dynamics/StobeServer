<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

function relTestFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function relAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        relTestFail($message);
    }
}

function relAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        relTestFail($message . ' (expected="' . $expected . '", actual="' . $actual . '")');
    }
}

function relAssertSameInt(int $expected, int $actual, string $message): void
{
    if ($expected !== $actual) {
        relTestFail($message . ' (expected=' . $expected . ', actual=' . $actual . ')');
    }
}

$parsed = stobeParseRelationshipCommandTags(
    'Hand, you did well. #REL:Whistler=+7# #TYPE:Whistler=admirer#'
);
relAssertTrue(is_array($parsed['updates'] ?? null), 'parsed updates should be an array');
relAssertSameInt(1, count($parsed['updates']), 'should parse one relationship update');
$firstUpdate = $parsed['updates'][0];
relAssertSame('Whistler', strval($firstUpdate['target'] ?? ''), 'parsed target should match');
relAssertSameInt(7, intval($firstUpdate['aff_delta'] ?? 0), 'parsed affinity delta should match');
relAssertSame('admirer', strval($firstUpdate['type'] ?? ''), 'parsed relationship type should match');
relAssertTrue(
    strpos(strval($parsed['clean_response'] ?? ''), '#REL:') === false,
    'clean response should strip relationship tags'
);

$baseMap = stobeNormalizeRelationshipMap([
    'Whistler' => ['aff' => 12, 'type' => 'neutral', 'note' => 'known drifter'],
]);
relAssertTrue(isset($baseMap['Whistler']), 'normalized map should contain Whistler');

$storedMap = stobeNormalizeRelationshipMap(
    '{"Whistler":{"aff":12,"type":"neutral","note":"known drifter"}}'
);
relAssertTrue(isset($storedMap['Whistler']), 'stored JSON relationship map should normalize');
relAssertSameInt(12, intval($storedMap['Whistler']['aff'] ?? 0), 'stored JSON affinity should be preserved');
relAssertSame('neutral', strval($storedMap['Whistler']['type'] ?? ''), 'stored JSON type should be preserved');

$applied = stobeApplyRelationshipUpdatesMap($baseMap, $parsed['updates'], ['Whistler']);
relAssertSameInt(1, intval($applied['updated'] ?? 0), 'one relationship should be updated');
$updatedMap = is_array($applied['map'] ?? null) ? $applied['map'] : [];
relAssertTrue(isset($updatedMap['Whistler']), 'updated map should still contain Whistler');
relAssertSameInt(19, intval($updatedMap['Whistler']['aff'] ?? 0), 'affinity should increment by parsed delta');
relAssertSame('admirer', strval($updatedMap['Whistler']['type'] ?? ''), 'type should update from parsed delta');
relAssertSame('Acquaintance', strval($updatedMap['Whistler']['tier'] ?? ''), 'tier should match updated affinity');

relAssertSame('Neutral', stobeRelationshipTierLabel(0), 'tier helper should classify neutral score');
relAssertSame('Friendly', stobeRelationshipTierLabel(40), 'tier helper should classify friendly score');
relAssertSame('Hostile', stobeRelationshipTierLabel(-95), 'tier helper should classify hostile score');

relAssertSameInt(50, intval(getDefaultCoreProfileMetadata()['RELATIONSHIP_UPDATE_CHANCE'] ?? -1), 'default relationship update chance should be 50');
relAssertTrue(!stobeShouldRunAutomaticRelationshipEvaluation(0, 1), 'zero chance should skip automatic evaluation');
relAssertTrue(stobeShouldRunAutomaticRelationshipEvaluation(100, 100), '100 chance should always evaluate');
relAssertTrue(stobeShouldRunAutomaticRelationshipEvaluation(25, 25), 'roll equal to chance should evaluate');
relAssertTrue(!stobeShouldRunAutomaticRelationshipEvaluation(25, 26), 'roll above chance should skip evaluation');
relAssertTrue(!stobeShouldRunAutomaticRelationshipEvaluation(-5, 1), 'chance should clamp at zero');
relAssertTrue(stobeShouldRunAutomaticRelationshipEvaluation(105, 100), 'chance should clamp at 100');


require __DIR__ . '/../lib/eventlog_helper.php';

// Relationship history timeline derivation: affinity/type/note movement only.
$relSnapshot = static function (array $current, ?array $previous): array {
    return [
        'extended_data' => json_encode(['relationships' => $current]),
        'previous_extended_data' => $previous === null ? null : json_encode(['relationships' => $previous]),
        'owner_name' => 'Nomi',
    ];
};

$affinityChange = stobeBuildRelationshipChangeDetails($relSnapshot(
    ['Whistler' => ['aff' => 60, 'type' => 'ally', 'note' => 'shared water rations']],
    ['Whistler' => ['aff' => 35, 'type' => 'ally', 'note' => 'known drifter']]
));
relAssertSameInt(1, count($affinityChange), 'one target should be reported as changed');
relAssertSameInt(25, intval($affinityChange[0]['delta'] ?? 0), 'affinity delta should be derived');
relAssertSame('Friendly', strval($affinityChange[0]['tier_from'] ?? ''), 'previous tier should be derived');
relAssertSame('Fond', strval($affinityChange[0]['tier_to'] ?? ''), 'new tier should be derived');
relAssertSame('shared water rations', strval($affinityChange[0]['note'] ?? ''), 'rewritten note should surface');
relAssertTrue(
    strpos(stobeRelationshipChangeText('Nomi', $affinityChange), 'Nomi - Whistler: +25 (35 to 60), Friendly to Fond') === 0,
    'change text should lead with the owner and signed delta'
);

// A re-save that only moves the volatile updated_at/tier fields is not a change.
$volatileOnly = stobeBuildRelationshipChangeDetails($relSnapshot(
    ['Whistler' => ['aff' => 35, 'type' => 'ally', 'note' => 'known drifter', 'tier' => 'Friendly', 'updated_at' => 200]],
    ['Whistler' => ['aff' => 35, 'type' => 'ally', 'note' => 'known drifter', 'tier' => 'Friendly', 'updated_at' => 100]]
));
relAssertSameInt(0, count($volatileOnly), 'volatile-only differences should not be reported');

// The first snapshot for a target is a real change, not an empty diff.
$firstSnapshot = stobeBuildRelationshipChangeDetails($relSnapshot(
    ['Whistler' => ['aff' => 35, 'type' => 'ally', 'note' => '']],
    null
));
relAssertSameInt(1, count($firstSnapshot), 'a newly tracked target should be reported');
relAssertSame('added', strval($firstSnapshot[0]['state'] ?? ''), 'a newly tracked target should be marked added');
relAssertSameInt(0, intval($firstSnapshot[0]['delta'] ?? -1), 'a newly tracked target should not report a delta');

$emptyBoth = stobeBuildRelationshipChangeDetails($relSnapshot([], null));
relAssertSameInt(0, count($emptyBoth), 'an empty initial map should not be reported');

$typeOnly = stobeBuildRelationshipChangeDetails($relSnapshot(
    ['Whistler' => ['aff' => 35, 'type' => 'rival', 'note' => 'known drifter']],
    ['Whistler' => ['aff' => 35, 'type' => 'ally', 'note' => 'known drifter']]
));
relAssertSameInt(1, count($typeOnly), 'a type-only change should be reported');
relAssertSame('Nomi - Whistler: type ally to rival', stobeRelationshipChangeText('Nomi', $typeOnly), 'type-only text should omit a delta');

$cleared = stobeBuildRelationshipChangeDetails($relSnapshot(
    [],
    ['Whistler' => ['aff' => 35, 'type' => 'ally', 'note' => 'known drifter']]
));
relAssertSameInt(1, count($cleared), 'a removed target should be reported');
relAssertSame('Nomi - Whistler: relationship cleared', stobeRelationshipChangeText('Nomi', $cleared), 'removal text should read as cleared');

$decorated = stobeDecorateRelationshipTimelineRow($relSnapshot(
    ['Whistler' => ['aff' => 47, 'type' => 'ally', 'note' => '']],
    ['Whistler' => ['aff' => 35, 'type' => 'ally', 'note' => '']]
));
relAssertSame('relationship', strval($decorated['type'] ?? ''), 'decorated rows should use the relationship type');
relAssertSame('|Nomi|Whistler|', strval($decorated['people'] ?? ''), 'decorated rows should list owner then targets');

echo "PASS: relationship command parsing/apply regression\n";

