<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Date;

it('prunes old runs and expired backup drill records while retaining recent protected rows', function (): void {
    config()->set('checkpoint.schedule.prune_keep_days', 30);
    config()->set('checkpoint.schedule.prune_keep_failed_days', 365);
    config()->set('checkpoint.schedule.prune_keep_backup_drill_days', 60);

    $prunableSucceeded = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 0,
        'created_at' => Date::now()->subDays(45),
        'updated_at' => Date::now()->subDays(45),
    ]);

    $retainedFailed = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'status' => CommandRunStatus::Failed,
        'attempts' => 0,
        'created_at' => Date::now()->subDays(100),
        'updated_at' => Date::now()->subDays(100),
    ]);

    $freshPending = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Date::now()->subDays(5),
        'updated_at' => Date::now()->subDays(5),
    ]);

    $prunableDrill = BackupDrillRun::query()->create([
        'run_uuid' => 'drill-prune-old',
        'overall_result' => 'pass',
        'executed_at' => Date::now()->subDays(75),
    ]);

    $retainedDrill = BackupDrillRun::query()->create([
        'run_uuid' => 'drill-keep-recent',
        'overall_result' => 'fail',
        'executed_at' => Date::now()->subDays(10),
    ]);

    checkpoint_artisan('db-ops:prune')
        ->expectsOutput('Pruned 1 command run records and 1 backup drill records.')
        ->assertSuccessful();

    expect(CommandRun::query()->find($prunableSucceeded->getKey()))->toBeNull()
        ->and(CommandRun::query()->find($retainedFailed->getKey()))->not->toBeNull()
        ->and(CommandRun::query()->find($freshPending->getKey()))->not->toBeNull()
        ->and(BackupDrillRun::query()->find($prunableDrill->getKey()))->toBeNull()
        ->and(BackupDrillRun::query()->find($retainedDrill->getKey()))->not->toBeNull();
});
