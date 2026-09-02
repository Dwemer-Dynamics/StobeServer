<?php

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../debug/db_updates.php';

$db = $GLOBALS['db'];

function importRuleFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function importRuleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        importRuleFail($message);
    }
}

function importRuleTableExists(): bool
{
    $db = $GLOBALS['db'];
    $row = $db->fetchOne("SELECT to_regclass('public.core_profile_import_rules') AS rel");
    return trim(strval($row['rel'] ?? '')) !== '';
}

$defaultProfileId = getDefaultNpcProfileId();
importRuleAssert($defaultProfileId > 0, 'default NPC profile id should exist');

$ruleName = 'UT_IMPORT_RULE_' . strval(time()) . '_' . strval(random_int(1000, 9999));
$exactRace = 'Greenlander [UT ' . $ruleName . ']';
$exactFaction = 'Nameless [204-gamedata.base]';
$raceRegex = stobeBuildExactProfileRuleRegex($exactRace);
$genderRegex = stobeBuildExactProfileRuleRegex('female');
$factionRegex = stobeBuildExactProfileRuleRegex($exactFaction);
importRuleAssert(is_string($raceRegex), 'race dropdown value should build an exact regex');
importRuleAssert(is_string($genderRegex), 'gender dropdown value should build an exact regex');
importRuleAssert(is_string($factionRegex), 'faction dropdown value should build an exact regex');
importRuleAssert(
    stobeParseExactProfileRuleRegex($factionRegex) === [$exactFaction],
    'exact regex parser should recover punctuation-heavy faction values'
);
importRuleAssert(
    stobeParseExactProfileRuleRegex('Nord|Shek') === null,
    'custom regex should not be treated as a dropdown value'
);
importRuleAssert(
    stobeResolveProfileIdFromRuleRows([[
        'profile' => $defaultProfileId,
        'enabled' => true,
        'match_race' => $raceRegex,
        'match_gender' => $genderRegex,
        'match_faction' => $factionRegex,
    ]], $ruleName, $exactRace, 'FEMALE', $exactFaction) === $defaultProfileId,
    'dropdown-generated race, gender, and faction regexes should match exact NPC values'
);
importRuleAssert(
    stobeResolveProfileIdFromRuleRows([[
        'profile' => $defaultProfileId,
        'enabled' => true,
        'match_race' => $raceRegex,
        'match_gender' => $genderRegex,
        'match_faction' => $factionRegex,
    ]], $ruleName, 'Greenlander', 'female', $exactFaction) === 0,
    'dropdown-generated race regex should reject a different race'
);

$db->exec('BEGIN');
try {
    $optionSeed = strval(random_int(100000, 999999));
    $db->exec(
        "INSERT INTO core_npc_master (name, race, faction) VALUES
         ($1, $2, $3),
         ($4, $5, $6),
         ($7, 'Unknown', 'Unknown')",
        [
            'UT_RULE_OPTION_A_' . $optionSeed,
            $exactRace,
            $exactFaction,
            'UT_RULE_OPTION_B_' . $optionSeed,
            strtolower($exactRace),
            strtolower($exactFaction),
            'UT_RULE_OPTION_C_' . $optionSeed,
        ]
    );
    $editorOptions = stobeGetCoreProfileImportRuleEditorOptions();
    $matchingRaces = array_values(array_filter($editorOptions['races'] ?? [], static function ($value) use ($exactRace): bool {
        return strcasecmp(strval($value), $exactRace) === 0;
    }));
    $matchingFactions = array_values(array_filter($editorOptions['factions'] ?? [], static function ($value) use ($exactFaction): bool {
        return strcasecmp(strval($value), $exactFaction) === 0;
    }));
    importRuleAssert(count($matchingRaces) === 1, 'detected races should be deduplicated case-insensitively');
    importRuleAssert(count($matchingFactions) === 1, 'detected factions should be deduplicated case-insensitively');
    importRuleAssert(
        count(array_filter($editorOptions['races'] ?? [], static fn($value): bool => strcasecmp(strval($value), 'Unknown') === 0)) === 0,
        'unknown races should not be offered by the dropdown'
    );
    importRuleAssert(
        count(array_filter($editorOptions['factions'] ?? [], static fn($value): bool => strcasecmp(strval($value), 'Unknown') === 0)) === 0,
        'unknown factions should not be offered by the dropdown'
    );
} finally {
    $db->exec('ROLLBACK');
}

$db->exec('BEGIN');
try {
    stobeEnsureCoreProfileImportRulesTable();
    importRuleAssert(importRuleTableExists(), 'import-rules table should exist after initial ensure call');

    $ruleId = stobeCreateCoreProfileImportRule([
        'description' => 'UT import rule rollback probe',
        'match_name' => '^' . preg_quote($ruleName, '/') . '$',
        'profile' => $defaultProfileId,
        'priority' => 50,
        'enabled' => true,
    ]);
    importRuleAssert($ruleId > 0, 'import rule should be creatable inside rollback transaction');
    importRuleAssert(
        stobeResolveProfileIdFromImportRules($ruleName, '', '', '') === $defaultProfileId,
        'import rule should resolve inside rollback transaction'
    );
} finally {
    $db->exec('ROLLBACK');
}

importRuleAssert(!importRuleTableExists(), 'rolled-back import-rules table should not persist after rollback');

stobeEnsureCoreProfileImportRulesTable();
importRuleAssert(importRuleTableExists(), 'import-rules table should be recreated after rollback in the same PHP process');

$ruleIdAfter = stobeCreateCoreProfileImportRule([
    'description' => 'UT import rule post-rollback recreate',
    'match_name' => '^' . preg_quote($ruleName, '/') . '$',
    'profile' => $defaultProfileId,
    'priority' => 60,
    'enabled' => true,
]);
importRuleAssert($ruleIdAfter > 0, 'import rule should be creatable after rollback recreation');
importRuleAssert(
    stobeResolveProfileIdFromImportRules($ruleName, '', '', '') === $defaultProfileId,
    'import rule should resolve after rollback recreation'
);

echo 'All import-rules regression tests passed.' . PHP_EOL;
exit(0);
