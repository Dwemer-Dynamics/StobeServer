<?php

/**
 * Shared Kenshi game-timestamp utilities for StobeServer.
 *
 * Kenshi `gamets` is treated as TimeOfDay::getTotalSeconds() output.
 *
 * In practice, game saves report day 1 while `getTotalSeconds()` is already
 * >= 86400, so displayed day labels use max(1, floor(gamets / 86400)).
 */

function stobeGametsNormalize(mixed $gamets): int
{
    if (is_int($gamets)) {
        return max(0, $gamets);
    }
    if (is_float($gamets)) {
        return max(0, intval(floor($gamets)));
    }
    if (is_string($gamets)) {
        $trimmed = trim($gamets);
        if ($trimmed === '') {
            return 0;
        }
        if (preg_match('/^-?\d+$/', $trimmed) === 1) {
            return max(0, intval($trimmed));
        }
        if (is_numeric($trimmed)) {
            return max(0, intval(floor(floatval($trimmed))));
        }
    }
    return 0;
}

function stobeGametsTimeLabelFromHour(int $hour24): string
{
    if ($hour24 < 0) {
        return 'Late Night';
    }
    if ($hour24 < 4) {
        return 'Late Night';
    }
    if ($hour24 < 7) {
        return 'Dawn';
    }
    if ($hour24 < 11) {
        return 'Morning';
    }
    if ($hour24 < 14) {
        return 'Midday';
    }
    if ($hour24 < 18) {
        return 'Afternoon';
    }
    if ($hour24 < 21) {
        return 'Evening';
    }
    return 'Night';
}

function stobeGametsToDateParts(mixed $gamets): array
{
    $safeGamets = stobeGametsNormalize($gamets);
    $secondsPerDay = 86400;
    $secondsIntoDay = $safeGamets % $secondsPerDay;
    $rawDayBucket = intdiv($safeGamets, $secondsPerDay);
    $dayNumber = $rawDayBucket < 1 ? 1 : $rawDayBucket;
    $dayIndex = $dayNumber - 1;

    $hour24 = intdiv($secondsIntoDay, 3600);
    $minute = intdiv($secondsIntoDay % 3600, 60);
    $clock24 = sprintf('%02d:%02d', $hour24, $minute);

    $hour12 = $hour24 % 12;
    if ($hour12 === 0) {
        $hour12 = 12;
    }
    $amPm = $hour24 < 12 ? 'AM' : 'PM';
    $clock12 = sprintf('%d:%02d %s', $hour12, $minute, $amPm);

    $timeLabel = stobeGametsTimeLabelFromHour($hour24);
    $dateLabel = 'Day ' . strval($dayNumber) . ', ' . $timeLabel . ' (' . $clock24 . ')';

    return [
        'gamets' => $safeGamets,
        'day_index' => $dayIndex,
        'day_number' => $dayNumber,
        'seconds_into_day' => $secondsIntoDay,
        'hour_24' => $hour24,
        'minute' => $minute,
        'clock_24' => $clock24,
        'clock_12' => $clock12,
        'time_label' => $timeLabel,
        'date_label' => $dateLabel,
    ];
}

function stobeGametsDateLabel(mixed $gamets): string
{
    $parts = stobeGametsToDateParts($gamets);
    return strval($parts['date_label'] ?? '');
}

function stobeGametsDisplayWithRaw(mixed $gamets): string
{
    $safeGamets = stobeGametsNormalize($gamets);
    $label = stobeGametsDateLabel($safeGamets);
    return strval($safeGamets) . ' (' . $label . ')';
}

function stobeFormatEventHistoryLine(array $row, bool $includeGamets = true): string
{
    $historyType = trim(strval($row['type'] ?? 'event'));
    if ($historyType === '') {
        $historyType = 'event';
    }
    $historyTypeLower = strtolower($historyType);
    if ($historyTypeLower === 'inputtext' || $historyTypeLower === 'inputtext_s') {
        return '';
    }
    $historyData = trim(strval($row['data'] ?? ''));
    if ($historyData !== '' && function_exists('stobeNormalizeContextHistoryDataLine')) {
        $normalizedHistoryData = stobeNormalizeContextHistoryDataLine($historyData);
        if ($normalizedHistoryData !== '') {
            $historyData = $normalizedHistoryData;
        }
    }
    if ($historyData === '') {
        return '';
    }

    if (!$includeGamets) {
        return '[' . $historyType . '] ' . $historyData;
    }

    $gamets = stobeGametsNormalize($row['gamets'] ?? 0);
    $dateLabel = stobeGametsDateLabel($gamets);
    return '[' . $historyType . ' @ ' . $dateLabel . ' | gamets=' . strval($gamets) . '] ' . $historyData;
}
