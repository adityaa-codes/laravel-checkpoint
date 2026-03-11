<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

it('shows recent command runs in descending order with the requested limit', function (): void {
    CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'exit_code' => 0,
    ]);

    CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'status' => CommandRunStatus::Failed,
        'attempts' => 0,
        'exit_code' => 1,
    ]);

    checkpoint_artisan('db-ops:status --limit=2')
        ->expectsTable(
            ['ID', 'Operation', 'Status', 'Exit', 'Started', 'Finished'],
            [
                ['3', 'logical_restore_file', 'Failed', '1', '-', '-'],
                ['2', 'pgbackrest_info', 'Succeeded', '0', '-', '-'],
            ],
        )
        ->assertSuccessful();
});
