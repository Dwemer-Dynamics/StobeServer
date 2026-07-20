<?php

declare(strict_types=1);

const STOBE_RENAME_SEED_BEGIN = '-- BEGIN GENERATED RENAME NAME SEED';
const STOBE_RENAME_SEED_END = '-- END GENERATED RENAME NAME SEED';

function stobeGeneratedNameReadCsv(string $path): array
{
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Could not read CSV: ' . $path);
    }

    try {
        $header = fgetcsv($handle);
        if (!is_array($header)) {
            throw new RuntimeException('CSV is empty: ' . $path);
        }
        $header = array_map(static fn (mixed $value): string => strtolower(trim(strval($value))), $header);
        $nameIndex = array_search('name', $header, true);
        $genderIndex = array_search('gender', $header, true);
        if ($nameIndex === false || $genderIndex === false) {
            throw new RuntimeException('CSV must contain name and gender columns: ' . $path);
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (!is_array($values) || count(array_filter($values, static fn (mixed $value): bool => trim(strval($value)) !== '')) === 0) {
                continue;
            }
            $rows[] = [
                'name' => trim(strval($values[$nameIndex] ?? '')),
                'gender' => strtolower(trim(strval($values[$genderIndex] ?? ''))),
            ];
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

function stobeGeneratedNameReadBlocklist(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        throw new RuntimeException('Could not read blocklist: ' . $path);
    }

    $blocked = [];
    foreach ($lines as $line) {
        $value = strtolower(trim(strval(preg_replace('/#.*/', '', $line))));
        if ($value !== '') {
            $blocked[$value] = true;
        }
    }
    return $blocked;
}

function stobeGeneratedNameIndex(array $rows): array
{
    $index = [];
    foreach ($rows as $row) {
        $name = strtolower(trim(strval($row['name'] ?? '')));
        if ($name !== '') {
            $index[$name] = true;
        }
    }
    return $index;
}

function stobeGeneratedNameValidateCandidate(array $candidate, array $seen, array $blocked): array
{
    $name = trim(strval($candidate['name'] ?? ''));
    $gender = strtolower(trim(strval($candidate['gender'] ?? '')));

    if (!preg_match('/^[A-Z][a-z]{2,9}$/D', $name)) {
        return ['ok' => false, 'reason' => 'format', 'row' => ['name' => $name, 'gender' => $gender]];
    }
    if (!in_array($gender, ['male', 'female', 'neutral'], true)) {
        return ['ok' => false, 'reason' => 'gender', 'row' => ['name' => $name, 'gender' => $gender]];
    }

    $key = strtolower($name);
    if (isset($seen[$key])) {
        return ['ok' => false, 'reason' => 'duplicate', 'row' => ['name' => $name, 'gender' => $gender]];
    }
    if (isset($blocked[$key])) {
        return ['ok' => false, 'reason' => 'blocklist', 'row' => ['name' => $name, 'gender' => $gender]];
    }

    return ['ok' => true, 'reason' => '', 'row' => ['name' => $name, 'gender' => $gender]];
}

function stobeGeneratedNameValidateRows(array $rows, array $existingRows, array $blocked): array
{
    $seen = stobeGeneratedNameIndex($existingRows);
    $accepted = [];
    $rejected = [];
    foreach ($rows as $candidate) {
        if (!is_array($candidate)) {
            $rejected[] = ['name' => '', 'gender' => '', 'reason' => 'not_object'];
            continue;
        }
        $result = stobeGeneratedNameValidateCandidate($candidate, $seen, $blocked);
        if (!boolval($result['ok'] ?? false)) {
            $row = is_array($result['row'] ?? null) ? $result['row'] : [];
            $rejected[] = [
                'name' => strval($row['name'] ?? ''),
                'gender' => strval($row['gender'] ?? ''),
                'reason' => strval($result['reason'] ?? 'invalid'),
            ];
            continue;
        }
        $row = $result['row'];
        $accepted[] = $row;
        $seen[strtolower(strval($row['name']))] = true;
    }
    return ['accepted' => $accepted, 'rejected' => $rejected];
}

function stobeGeneratedNameParseApiPayload(string $body): array
{
    $response = json_decode($body, true);
    if (!is_array($response)) {
        throw new RuntimeException('OpenRouter returned invalid JSON.');
    }
    if (isset($response['error'])) {
        $message = is_array($response['error']) ? strval($response['error']['message'] ?? 'unknown error') : strval($response['error']);
        throw new RuntimeException('OpenRouter error: ' . $message);
    }

    $content = $response['choices'][0]['message']['content'] ?? null;
    if (is_array($content)) {
        $parts = [];
        foreach ($content as $part) {
            if (is_array($part) && isset($part['text'])) {
                $parts[] = strval($part['text']);
            }
        }
        $content = implode('', $parts);
    }
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('OpenRouter response did not contain message content.');
    }

    $content = trim($content);
    if (str_starts_with($content, '```')) {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
    }
    $payload = json_decode($content, true);
    if (!is_array($payload)) {
        throw new RuntimeException('OpenRouter content was not valid structured JSON.');
    }
    return [
        'payload' => $payload,
        'cost' => floatval($response['usage']['cost'] ?? 0),
        'usage' => is_array($response['usage'] ?? null) ? $response['usage'] : [],
    ];
}

