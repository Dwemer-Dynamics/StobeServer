<?php

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'utils_game_timestamp.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'logger.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_storage.php');
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'playthrough_retention.php');

function stobeDragonBreakIsEnabled(): bool
{
    return ptp_runtime_backup_settings()['enabled'];
}

function stobeDragonBreakMinDays(): int
{
    return ptp_runtime_backup_settings()['min_days'];
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
        return 'Automatic Playthrough Save (Day ' . $fromDay . ' -> Day ' . $toDay . ')';
    }

    return 'Automatic Playthrough Save (' . stobeGametsDateLabel($prevGamets) . ' -> ' . stobeGametsDateLabel($incomingGamets) . ')';
}

function stobeDragonBreakCreatePlaythrough(string $name, string $notes, array $meta = []): int
{
    $options = [
        'mark_active' => false,
        'storage_type' => 'schema',
        'game' => 'Kenshi',
        'retention_kind' => 'dragon_break',
        'rollback_delta_days' => intval($meta['rollback_delta_days'] ?? 0),
        'rollback_from_gamets' => intval($meta['rollback_from_gamets'] ?? 0),
        'rollback_to_gamets' => intval($meta['rollback_to_gamets'] ?? 0),
    ];

    $playthrough = stobePlaythroughCreate($name, $notes, $options);
    $statusConn = ptp_connect();
    if ($statusConn) {
        $id = !empty($playthrough['success']) ? intval($playthrough['id'] ?? 0) : 0;
        ptp_record_backup($statusConn, $id, $id > 0 ? 'Automatic Playthrough Save created.' : 'Automatic Playthrough Save failed. Check the server log.');
        pg_close($statusConn);
    }
    if (!boolval($playthrough['success'] ?? false)) {
        stobeLogWarn('STOBE Rollback: Playthrough creation failed', [
            'name' => $name,
            'error' => strval($playthrough['error'] ?? 'unknown'),
        ]);
        return 0;
    }

    return intval($playthrough['id'] ?? 0);
}

function stobeDragonBreakPlaythroughIfNeeded(mixed $prevGamets, mixed $incomingGamets): int
{
    $prev = stobeGametsNormalize($prevGamets);
    $incoming = stobeGametsNormalize($incomingGamets);

    if ($prev <= 0 || $incoming <= 0 || $incoming >= $prev) {
        return 0;
    }

    if (!stobeDragonBreakIsEnabled()) {
        return 0;
    }

    $daysRollback = stobeDragonBreakDaysRollback($prev, $incoming);
    if ($daysRollback < stobeDragonBreakMinDays()) {
        return 0;
    }

    $name = stobeDragonBreakBuildName($prev, $incoming);
    $notes = 'Automatic STOBE rollback playthrough save due to rollback of '
        . strval($daysRollback)
        . ' Kenshi day(s) ('
        . strval($incoming)
        . ' -> '
        . strval($prev)
        . ').';

    $playthroughId = stobeDragonBreakCreatePlaythrough($name, $notes, [
        'rollback_delta_days' => $daysRollback,
        'rollback_from_gamets' => $prev,
        'rollback_to_gamets' => $incoming,
    ]);

    if ($playthroughId > 0) {
        stobeLogInfo('STOBE Rollback: Playthrough created', [
            'playthrough_id' => $playthroughId,
            'days_rollback' => $daysRollback,
            'from_gamets' => $prev,
            'to_gamets' => $incoming,
            'name' => $name,
        ]);
    }

    return $playthroughId;
}

?>
