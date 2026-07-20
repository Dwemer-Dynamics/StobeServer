<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/rename_name_generation_functions.php';

function promotionFail(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (!str_starts_with($argument, '--')) {
        promotionFail('Unexpected argument: ' . $argument);
    }
    $parts = explode('=', substr($argument, 2), 2);
    $options[$parts[0]] = $parts[1] ?? 'true';
}

$root = dirname(__DIR__);
$input = trim(strval($options['input'] ?? ''));
$target = intval($options['target'] ?? 1000);
$seedPath = strval($options['seed'] ?? ($root . '/data/rename_names_seed.csv'));
$schemaPath = strval($options['schema'] ?? ($root . '/data/schema.sql'));
$blocklistPath = strval($options['blocklist'] ?? ($root . '/data/rename_name_blocklist.txt'));
if ($input === '') {
    promotionFail('Pass the reviewed generated CSV with --input=path/to/accepted.csv.');
}

try {
    $seedRows = stobeGeneratedNameReadCsv($seedPath);
    $generatedRows = stobeGeneratedNameReadCsv($input);
    $blocked = stobeGeneratedNameReadBlocklist($blocklistPath);
    $validated = stobeGeneratedNameValidateRows($generatedRows, $seedRows, $blocked);
    if (count($validated['rejected']) > 0) {
        $first = $validated['rejected'][0];
        throw new RuntimeException('Generated CSV has invalid rows; first rejection: ' . strval($first['name'] ?? '') . ' (' . strval($first['reason'] ?? '') . ').');
    }
    $generatedRows = $validated['accepted'];
    if (count($generatedRows) !== $target) {
        throw new RuntimeException("Expected {$target} generated names, found " . strval(count($generatedRows)) . '.');
    }

    $expectedCounts = [
        'male' => intdiv($target, 3) + ($target % 3 > 0 ? 1 : 0),
        'female' => intdiv($target, 3) + ($target % 3 > 1 ? 1 : 0),
        'neutral' => intdiv($target, 3),
    ];
    if (stobeGeneratedNameCounts($generatedRows) !== $expectedCounts) {
        throw new RuntimeException('Generated gender quotas do not match the target.');
    }

    $order = ['male' => 0, 'female' => 1, 'neutral' => 2];
    usort($generatedRows, static function (array $left, array $right) use ($order): int {
        $genderCompare = ($order[strval($left['gender'])] ?? 9) <=> ($order[strval($right['gender'])] ?? 9);
        return $genderCompare !== 0 ? $genderCompare : strcasecmp(strval($left['name']), strval($right['name']));
    });
    $merged = array_merge($seedRows, $generatedRows);
    $schema = @file_get_contents($schemaPath);
    if (!is_string($schema)) {
        throw new RuntimeException('Could not read schema: ' . $schemaPath);
    }
    $updatedSchema = stobeGeneratedNameReplaceSeedSql($schema, $merged);

    stobeGeneratedNameWriteCsv($seedPath, $merged);
    if (file_put_contents($schemaPath . '.tmp', $updatedSchema) === false || !rename($schemaPath . '.tmp', $schemaPath)) {
        @unlink($schemaPath . '.tmp');
        throw new RuntimeException('Could not update schema: ' . $schemaPath);
    }
    fwrite(STDOUT, 'Promoted ' . strval(count($generatedRows)) . ' generated names; canonical pool now contains ' . strval(count($merged)) . ' names.' . PHP_EOL);
} catch (Throwable $exception) {
    promotionFail($exception->getMessage());
}
