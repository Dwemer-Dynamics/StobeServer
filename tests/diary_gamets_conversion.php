<?php

declare(strict_types=1);

require __DIR__ . '/../lib/diary_helper_functions.php';
require_once __DIR__ . '/../lib/utils_game_timestamp.php';

function failTest(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function assertSameValue(string $label, mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        failTest($label . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$d0 = stobeBuildKenshiDateFromGamets(0);
assertSameValue('day 0 -> day_number', 1, intval($d0['day_number'] ?? -1));
assertSameValue('day 0 -> clock_24', '00:00', strval($d0['clock_24'] ?? ''));
assertSameValue('day 0 -> time_label', 'Late Night', strval($d0['time_label'] ?? ''));

$midday = stobeBuildKenshiDateFromGamets(43200);
assertSameValue('43200 -> day_number', 1, intval($midday['day_number'] ?? -1));
assertSameValue('43200 -> clock_24', '12:00', strval($midday['clock_24'] ?? ''));
assertSameValue('43200 -> time_label', 'Midday', strval($midday['time_label'] ?? ''));

$nextDay = stobeBuildKenshiDateFromGamets(86400);
assertSameValue('86400 -> day_number', 1, intval($nextDay['day_number'] ?? -1));
assertSameValue('86400 -> clock_24', '00:00', strval($nextDay['clock_24'] ?? ''));

$genericLabel = stobeGametsDateLabel(86400 + 36000);
assertSameValue('generic label', 'Day 1, Morning (10:00)', $genericLabel);

$display = stobeGametsDisplayWithRaw(86400 + 36000);
assertSameValue('display includes raw', '122400 (Day 1, Morning (10:00))', $display);

$fixed = stobeApplyKenshiDiaryHeader(
    "Day 45, Midday.\n\nThe wind is howling.",
    stobeBuildKenshiDateFromGamets(500)
);
if (strpos($fixed, 'Day 1, Late Night.') !== 0) {
    failTest('header normalization did not replace wrong day header');
}

echo "PASS: diary gamets conversion\n";