function stobeGeneratedNameApiReportedCost(string $body): float
{
    $response = json_decode($body, true);
    return is_array($response) ? floatval($response['usage']['cost'] ?? 0) : 0.0;
}

function stobeGeneratedNameParseApiResponse(string $body): array
{
    $parsed = stobeGeneratedNameParseApiPayload($body);
    $names = $parsed['payload']['names'] ?? null;
    if (!is_array($names)) {
        throw new RuntimeException('OpenRouter content did not match the names schema.');
    }
    return [
        'names' => $names,
        'cost' => floatval($parsed['cost'] ?? 0),
        'usage' => is_array($parsed['usage'] ?? null) ? $parsed['usage'] : [],
    ];
}

function stobeGeneratedNameCounts(array $rows): array
{
    $counts = ['male' => 0, 'female' => 0, 'neutral' => 0];
    foreach ($rows as $row) {
        $gender = strtolower(trim(strval($row['gender'] ?? '')));
        if (array_key_exists($gender, $counts)) {
            $counts[$gender]++;
        }
    }
    return $counts;
}

function stobeGeneratedNameNearDuplicates(array $rows, int $reportFromIndex = 0): array
{
    $matches = [];
    $count = count($rows);
    for ($left = 0; $left < $count; $left++) {
        $leftName = strval($rows[$left]['name'] ?? '');
        for ($right = $left + 1; $right < $count; $right++) {
            if ($left < $reportFromIndex && $right < $reportFromIndex) {
                continue;
            }
            $rightName = strval($rows[$right]['name'] ?? '');
            if (abs(strlen($leftName) - strlen($rightName)) > 1) {
                continue;
            }
            $distance = levenshtein(strtolower($leftName), strtolower($rightName));
            if ($distance <= 1) {
                $matches[] = ['name' => $leftName, 'near_name' => $rightName, 'distance' => $distance];
            }
        }
    }
    return $matches;
}

function stobeGeneratedNameWriteCsv(string $path, array $rows, array $columns = ['name', 'gender']): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create directory: ' . $directory);
    }
    $temporary = $path . '.tmp';
    $handle = @fopen($temporary, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Could not write CSV: ' . $path);
    }
    try {
        fputcsv($handle, $columns);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (string $column): string => strval($row[$column] ?? ''), $columns));
        }
    } finally {
        fclose($handle);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not replace CSV: ' . $path);
    }
}

function stobeGeneratedNameImportSeed(sql $db, string $path): int
{
    $rows = stobeGeneratedNameReadCsv($path);
    $insertedCount = 0;
    foreach ($rows as $row) {
        $name = trim(strval($row['name'] ?? ''));
        $gender = strtolower(trim(strval($row['gender'] ?? '')));
        if (!preg_match('/^[A-Z][a-z]{2,9}$/D', $name) || !in_array($gender, ['male', 'female', 'neutral'], true)) {
            throw new RuntimeException('Rename name seed contains an invalid row.');
        }
        $existing = $db->fetchOne(
            'SELECT id FROM rename_global WHERE LOWER(name) = LOWER($1) LIMIT 1',
            [$name]
        );
        if (is_array($existing)) {
            continue;
        }
        $inserted = $db->exec(
            "INSERT INTO rename_global (name, gender, faction, race, is_enabled)
             VALUES ($1, $2, '', '', TRUE)",
            [$name, $gender]
        );
        if ($inserted === false) {
            throw new RuntimeException('Could not insert rename seed row ' . $name . ': ' . $db->GetLastError());
        }
        $insertedCount++;
    }
    return $insertedCount;
}

function stobeGeneratedNameRenderSeedSql(array $rows): string
{
    $values = [];
    foreach ($rows as $row) {
        $name = str_replace("'", "''", strval($row['name'] ?? ''));
        $gender = str_replace("'", "''", strval($row['gender'] ?? ''));
        $values[] = "('{$name}', '{$gender}', '', '', 'kenshi_default')";
    }
    return STOBE_RENAME_SEED_BEGIN . "\n"
        . "INSERT INTO rename_global (name, gender, faction, race)\n"
        . "SELECT\n    v.name,\n    v.gender,\n    '',\n    ''\n"
        . "FROM (VALUES\n" . implode(",\n", $values) . "\n"
        . ") AS v(name, gender, _legacy_faction, _legacy_race, _legacy_tag)\n"
        . "ON CONFLICT (name) DO NOTHING;\n"
        . STOBE_RENAME_SEED_END;
}

function stobeGeneratedNameReplaceSeedSql(string $schema, array $rows): string
{
    $begin = strpos($schema, STOBE_RENAME_SEED_BEGIN);
    $end = strpos($schema, STOBE_RENAME_SEED_END);
    if ($begin === false || $end === false || $end < $begin) {
        throw new RuntimeException('Schema seed markers were not found.');
    }
    $end += strlen(STOBE_RENAME_SEED_END);
    return substr($schema, 0, $begin) . stobeGeneratedNameRenderSeedSql($rows) . substr($schema, $end);
}
