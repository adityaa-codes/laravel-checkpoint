<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;

it('shows recent command runs in descending order with the requested limit', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

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
        'operation' => 'pgbackrest_info',
        'backup_type' => 'full',
        'backup_label' => '20260311-010101F',
        'verification_state' => 'verified',
        'last_known_good_at' => now()->subMinutes(10),
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'exit_code' => 0,
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'restore_target' => 'nightly.sql',
        'verification_state' => 'failed',
        'status' => CommandRunStatus::Failed,
        'attempts' => 0,
        'exit_code' => 1,
    ]);

    checkpoint_artisan('db-ops:status --limit=2')
        ->expectsTable(
            ['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Last Good', 'Started', 'Finished'],
            [
                ['3', 'logical_restore_file', 'Failed', '1', '-', 'failed', '-', '-', '-'],
                ['2', 'pgbackrest_info', 'Succeeded', '0', 'full:20260311-010101F', 'verified', '2026-03-11 11:50:00', '-', '-'],
            ],
        )
        ->assertSuccessful();

    Date::setTestNow();
});

it('shows an operator-facing summary of recent checkpoint health signals', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'backup_type' => 'logical_export',
        'backup_label' => 'nightly-001',
        'verification_state' => 'not_applicable',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup',
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
        'operation' => 'pgbackrest_check',
        'status' => CommandRunStatus::Running,
        'attempts' => 1,
        'created_at' => now()->subMinutes(2),
        'updated_at' => now()->subMinutes(2),
        'started_at' => now()->subMinute(),
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'restore_target' => 'nightly.sql',
        'verification_state' => 'failed',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
        'exit_code' => 1,
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(18),
    ]);

    checkpoint_artisan('db-ops:status --summary')
        ->expectsTable(
            ['Signal', 'Value'],
            [
                ['Pending runs', '1'],
                ['Running runs', '1'],
                ['Failed runs (24h)', '1'],
                ['Last known good backup', 'full:20260311-010101F at 2026-03-11 11:50:00'],
                ['Latest verified backup', 'full:20260311-010101F at 2026-03-11 11:55:00'],
                ['Latest restore failure', 'logical_restore_file (nightly.sql) at 2026-03-11 11:42:00'],
            ],
        )
        ->assertSuccessful();

    Date::setTestNow();
});

it('renders recent runs as machine-readable json', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

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

    Artisan::call('db-ops:status', ['--limit' => 1, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['mode'])->toBe('runs')
        ->and($report['limit'])->toBe(1)
        ->and($report['runs'])->toHaveCount(1)
        ->and($report['runs'][0])->toMatchArray([
            'id' => 2,
            'operation' => 'pgbackrest_info',
            'status' => 'succeeded',
            'exit_code' => 0,
            'backup' => 'full:20260311-010101F',
            'verification_state' => 'verified',
            'last_known_good_at' => '2026-03-11 11:50:00',
        ]);

    Date::setTestNow();
});

it('renders summary signals as machine-readable json', function (): void {
    Date::setTestNow('2026-03-11 12:00:00');

    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'backup_type' => 'logical_export',
        'backup_label' => 'nightly-001',
        'verification_state' => 'not_applicable',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    CommandRun::query()->create([
        'operation' => 'pgbackrest_backup',
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
        'verification_state' => 'failed',
        'status' => CommandRunStatus::Failed,
        'attempts' => 1,
        'exit_code' => 1,
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
        'finished_at' => now()->subMinutes(18),
    ]);

    Artisan::call('db-ops:status', ['--summary' => true, '--format' => 'json']);

    $report = json_decode(Artisan::output(), true);

    expect($report)->toBeArray()
        ->and($report['mode'])->toBe('summary')
        ->and($report['summary'])->toMatchArray([
            'pending_runs' => 1,
            'running_runs' => 0,
            'failed_runs_24h' => 1,
        ])
        ->and($report['summary']['last_known_good_backup'])->toMatchArray([
            'label' => 'full:20260311-010101F at 2026-03-11 11:50:00',
            'timestamp' => '2026-03-11 11:50:00',
            'operation' => 'pgbackrest_backup',
        ])
        ->and($report['summary']['latest_restore_failure'])->toMatchArray([
            'label' => 'logical_restore_file (nightly.sql) at 2026-03-11 11:42:00',
            'timestamp' => '2026-03-11 11:42:00',
            'operation' => 'logical_restore_file',
            'target' => 'nightly.sql',
        ]);

    Date::setTestNow();
});

it('fails for unsupported status output formats', function (): void {
    checkpoint_artisan('db-ops:status --format=xml')
        ->expectsOutput('The --format option must be table or json.')
        ->assertFailed();
});
