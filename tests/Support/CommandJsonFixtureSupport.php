<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Tests\Support;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Date;

final class CommandJsonFixtureSupport
{
    public static function freezeTime(): void
    {
        Date::setTestNow('2026-03-11 12:00:00');
    }

    public static function resetTime(): void
    {
        Date::setTestNow();
    }

    public static function seedStatusRuns(): void
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
            'operation' => 'pgbackrest_info',
            'backup_type' => 'full',
            'backup_label' => '20260311-010101F',
            'verification_state' => 'verified',
            'last_known_good_at' => now()->subMinutes(10),
            'status' => CommandRunStatus::Succeeded,
            'attempts' => 0,
            'exit_code' => 0,
        ]);
    }

    public static function seedOperatorState(): void
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
            'operation' => 'pgbackrest_backup_full',
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

        BackupDrillRun::query()->create([
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_by' => 'ops-user',
            'executed_at' => now()->subHours(3),
        ]);
    }

    public static function withEmptyPath(callable $callback): void
    {
        $originalPath = getenv('PATH');

        putenv('PATH=');

        try {
            $callback();
        } finally {
            putenv($originalPath === false ? 'PATH' : 'PATH='.$originalPath);
        }
    }

    public static function seedMysqlDoctorInputs(): void
    {
        config()->set('checkpoint.drivers.mysql.output_dir', sys_get_temp_dir().'/checkpoint-mysql-exports');
        config()->set('checkpoint.drivers.mysql.pitr.binlog_files', [
            '/var/lib/mysql/binlog.000001',
            '/var/lib/mysql/binlog.000002',
        ]);
        config()->set('checkpoint.restore.allowed_environments', ['local', 'testing', 'staging']);
        config()->set('checkpoint.restore.allowed_databases', ['checkpoint_shadow']);
        config()->set('checkpoint.restore.allow_in_ci', false);
        config()->set('checkpoint.restore.require_verified_backup', true);
    }
}
