<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

it('renders a machine-readable operational report', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);

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

    Artisan::call('db-ops:report', ['--limit' => 2]);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('report')
        ->and($report['driver'])->toBe('shell')
        ->and($report['recent_runs'])->toHaveCount(2)
        ->and($report['recent_runs'][0])->toMatchArray([
            'id' => 3,
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'restore_target' => 'nightly.sql',
        ])
        ->and($report['summary']['latest_restore_run'])->toMatchArray([
            'operation' => 'logical_restore_file',
            'status' => 'failed',
            'target' => 'nightly.sql',
        ])
        ->and($report['summary']['latest_backup_drill'])->toMatchArray([
            'run_uuid' => 'drill-fail-001',
            'overall_result' => 'fail',
            'executed_by' => 'ops-user',
        ])
        ->and($report['summary']['backup_drill_pass_rate'])->toMatchArray([
            'label' => '0/1 (0.0%)',
            'window_days' => 14,
            'total' => 1,
            'passing' => 0,
            'pass_rate_percent' => 0.0,
        ])
        ->and($report['health']['ok'])->toBeFalse()
        ->and(collect($report['health']['checks'])->contains(
            fn (array $check): bool => $check['check'] === 'Backup drills: pass rate'
                && $check['status'] === 'warn',
        ))->toBeTrue();

    Date::setTestNow();
});

it('returns a failed report when config validation fails', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    $exitCode = Artisan::call('db-ops:report');
    $report = json_decode(Artisan::output(), true);

    expect($exitCode)->toBe(1)
        ->and($report)->toBeArray()
        ->and($report['version'])->toBe(1)
        ->and($report['surface'])->toBe('report')
        ->and($report)->toHaveKeys(['generated_at', 'driver', 'recent_runs', 'summary', 'health'])
        ->and($report['recent_runs'])->toBeArray()
        ->and($report['summary'])->toBeArray()
        ->and($report['health']['ok'])->toBeFalse()
        ->and($report['health']['checks'])->toHaveCount(1)
        ->and($report['health']['checks'][0])->toMatchArray([
            'check' => 'Config validation',
            'status' => 'fail',
        ]);
});
