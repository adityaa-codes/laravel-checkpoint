<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
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
