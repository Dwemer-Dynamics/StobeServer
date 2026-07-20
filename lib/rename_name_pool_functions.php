<?php

declare(strict_types=1);

function stobeRenameNameBool(mixed $value, bool $default = true): bool
{
    $text = strtolower(trim(strval($value)));
    if ($text === '') {
        return $default;
    }
    if (in_array($text, ['1', 'true', 'yes', 'on', 'enabled', 't'], true)) {
        return true;
    }
    if (in_array($text, ['0', 'false', 'no', 'off', 'disabled', 'f'], true)) {
        return false;
    }
    return $default;
}

function stobeRenameNameNormalizeGender(mixed $value): string
{
    $gender = strtolower(trim(strval($value)));
    $aliases = ['m' => 'male', 'f' => 'female', 'n' => 'neutral', 'any' => ''];
    return $aliases[$gender] ?? $gender;
}

function stobeRenameNameValidate(array $row): array
{
    $normalized = [
        'name' => trim(strval($row['name'] ?? '')),
        'gender' => stobeRenameNameNormalizeGender($row['gender'] ?? ''),
        'race' => trim(strval($row['race'] ?? '')),
        'faction' => trim(strval($row['faction'] ?? '')),
        'is_enabled' => stobeRenameNameBool($row['is_enabled'] ?? ($row['enabled'] ?? ''), true),
    ];

    if ($normalized['name'] === '') {
        return ['ok' => false, 'message' => 'Name is required.', 'row' => $normalized];
    }
    if (strlen($normalized['name']) > 128) {
        return ['ok' => false, 'message' => 'Name must be 128 characters or fewer.', 'row' => $normalized];
    }
    if (!in_array($normalized['gender'], ['', 'male', 'female', 'neutral'], true)) {
        return ['ok' => false, 'message' => 'Gender must be male, female, neutral, or blank.', 'row' => $normalized];
    }
    if (strlen($normalized['race']) > 64 || strlen($normalized['faction']) > 128) {
        return ['ok' => false, 'message' => 'Race or faction is too long.', 'row' => $normalized];
    }
    return ['ok' => true, 'message' => '', 'row' => $normalized];
}

function stobeRenameNameParseCsv(string $path): array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return ['ok' => false, 'message' => 'Could not read the CSV file.', 'rows' => [], 'errors' => []];
    }
    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $raw = substr($raw, 3);
    }
    if (strpos($raw, "\x00") !== false && function_exists('mb_convert_encoding')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    } elseif (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }

    $stream = fopen('php://memory', 'r+');
    if ($stream === false) {
        return ['ok' => false, 'message' => 'Could not parse the CSV file.', 'rows' => [], 'errors' => []];
    }
    fwrite($stream, $raw);
    rewind($stream);
    $first = fgetcsv($stream, 0, ',');
    if (!is_array($first)) {
        fclose($stream);
        return ['ok' => false, 'message' => 'The CSV file is empty.', 'rows' => [], 'errors' => []];
    }

    $header = array_map(static fn ($value): string => strtolower(trim(strval($value))), $first);
    $hasHeader = in_array('name', $header, true);
    $map = ['name' => 0, 'gender' => -1, 'race' => -1, 'faction' => -1, 'enabled' => -1];
    if ($hasHeader) {
        foreach ($header as $index => $column) {
            $key = $column === 'is_enabled' ? 'enabled' : ($column === 'sex' ? 'gender' : $column);
            if (array_key_exists($key, $map)) {
                $map[$key] = intval($index);
            }
        }
    }

    $sourceRows = $hasHeader ? [] : [$first];
    while (($row = fgetcsv($stream, 0, ',')) !== false) {
        if (is_array($row)) {
            $sourceRows[] = $row;
        }
    }
    fclose($stream);

    $rows = [];
    $errors = [];
    foreach ($sourceRows as $index => $source) {
        if (count(array_filter($source, static fn ($value): bool => trim(strval($value)) !== '')) === 0) {
            continue;
        }
        $pick = static function (array $values, int $column): string {
            return $column >= 0 ? trim(strval($values[$column] ?? '')) : '';
        };
        $validated = stobeRenameNameValidate([
            'name' => $pick($source, $map['name']),
            'gender' => $pick($source, $map['gender']),
            'race' => $pick($source, $map['race']),
            'faction' => $pick($source, $map['faction']),
            'enabled' => $map['enabled'] >= 0 ? $pick($source, $map['enabled']) : 'true',
        ]);
        if (!boolval($validated['ok'] ?? false)) {
            $errors[] = 'Row ' . strval($index + ($hasHeader ? 2 : 1)) . ': ' . strval($validated['message'] ?? 'Invalid row.');
            continue;
        }
        $rows[] = $validated['row'];
    }
    return ['ok' => true, 'message' => '', 'rows' => $rows, 'errors' => $errors];
}

