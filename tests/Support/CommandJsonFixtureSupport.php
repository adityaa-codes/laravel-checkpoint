<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Tests\Support;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Support\Facades\Date;

final class CommandJsonFixtureSupport
{
    /**
     * @var list<string>
     */
    private static array $temporaryPitrFiles = [];

    public static function freezeTime(): void
    {
        Date::setTestNow('2026-03-11 12:00:00');
    }

    public static function resetTime(): void
    {
        Date::setTestNow();

        foreach (self::$temporaryPitrFiles as $file) {
            @unlink($file);
        }

        self::$temporaryPitrFiles = [];
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
    }

    public static function seedCatalogExports(): void
    {
        CommandRun::query()->create([
            'operation' => 'physical_backup',
            'driver_name' => 'pgbasebackup',
            'repository' => 1,
            'stanza' => 'main',
            'backup_type' => 'full',
            'backup_label' => '20260311-010101F',
            'artifact_path' => '/var/backups/full-20260311.tar',
            'backup_size_bytes' => 1048576,
            'verification_state' => 'verified',
            'verified_at' => now()->subMinutes(5),
            'last_known_good_at' => now()->subMinutes(4),
            'status' => CommandRunStatus::Succeeded,
            'attempts' => 1,
            'exit_code' => 0,
            'metadata' => [
                'driver' => 'pgbasebackup',
                'flags' => ['nightly'],
                'storage' => ['class' => 'warm'],
            ],
            'created_at' => now()->subMinutes(12),
            'updated_at' => now()->subMinutes(12),
            'started_at' => now()->subMinutes(12),
            'finished_at' => now()->subMinutes(10),
        ]);

        VerificationRun::query()->create([
            'command_run_id' => 1,
            'verification_type' => 'physical_backup',
            'status' => 'verified',
            'verified_at' => now()->subMinutes(5),
            'metadata' => [
                'driver' => 'pgbasebackup',
            ],
        ]);

        CommandRun::query()->create([
            'operation' => 'logical_backup',
            'backup_type' => 'logical_export',
            'backup_label' => 'nightly-002',
            'artifact_path' => '/var/backups/nightly-002.sql',
            'backup_size_bytes' => 256000,
            'verification_state' => 'not_applicable',
            'status' => CommandRunStatus::Succeeded,
            'attempts' => 1,
            'exit_code' => 0,
            'metadata' => [
                'storage' => ['class' => 'hot'],
            ],
            'created_at' => now()->subMinutes(2),
            'updated_at' => now()->subMinutes(2),
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
        ]);
    }

    public static function seedPitrReadinessState(): void
    {
        $suffix = self::parallelProcessSuffix();
        $baseline = sprintf('/tmp/checkpoint-pitr-fixture%s-baseline.sql', $suffix);
        $binlogA = sprintf('/tmp/checkpoint-pitr-fixture%s-binlog-a.log', $suffix);
        $binlogB = sprintf('/tmp/checkpoint-pitr-fixture%s-binlog-b.log', $suffix);

        file_put_contents($baseline, 'baseline');
        file_put_contents($binlogA, 'binlog-a');
        file_put_contents($binlogB, 'binlog-b');
        self::$temporaryPitrFiles = [$baseline, $binlogA, $binlogB];

        config()->set('checkpoint.drivers.mysql.pitr.binlog_files', [$binlogA, $binlogB]);

        CommandRun::query()->create([
            'operation' => 'logical_backup',
            'backup_type' => 'logical_export',
            'backup_label' => 'nightly-pitr-ready',
            'artifact_path' => $baseline,
            'verification_state' => 'verified',
            'last_known_good_at' => now()->subHour(),
            'status' => CommandRunStatus::Succeeded,
            'attempts' => 1,
            'exit_code' => 0,
        ]);
    }

    private static function parallelProcessSuffix(): string
    {
        foreach (['TEST_TOKEN', 'PARATEST', 'PARATEST_PROCESS', 'LARAVEL_PARALLEL_TESTING_TOKEN'] as $key) {
            $token = getenv($key);

            if (! is_string($token) || trim($token) === '') {
                continue;
            }

            return '-'.$token;
        }

        return '';
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
