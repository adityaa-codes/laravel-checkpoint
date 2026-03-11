<?php

declare(strict_types=1);

return [
    'operations.logical_backup' => 'Logical Backup',
    'operations.logical_restore_latest' => 'Logical Restore (Latest)',
    'operations.logical_restore_file' => 'Logical Restore (Specific File)',
    'operations.pitr_restore' => 'PITR Restore',
    'operations.backup_drill' => 'Backup Drill',
    'operations.pgbackrest_check' => 'pgBackRest Check',
    'operations.pgbackrest_info' => 'pgBackRest Info',

    'status.pending' => 'Pending',
    'status.running' => 'Running',
    'status.succeeded' => 'Succeeded',
    'status.failed' => 'Failed',
    'status.cancelled' => 'Cancelled',

    'errors.invalid_operation' => 'Unsupported operation: :operation',
    'errors.argument_required' => 'Operation :operation requires an argument.',
    'errors.invalid_argument' => 'Invalid argument for :operation. Expected: :hint',
    'errors.config_driver_missing' => 'Driver ":driver" is not defined in checkpoint.drivers config.',
    'errors.config_class_missing' => 'Driver class :class does not exist.',
    'errors.config_log_missing' => 'Log channel ":channel" is not configured.',
    'errors.pre_restore_failed' => 'Pre-restore snapshot failed. Restore aborted.',
    'errors.operation_exclusive' => 'Operation :operation is already running. Only one instance allowed at a time.',

    'cli.backup_queued' => 'Queued :operation run #:id.',
    'cli.orphan_redispatched' => 'Re-dispatched orphaned run #:id.',
    'cli.health_check_failed' => 'Marked run #:id as failed (timed out after :seconds seconds).',
    'cli.pruned' => 'Pruned :count command run records.',
    'cli.doctor_pass' => 'PASS',
    'cli.doctor_warn' => 'WARN',
    'cli.doctor_fail' => 'FAIL',
    'cli.drill_recorded' => 'Recorded backup drill run :uuid (overall: :result).',
];
