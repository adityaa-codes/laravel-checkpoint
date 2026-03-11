<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

it('re-dispatches stale pending runs and leaves recent pending runs untouched', function (): void {
    Bus::fake();

    Log::shouldReceive('channel->warning')
        ->once()
        ->with('Recover orphans re-dispatched command run', Mockery::on(
            fn (array $context): bool => $context['operation'] === 'logical_backup'
                && $context['run_id'] === 1
        ));

    config()->set('checkpoint.queue.orphan_threshold', 10);

    $staleRun = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Carbon::now()->subMinutes(20),
        'updated_at' => Carbon::now()->subMinutes(20),
    ]);

    $freshRun = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Carbon::now()->subMinutes(5),
        'updated_at' => Carbon::now()->subMinutes(5),
    ]);

    checkpoint_artisan('db-ops:recover-orphans')
        ->expectsOutput('Re-dispatched orphaned run #1.')
        ->assertSuccessful();

    Bus::assertDispatched(ProcessCommandRunJob::class, fn (ProcessCommandRunJob $job): bool => $job->run->is($staleRun));
    Bus::assertNotDispatched(ProcessCommandRunJob::class, fn (ProcessCommandRunJob $job): bool => $job->run->is($freshRun));
});
