<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_storage.php');

function stobeDragonBreakIsEnabled(): bool
{
    if (!isset($GLOBALS['DRAGON_BREAK_AUTOSNAPSHOT'])) {
        $GLOBALS['DRAGON_BREAK_AUTOSNAPSHOT'] = true;
    }
    return !!$GLOBALS['DRAGON_BREAK_AUTOSNAPSHOT'];
}

function stobeDragonBreakMinDays(): int
{
    if (!isset($GLOBALS['DRAGON_BREAK_MIN_DAYS'])) {
        $GLOBALS['DRAGON_BREAK_MIN_DAYS'] = 1;
    }
    $days = intval($GLOBALS['DRAGON_BREAK_MIN_DAYS']);
    if ($days < 1) {
        $days = 1;
    }
    return $days;
}

function stobeDragonBreakDaysRollback(int $prevGamets, int $incomingGamets): int
{
    if ($prevGamets <= 0 || $incomingGamets <= 0 || $incomingGamets >= $prevGamets) {
        return 0;
    }
    $delta = $prevGamets - $incomingGamets;
    if ($delta <= 0) {
        return 0;
    }
    return intdiv($delta, 86400);
}

function stobeDragonBreakBuildName(int $prevGamets, int $incomingGamets): string
{
    $prevParts = stobeGametsToDateParts($prevGamets);
    $incomingParts = stobeGametsToDateParts($incomingGamets);

    $fromDay = intval($prevParts['day_number'] ?? 0);
    $toDay = intval($incomingParts['day_number'] ?? 0);

    if ($fromDay > 0 && $toDay > 0) {
        return 'STOBE Rollback (Day ' . $fromDay . ' -> Day ' . $toDay . ')';
    }

    return 'STOBE Rollback (' . stobeGametsDateLabel($prevGamets) . ' -> ' . stobeGametsDateLabel($incomingGamets) . ')';
}

function stobeDragonBreakCreateSnapshot(string $name, string $notes, array $meta = []): int
{
    $options = [
        'mark_active' => false,
        'storage_type' => 'schema',
        'game' => 'Kenshi',
        'rollback_delta_days' => intval($meta['rollback_delta_days'] ?? 0),
        'rollback_from_gamets' => intval($meta['rollback_from_gamets'] ?? 0),
        'rollback_to_gamets' => intval($meta['rollback_to_gamets'] ?? 0),
    ];

    $snapshot = stobePlaythroughCreateSchemaSnapshot($name, $notes, $options);
    if (!boolval($snapshot['success'] ?? false)) {
        stobeLogWarn('STOBE Rollback: Snapshot creation failed', [
            'name' => $name,
            'error' => strval($snapshot['error'] ?? 'unknown'),
        ]);
        return 0;
    }

    return intval($snapshot['id'] ?? 0);
}

function stobeDragonBreakSnapshotIfNeeded(mixed $prevGamets, mixed $incomingGamets): int
{
    if (!stobeDragonBreakIsEnabled()) {
        return 0;
    }

    $prev = stobeGametsNormalize($prevGamets);
    $incoming = stobeGametsNormalize($incomingGamets);

    if ($prev <= 0 || $incoming <= 0 || $incoming >= $prev) {
        return 0;
    }

    $daysRollback = stobeDragonBreakDaysRollback($prev, $incoming);
    if ($daysRollback < stobeDragonBreakMinDays()) {
        return 0;
    }

    $name = stobeDragonBreakBuildName($prev, $incoming);
    $notes = 'Auto STOBE rollback snapshot due to rollback of '
        . strval($daysRollback)
        . ' Kenshi day(s) ('
        . strval($incoming)
        . ' -> '
        . strval($prev)
        . ').';

    $snapshotId = stobeDragonBreakCreateSnapshot($name, $notes, [
        'rollback_delta_days' => $daysRollback,
        'rollback_from_gamets' => $prev,
        'rollback_to_gamets' => $incoming,
    ]);

    if ($snapshotId > 0) {
        stobeLogInfo('STOBE Rollback: Snapshot created', [
            'snapshot_id' => $snapshotId,
            'days_rollback' => $daysRollback,
            'from_gamets' => $prev,
            'to_gamets' => $incoming,
            'name' => $name,
        ]);
    }

    return $snapshotId;
}

?>
