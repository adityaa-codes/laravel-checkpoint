<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Events\OrphanRunRedispatched;
use AdityaaCodes\LaravelCheckpoint\Events\QueueLagDetected;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

it('re-dispatches stale pending runs and leaves recent pending runs untouched', function (): void {
    Date::setTestNow('2026-03-12 10:00:00');

    Bus::fake();
    Event::fake([QueueLagDetected::class, OrphanRunRedispatched::class]);

    Log::shouldReceive('channel->warning')
        ->once()
        ->with('Recover orphans re-dispatched command run', Mockery::on(
            fn (array $context): bool => $context['operation'] === 'logical_backup'
                && $context['run_id'] === 1
                && $context['driver'] === 'shell'
                && $context['queue'] === 'db-ops'
                && $context['threshold_minutes'] === 10
                && $context['stale_age_minutes'] === 20
        ));

    config()->set('checkpoint.queue.orphan_threshold', 10);

    $staleRun = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Date::now()->subMinutes(20),
        'updated_at' => Date::now()->subMinutes(20),
    ]);

    $freshRun = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Date::now()->subMinutes(5),
        'updated_at' => Date::now()->subMinutes(5),
    ]);

    checkpoint_artisan('db-ops:recover-orphans')
        ->expectsOutput('Re-dispatched orphaned run #1.')
        ->assertSuccessful();

    Bus::assertDispatched(fn (ProcessCommandRunJob $job): bool => $job->run->is($staleRun));
    Bus::assertNotDispatched(ProcessCommandRunJob::class, fn (ProcessCommandRunJob $job): bool => $job->run->is($freshRun));
    Event::assertDispatched(fn (QueueLagDetected $event): bool => $event->queue === 'db-ops'
        && $event->staleRunCount === 1
        && $event->thresholdMinutes === 10
        && $event->oldestStaleAgeMinutes === 20
        && $event->staleRunIds === [1]);
    Event::assertDispatched(fn (OrphanRunRedispatched $event): bool => $event->run->is($staleRun)
        && $event->queue === 'db-ops'
        && $event->thresholdMinutes === 10
        && $event->staleAgeMinutes === 20);

    Date::setTestNow();
});

it('re-dispatches large stale batches with aggregate lag details', function (): void {
    Date::setTestNow('2026-03-12 10:00:00');

    Bus::fake();
    Event::fake([QueueLagDetected::class, OrphanRunRedispatched::class]);
    Log::shouldReceive('channel->warning')->times(25);

    config()->set('checkpoint.queue.orphan_threshold', 10);

    $staleRuns = collect(range(1, 25))->map(fn (int $index): CommandRun => CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Date::now()->subMinutes(20 + $index),
        'updated_at' => Date::now()->subMinutes(20 + $index),
    ]));

    CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
        'created_at' => Date::now()->subMinutes(5),
        'updated_at' => Date::now()->subMinutes(5),
    ]);

    checkpoint_artisan('db-ops:recover-orphans')->assertSuccessful();

    Bus::assertDispatchedTimes(ProcessCommandRunJob::class, 25);
    $lagEvents = Event::dispatched(QueueLagDetected::class);
    expect($lagEvents)->toHaveCount(1);

    /** @var QueueLagDetected $lagEvent */
    $lagEvent = $lagEvents->first()[0];

    expect($lagEvent->queue)->toBe('db-ops')
        ->and($lagEvent->staleRunCount)->toBe(25)
        ->and($lagEvent->thresholdMinutes)->toBe(10)
        ->and($lagEvent->oldestStaleAgeMinutes)->toBeGreaterThanOrEqual(45)
        ->and($lagEvent->staleRunIds)->toHaveCount(25);
    Event::assertDispatchedTimes(OrphanRunRedispatched::class, 25);

    Date::setTestNow();
});
