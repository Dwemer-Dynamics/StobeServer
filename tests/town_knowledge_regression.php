<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

function townKnowledgeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = $GLOBALS['db'];
$names = ['UT_TOWN_HIDDEN', 'UT_TOWN_MAP', 'UT_TOWN_VISITED'];
$db->exec('DELETE FROM location_zones WHERE zone_name IN ($1, $2, $3)', $names);

try {
    $result = stobeStoreTownKnowledgeSnapshot([
        ['name' => $names[0], 'discovered' => false, 'explored' => false],
        ['name' => $names[1], 'x' => 10, 'y' => 20, 'z' => 30, 'discovered' => true, 'explored' => false],
    ], 12345);
    townKnowledgeAssert(intval($result['stored'] ?? 0) === 1, 'Only discovered towns should be stored.');
    townKnowledgeAssert(intval($result['rejected'] ?? 0) === 1, 'Undiscovered towns should be rejected.');
    townKnowledgeAssert(!$db->fetchOne('SELECT id FROM location_zones WHERE zone_name = $1', [$names[0]]), 'Undiscovered town leaked into storage.');

    $mapTown = $db->fetchOne('SELECT first_game_ts, metadata FROM location_zones WHERE zone_name = $1', [$names[1]]);
    $mapMetadata = json_decode(strval($mapTown['metadata'] ?? '{}'), true);
    townKnowledgeAssert(intval($mapTown['first_game_ts'] ?? -1) === 0, 'Map-only knowledge must not count as a visit.');
    townKnowledgeAssert(($mapMetadata['knowledge_only'] ?? null) === true, 'Map-only town should be marked knowledge_only.');
    townKnowledgeAssert(stobeResolveTravelLocationFromVisitedZones($names[1]) === false, 'Map-only town must not be travelable.');

    stobeStoreTownKnowledgeSnapshot([
        ['name' => $names[1], 'x' => 10, 'y' => 20, 'z' => 30, 'discovered' => true, 'explored' => true],
    ], 12346);
    townKnowledgeAssert(is_array(stobeResolveTravelLocationFromVisitedZones($names[1])), 'Explored town should be travelable.');

    stobeStoreTownKnowledgeSnapshot([
        ['name' => $names[1], 'x' => 10, 'y' => 20, 'z' => 30, 'discovered' => true, 'explored' => false],
    ], 12347);

    $db->exec(
        "INSERT INTO location_zones (zone_name, city_name, x, y, z, first_game_ts, last_game_ts, metadata)
         VALUES ($1, $1, 1, 2, 3, 12000, 12000, '{\"visited\":true}'::jsonb)",
        [$names[2]]
    );
    stobeStoreTownKnowledgeSnapshot([
        ['name' => $names[2], 'x' => 1, 'y' => 2, 'z' => 3, 'discovered' => true, 'explored' => false],
    ], 12348);
    $visited = $db->fetchOne('SELECT metadata FROM location_zones WHERE zone_name = $1', [$names[2]]);
    $visitedMetadata = json_decode(strval($visited['metadata'] ?? '{}'), true);
    townKnowledgeAssert(($visitedMetadata['knowledge_only'] ?? null) === false, 'Town sync must not downgrade an existing visit.');

    stobeStoreTownKnowledgeSnapshot([], 12349);
    townKnowledgeAssert(!$db->fetchOne('SELECT id FROM location_zones WHERE zone_name = $1', [$names[1]]), 'Stale map-only knowledge should be pruned.');
    townKnowledgeAssert(is_array($db->fetchOne('SELECT id FROM location_zones WHERE zone_name = $1', [$names[2]])), 'Visited rows must survive knowledge pruning.');

    echo "PASS: town knowledge regression\n";
} finally {
    $db->exec('DELETE FROM location_zones WHERE zone_name IN ($1, $2, $3)', $names);
}
