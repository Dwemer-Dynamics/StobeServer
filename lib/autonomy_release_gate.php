<?php

/**
 * Public beta release gate for the unfinished autonomy feature.
 */

function stobeAutonomyReleaseEnabled(): bool
{
    return false;
}

function stobeAutonomyDisableForRelease(): void
{
    if (stobeAutonomyReleaseEnabled()) {
        return;
    }

    stobeAutonomyEnsureSchema();
    $db = $GLOBALS['db'];
    $db->exec('BEGIN');
    try {
        $db->exec(
            "UPDATE autonomy_decision
             SET status = 'CANCELLED', terminal_at = NOW(),
                 outcome_reason = 'autonomy_release_disabled', updated_at = NOW()
             WHERE session_id = 1 AND status IN ('ISSUED', 'DISPATCHED')"
        );
        $db->exec(
            "UPDATE autonomy_pilot_step
             SET status = 'CANCELLED', updated_at = NOW()
             WHERE session_id = 1 AND status IN ('PENDING', 'CLAIMED')"
        );
        $db->exec(
            "UPDATE autonomy_session
             SET enabled = FALSE, desired_state = 'DISABLED', plugin_state = 'DISABLED',
                 control_revision = control_revision + CASE
                     WHEN enabled OR desired_state <> 'DISABLED' OR plugin_state <> 'DISABLED' THEN 1
                     ELSE 0
                 END,
                 stop_mode = 'normal', active_decision_id = NULL,
                 current_goal = '{}'::jsonb, current_action = '{}'::jsonb,
                 planner_status = 'disabled', planner_failure_count = 0,
                 planner_backoff_seconds = 0, next_decision_local_ts = 0,
                 active_elapsed_ms = 0, last_error = '', updated_at = NOW()
             WHERE id = 1"
        );
        $db->exec('COMMIT');
    } catch (Throwable $exception) {
        $db->exec('ROLLBACK');
        throw $exception;
    }
}

function stobeAutonomyRejectForRelease(): void
{
    if (stobeAutonomyReleaseEnabled()) {
        return;
    }

    stobeAutonomyDisableForRelease();
    stobeAutonomySendJson([
        'ok' => false,
        'available' => false,
        'error' => 'autonomy_unavailable',
    ], 503);
}
