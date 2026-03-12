<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupStarted;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

it('uses the configured driver to process a command run', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    $driver = new FakeDriver;
    $driver->succeed('pgbackrest_info', 0, 'info');

    app()->instance(FakeDriver::class, $driver);
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);

    $run = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $job = new ProcessCommandRunJob($run);
    $job->handle();

    $run->refresh();

    expect($driver->calls())->toHaveCount(1)
        ->and($driver->calls()[0]->is($run))->toBeTrue()
        ->and($run->status)->toBe(CommandRunStatus::Succeeded)
        ->and($run->exit_code)->toBe(0)
        ->and($run->command_output)->toBe('info');

    Event::assertDispatched(BackupStarted::class);
    Event::assertDispatched(BackupCompleted::class);
    Event::assertNotDispatched(BackupFailed::class);
});

it('returns an exclusive unique id for exclusive operations', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(new ProcessCommandRunJob($run)->uniqueId())
        ->toBe('db-ops-exclusive:logical_backup');
});

it('returns a per-run unique id for non-exclusive operations', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'pgbackrest_check',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(new ProcessCommandRunJob($run)->uniqueId())
        ->toBe('db-ops-run:'.$run->getKey());
});

it('uses the configured unique lock duration and cache store', function (): void {
    config()->set('checkpoint.queue.unique_for', 7200);
    config()->set('checkpoint.queue.lock_store', 'array');

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $job = new ProcessCommandRunJob($run);

    expect($job->uniqueFor())->toBe(7200)
        ->and($job->uniqueVia())->toBeInstanceOf(CacheRepository::class)
        ->and($job->uniqueVia()->getStore())->toBe(resolve(CacheFactory::class)->store('array')->getStore());
});

it('forces destructive operations to a single attempt and logs a warning for higher config', function (): void {
    Log::shouldReceive('channel->warning')
        ->once()
        ->with('Destructive checkpoint operation forced to a single attempt', Mockery::on(
            fn (array $context): bool => $context['operation'] === 'logical_restore_file'
                && $context['driver'] === 'shell'
                && $context['restore_target'] === 'nightly.sql'
                && $context['configured_attempts'] === 5
        ));

    config()->set('checkpoint.queue.max_attempts', 5);

    $run = CommandRun::query()->create([
        'operation' => 'logical_restore_file',
        'argument_text' => 'nightly.sql',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(new ProcessCommandRunJob($run)->tries())->toBe(1);
});

it('marks the run failed and emits the failure event in the failed callback', function (): void {
    Event::fake([BackupFailed::class]);

    Log::shouldReceive('channel->error')
        ->once()
        ->with('ProcessCommandRunJob failed', Mockery::on(
            fn (array $context): bool => $context['run_id'] > 0
                && $context['operation'] === 'pgbackrest_info'
                && $context['driver'] === 'shell'
                && $context['error'] === 'boom'
        ));

    $run = CommandRun::query()->create([
        'operation' => 'pgbackrest_info',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    new ProcessCommandRunJob($run)->failed(new RuntimeException('boom'));

    $run->refresh();

    expect($run->status)->toBe(CommandRunStatus::Failed)
        ->and($run->exit_code)->toBe(-1)
        ->and($run->command_output)->toBe('boom');

    Event::assertDispatched(fn (BackupFailed $event): bool => $event->run->is($run)
        && $event->exitCode === -1
        && $event->output === 'boom'
        && $event->exception instanceof RuntimeException);
});
