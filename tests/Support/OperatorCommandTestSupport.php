<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Tests\Support;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Support\Facades\Date;

final class OperatorCommandTestSupport
{
    public static function freezeTime(): void
    {
        Date::setTestNow('2026-03-11 12:00:00');
    }

    public static function resetTime(): void
    {
        Date::setTestNow();
    }

    public static function seedRecentRuns(): void
    {
        CommandRun::query()->create([
            'operation' => 'logical_backup',
            'backup_type' => 'logical_export',
            'backup_label' => 'nightly-001',
            'verification_state' => 'not_applicable',
            'last_known_good_at' => now()->subHour(),
            'status' => CommandRunStatus::Pending,
            'attempts' => 0,
        ]);

        CommandRun::query()->create([
            'operation' => 'physical_backup',
            'backup_type' => 'full',
            'backup_label' => '20260311-010101F',
            'verification_state' => 'verified',
            'last_known_good_at' => now()->subMinutes(10),
            'status' => CommandRunStatus::Succeeded,
            'attempts' => 0,
            'exit_code' => 0,
        ]);
    }

    public static function seedOperatorSummaryState(bool $includeRunningRun = false): void
    {
        CommandRun::query()->create([
            'operation' => 'logical_backup',
            'backup_type' => 'logical_export',
            'backup_label' => 'nightly-001',
            'verification_state' => 'not_applicable',
            'status' => CommandRunStatus::Pending,
            'attempts' => 0,
        ]);

        CommandRun::query()->create([
            'operation' => 'physical_backup',
            'backup_type' => 'full',
            'backup_label' => '20260311-010101F',
            'verification_state' => 'verified',
            'verified_at' => now()->subMinutes(5),
            'last_known_good_at' => now()->subMinutes(10),
            'status' => CommandRunStatus::Succeeded,
            'attempts' => 1,
            'exit_code' => 0,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
        ]);

        if ($includeRunningRun) {
            CommandRun::query()->create([
                'operation' => 'physical_backup',
                'status' => CommandRunStatus::Running,
                'attempts' => 1,
                'created_at' => now()->subMinutes(2),
                'updated_at' => now()->subMinutes(2),
                'started_at' => now()->subMinute(),
            ]);
        }

        CommandRun::query()->create([
            'operation' => 'logical_restore_file',
            'argument_text' => 'nightly.sql',
            'restore_target' => 'nightly.sql',
            'metadata' => [
                'restore_audit' => [
                    'environment' => 'testing',
                    'database' => ':memory:',
                    'target' => 'nightly.sql',
                    'confirmation_required' => true,
                    'confirmation_satisfied_via' => 'token',
                    'verified_backup_required' => true,
                    'verified_signal_run_id' => 2,
                    'post_restore_verification' => [
                        'contract_version' => 1,
                        'command_run_id' => 3,
                        'operation' => 'logical_restore_file',
                        'generated_at' => now()->subMinutes(18)->toIso8601String(),
                        'aggregate_result' => 'fail',
                        'checks_performed' => [
                            'restore_audit_recorded',
                            'restore_target_recorded',
                            'command_exit_code_zero',
                            'verified_backup_signal_linkage',
                        ],
                        'checks' => [
                            [
                                'name' => 'restore_audit_recorded',
                                'passed' => true,
                                'status' => 'pass',
                                'description' => 'restore guard decision metadata was persisted',
                                'observed' => 'recorded',
                            ],
                            [
                                'name' => 'restore_target_recorded',
                                'passed' => true,
                                'status' => 'pass',
                                'description' => 'restore target is present for post-restore verification linkage',
                                'observed' => 'nightly.sql',
                            ],
                            [
                                'name' => 'command_exit_code_zero',
                                'passed' => false,
                                'status' => 'fail',
                                'description' => 'restore command finished with exit code 0',
                                'observed' => 1,
                            ],
                            [
                                'name' => 'verified_backup_signal_linkage',
                                'passed' => true,
                                'status' => 'pass',
                                'description' => 'verified-backup requirement is satisfied when enabled',
                                'observed' => [
                                    'required' => true,
                                    'verified_signal_run_id' => 2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'verification_state' => 'failed',
            'status' => CommandRunStatus::Failed,
            'attempts' => 1,
            'exit_code' => 1,
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
            'finished_at' => now()->subMinutes(18),
        ]);

        VerificationRun::query()->create([
            'command_run_id' => 2,
            'verification_type' => 'physical_backup',
            'status' => 'verified',
            'verified_at' => now()->subMinutes(5),
            'metadata' => [
                'driver' => 'pgbasebackup',
                'summary' => ['ok' => true],
            ],
        ]);

        VerificationRun::query()->create([
            'command_run_id' => 3,
            'verification_type' => 'physical_backup',
            'status' => 'failed',
            'verified_at' => now()->subMinutes(3),
            'metadata' => [
                'driver' => 'pgbasebackup',
            ],
            'error_detail' => 'Restore verification failed',
        ]);

        BackupDrillRun::query()->create([
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_by' => 'ops-user',
            'executed_at' => now()->subHours(3),
        ]);

        BackupDrillRun::query()->create([
            'run_uuid' => 'drill-pass-001',
            'overall_result' => 'pass',
            'executed_by' => 'ci-pipeline',
            'executed_at' => now()->subHours(6),
        ]);
    }
}