function stobeRenameNameSaveCustom(sql $db, array $values, int $rowId = 0): array
{
    $validated = stobeRenameNameValidate($values);
    if (!boolval($validated['ok'] ?? false)) {
        return $validated;
    }
    $row = $validated['row'];
    $duplicate = $db->fetchOne(
        'SELECT id FROM rename_global_custom WHERE LOWER(name) = LOWER($1) AND id <> $2 LIMIT 1',
        [$row['name'], $rowId]
    );
    if (is_array($duplicate)) {
        return ['ok' => false, 'message' => 'A custom entry with that name already exists.', 'row' => $row];
    }

    if ($rowId > 0) {
        $existing = $db->fetchOne('SELECT id FROM rename_global_custom WHERE id = $1', [$rowId]);
        if (!is_array($existing)) {
            return ['ok' => false, 'message' => 'Custom entry not found.', 'row' => $row];
        }
        $ok = $db->exec(
            'UPDATE rename_global_custom SET name = $1, gender = $2, race = $3, faction = $4, is_enabled = $5, updated_at = NOW() WHERE id = $6',
            [$row['name'], $row['gender'], $row['race'], $row['faction'], $row['is_enabled'] ? '1' : '0', $rowId]
        ) !== false;
        return ['ok' => $ok, 'message' => $ok ? 'Custom name updated.' : 'Could not update the custom name.', 'row' => $row, 'id' => $rowId];
    }

    $existing = $db->fetchOne('SELECT id FROM rename_global_custom WHERE LOWER(name) = LOWER($1) LIMIT 1', [$row['name']]);
    if (is_array($existing)) {
        return stobeRenameNameSaveCustom($db, $row, intval($existing['id'] ?? 0));
    }
    $inserted = $db->fetchOne(
        'INSERT INTO rename_global_custom (name, gender, race, faction, is_enabled, created_at, updated_at) VALUES ($1, $2, $3, $4, $5, NOW(), NOW()) RETURNING id',
        [$row['name'], $row['gender'], $row['race'], $row['faction'], $row['is_enabled'] ? '1' : '0']
    );
    $ok = is_array($inserted);
    return ['ok' => $ok, 'message' => $ok ? 'Custom name added.' : 'Could not add the custom name.', 'row' => $row, 'id' => intval($inserted['id'] ?? 0)];
}

function stobeRenameNameImport(sql $db, array $rows): array
{
    $inserted = 0;
    $updated = 0;
    $failed = 0;
    $db->exec('BEGIN');
    try {
        foreach ($rows as $row) {
            $existing = $db->fetchOne('SELECT id FROM rename_global_custom WHERE LOWER(name) = LOWER($1) LIMIT 1', [strval($row['name'] ?? '')]);
            $result = stobeRenameNameSaveCustom($db, $row, intval($existing['id'] ?? 0));
            if (!boolval($result['ok'] ?? false)) {
                $failed++;
                continue;
            }
            if (is_array($existing)) {
                $updated++;
            } else {
                $inserted++;
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
    return ['inserted' => $inserted, 'updated' => $updated, 'failed' => $failed];
}
