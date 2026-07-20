<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/rename_name_generation_functions.php';

function generatedNameAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$seed = stobeGeneratedNameReadCsv($root . '/data/rename_names_seed.csv');
$blocked = stobeGeneratedNameReadBlocklist($root . '/data/rename_name_blocklist.txt');
generatedNameAssert(count($seed) === 1072, 'canonical seed should contain 72 original and 1,000 generated names');
generatedNameAssert(count(stobeGeneratedNameIndex($seed)) === count($seed), 'canonical seed names should be case-insensitively unique');
generatedNameAssert(
    stobeGeneratedNameCounts($seed) === ['male' => 358, 'female' => 357, 'neutral' => 357],
    'canonical seed should preserve the planned gender balance'
);

$validation = stobeGeneratedNameValidateRows([
    ['name' => 'Krell', 'gender' => 'male'],
    ['name' => 'krell', 'gender' => 'male'],
    ['name' => 'Two Words', 'gender' => 'neutral'],
    ['name' => 'Trader', 'gender' => 'neutral'],
    ['name' => 'Good', 'gender' => 'unknown'],
], [], $blocked);
generatedNameAssert(count($validation['accepted']) === 1, 'only one valid generated candidate should pass');
generatedNameAssert(count($validation['rejected']) === 4, 'duplicates, formatting, blocked words, and invalid genders should fail');

$fakeApi = json_encode([
    'choices' => [['message' => ['content' => json_encode(['names' => [['name' => 'Krell', 'gender' => 'male']]])]]],
    'usage' => ['cost' => 0.0123],
], JSON_THROW_ON_ERROR);
$parsed = stobeGeneratedNameParseApiResponse($fakeApi);
generatedNameAssert(count($parsed['names']) === 1, 'structured response parser should return names');
generatedNameAssert(abs(floatval($parsed['cost']) - 0.0123) < 0.000001, 'structured response parser should retain reported cost');
$arrayApi = json_encode([
    'choices' => [['message' => ['content' => json_encode([['name' => 'Krell', 'decision' => 'approve']])]]],
], JSON_THROW_ON_ERROR);
$arrayParsed = stobeGeneratedNameParseApiPayload($arrayApi);
generatedNameAssert(array_is_list($arrayParsed['payload']), 'structured response parser should tolerate a provider returning a root array');

$near = stobeGeneratedNameNearDuplicates([
    ['name' => 'Krell', 'gender' => 'male'],
    ['name' => 'Krel', 'gender' => 'male'],
    ['name' => 'Sava', 'gender' => 'female'],
], 1);
generatedNameAssert(count($near) === 1, 'near-duplicate report should find edit distance one');

$schema = "before\n" . STOBE_RENAME_SEED_BEGIN . "\nold\n" . STOBE_RENAME_SEED_END . "\nafter\n";
$replacementRows = [
    ['name' => 'Krell', 'gender' => 'male'],
    ['name' => 'Sava', 'gender' => 'female'],
    ['name' => 'Dusk', 'gender' => 'neutral'],
];
$updated = stobeGeneratedNameReplaceSeedSql($schema, $replacementRows);
generatedNameAssert(substr_count($updated, STOBE_RENAME_SEED_BEGIN) === 1, 'promotion should retain one opening marker');
generatedNameAssert(str_contains($updated, "('Krell', 'male'"), 'promotion should render candidate rows');
generatedNameAssert(stobeGeneratedNameReplaceSeedSql($updated, $replacementRows) === $updated, 'promotion should be idempotent');

$schemaContents = file_get_contents($root . '/data/schema.sql');
generatedNameAssert(is_string($schemaContents), 'schema should be readable');
foreach ($seed as $row) {
    $needle = "('" . strval($row['name']) . "', '" . strval($row['gender']) . "'";
    generatedNameAssert(str_contains($schemaContents, $needle), 'schema should contain canonical seed row ' . strval($row['name']));
}

echo 'All generated rename-name regression tests passed.' . PHP_EOL;
exit(0);
