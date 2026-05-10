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
