<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Carbon;

it('prunes old runs while retaining recent failed runs per retention policy', function (): void {
    config()->set('checkpoint.schedule.prune_keep_days', 30);
    config()->set('checkpoint.schedule.prune_keep_failed_days', 365);

    $prunableSucceeded = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'created_at' => Carbon::now()->subDays(45),
        'updated_at' => Carbon::now()->subDays(45),
    ]);

    $retainedFailed = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'status' => CommandRunStatus::Failed,
        'attempts' => 0,
        'created_at' => Carbon::now()->subDays(100),
        'updated_at' => Carbon::now()->subDays(100),
    ]);

    $freshPending = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Carbon::now()->subDays(5),
        'updated_at' => Carbon::now()->subDays(5),
    ]);

    $this->artisan('db-ops:prune')
        ->expectsOutput('Pruned 1 command run records.')
        ->assertSuccessful();

    expect(CommandRun::query()->find($prunableSucceeded->getKey()))->toBeNull()
        ->and(CommandRun::query()->find($retainedFailed->getKey()))->not->toBeNull()
        ->and(CommandRun::query()->find($freshPending->getKey()))->not->toBeNull();
});
