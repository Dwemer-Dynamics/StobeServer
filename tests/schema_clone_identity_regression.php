<?php

declare(strict_types=1);

$sql = file_get_contents(__DIR__ . '/../lib/schema_clone_function.sql');
if (!is_string($sql)) {
    fwrite(STDERR, "FAIL: schema clone SQL should be readable\n");
    exit(1);
}

$expected = 'INSERT INTO %I.%I OVERRIDING SYSTEM VALUE SELECT * FROM %I.%I ON CONFLICT DO NOTHING';
if (!str_contains($sql, $expected)) {
    fwrite(STDERR, "FAIL: schema clone should preserve GENERATED ALWAYS identity values\n");
    exit(1);
}

fwrite(STDOUT, "PASS: schema clone preserves GENERATED ALWAYS identity values\n");
