<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

function seed_status_contract_runs(): void
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

function seed_operator_contract_state(): void
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

function with_checkpoint_empty_path(callable $callback): void
{
    $originalPath = getenv('PATH');

    putenv('PATH=');

    try {
        $callback();
    } finally {
        putenv($originalPath === false ? 'PATH' : 'PATH='.$originalPath);
    }
}

it('matches the status runs json fixture', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');
    seed_status_contract_runs();

    Artisan::call('db-ops:status', ['--limit' => 1, '--format' => 'json']);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/status-runs.json',
    );

    Date::setTestNow();
});

it('matches the status summary json fixture', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');
    seed_operator_contract_state();

    Artisan::call('db-ops:status', ['--summary' => true, '--format' => 'json']);

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/status-summary.json',
    );

    Date::setTestNow();
});

it('matches the doctor json fixture', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    seed_operator_contract_state();

    with_checkpoint_empty_path(function (): void {
        Artisan::call('db-ops:doctor', ['--format' => 'json']);
    });

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/doctor.json',
    );

    Date::setTestNow();
});

it('matches the operational report json fixture', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);
    seed_operator_contract_state();

    with_checkpoint_empty_path(function (): void {
        Artisan::call('db-ops:report', ['--limit' => 2]);
    });

    checkpoint_assert_matches_fixture(
        json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR),
        'command-json/report.json',
    );

    Date::setTestNow();
});
