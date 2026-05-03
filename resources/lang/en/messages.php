<?php

declare(strict_types=1);

return [

    'cli' => [
        'doctor_pass' => 'PASS',
        'doctor_warn' => 'WARN',
        'doctor_fail' => 'FAIL',
        'backup_queued' => 'Queued :operation run #:id.',
        'health_check_failed' => 'Marked run #:id as failed (timed out after :seconds seconds).',
        'pruned_with_drills' => 'Pruned :command_run_count command run records and :backup_drill_count backup drill records.',
        'drill_recorded' => 'Recorded backup drill run :uuid (overall: :result).',
        'orphan_redispatched' => 'Re-dispatched orphaned run #:id.',
    ],

    'operations' => [
        'logical_backup' => 'Logical Backup',
        'logical_restore_latest' => 'Logical Restore (Latest)',
        'logical_restore_file' => 'Logical Restore (Specific File)',
        'pitr_restore' => 'PITR Restore',
        'backup_drill' => 'Backup Drill',
        'pgbackrest_backup_full' => 'pgBackRest Full Backup',
        'pgbackrest_backup_diff' => 'pgBackRest Differential Backup',
        'pgbackrest_backup_incr' => 'pgBackRest Incremental Backup',
        'pgbackrest_restore' => 'pgBackRest Restore',
        'pgbackrest_verify' => 'pgBackRest Verify',
        'pgbackrest_check' => 'pgBackRest Check',
        'pgbackrest_info' => 'pgBackRest Info',
        'replication_sync' => 'Replication Sync',
    ],

    'status' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'errors' => [
        'pre_restore_failed' => 'Pre-restore safety check failed.',
        'config_driver_missing' => 'Driver [:driver] is not configured.',
        'config_class_missing' => 'Driver class [:class] does not exist.',
        'config_log_missing' => 'Log channel [:channel] is not configured in logging.channels.',
    ],

];
