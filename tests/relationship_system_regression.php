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

echo "PASS: relationship command parsing/apply regression\n";

