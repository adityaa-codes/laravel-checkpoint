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
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

it('uses the configured driver to process a command run', function (): void {
    Event::fake([
        BackupStarted::class,
        BackupCompleted::class,
        BackupFailed::class,
    ]);

    $driver = new FakeDriver;
    $driver->succeed('physical_backup', 0, 'info');

    app()->instance(FakeDriver::class, $driver);
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);

    $run = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $job = new ProcessCommandRunJob($run);
    Bus::dispatchSync($job);

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

it('skips duplicate delivery when the command run is already running', function (): void {
    Log::shouldReceive('channel->warning')
        ->once()
        ->with('ProcessCommandRunJob skipped duplicate delivery', Mockery::on(
            fn (array $context): bool => $context['run_id'] > 0
                && $context['operation'] === 'logical_backup'
                && $context['driver'] === 'fake'
                && $context['status'] === 'running'
        ));

    $driver = new FakeDriver;

    app()->instance(FakeDriver::class, $driver);
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);

    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Running,
        'attempts' => 1,
        'started_at' => now()->subMinute(),
    ]);

    Bus::dispatchSync(new ProcessCommandRunJob($run));

    expect($driver->calls())->toHaveCount(0);
});

it('skips duplicate delivery after the command run has already completed', function (): void {
    Log::shouldReceive('channel->warning')
        ->once()
        ->with('ProcessCommandRunJob skipped duplicate delivery', Mockery::on(
            fn (array $context): bool => $context['run_id'] > 0
                && $context['operation'] === 'physical_backup'
                && $context['driver'] === 'fake'
                && $context['status'] === 'succeeded'
        ));

    $driver = new FakeDriver;

    app()->instance(FakeDriver::class, $driver);
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);

    $run = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Succeeded,
        'attempts' => 1,
        'exit_code' => 0,
        'finished_at' => now()->subMinute(),
    ]);

    Bus::dispatchSync(new ProcessCommandRunJob($run));

    expect($driver->calls())->toHaveCount(0);
});

it('returns an exclusive unique id for exclusive operations', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(new ProcessCommandRunJob($run)->uniqueId())
        ->toBe('checkpoint-exclusive:logical_backup');
});

it('uses the same unique key for concurrent exclusive backup runs', function (): void {
    $logicalBackupA = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $logicalBackupB = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $fullBackup = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(new ProcessCommandRunJob($logicalBackupA)->uniqueId())
        ->toBe(new ProcessCommandRunJob($logicalBackupB)->uniqueId())
        ->and(new ProcessCommandRunJob($fullBackup)->uniqueId())
        ->not->toBe(new ProcessCommandRunJob($logicalBackupA)->uniqueId());
});

it('returns an exclusive unique id for physical backup operations', function (): void {
    $run = CommandRun::query()->create([
        'operation' => 'physical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    expect(new ProcessCommandRunJob($run)->uniqueId())
        ->toBe('checkpoint-exclusive:physical_backup');
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

it('shares unique locks across separate job instances through the configured cache store', function (): void {
    config()->set('checkpoint.queue.unique_for', 7200);
    config()->set('checkpoint.queue.lock_store', 'array');

    $runA = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $runB = CommandRun::query()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Pending,
        'attempts' => 0,
    ]);

    $jobA = new ProcessCommandRunJob($runA);
    $jobB = new ProcessCommandRunJob($runB);

    $lockA = $jobA->uniqueVia()->lock($jobA->uniqueId(), $jobA->uniqueFor());
    $lockB = $jobB->uniqueVia()->lock($jobB->uniqueId(), $jobB->uniqueFor());

    expect($lockA->get())->toBeTrue()
        ->and($lockB->get())->toBeFalse();

    $lockA->release();

    expect($lockB->get())->toBeTrue();

    $lockB->release();
});

it('forces destructive operations to a single attempt and logs a warning for higher config', function (): void {
    Log::shouldReceive('channel->warning')
        ->once()
        ->with('Destructive checkpoint operation forced to a single attempt', Mockery::on(
            fn (array $context): bool => $context['operation'] === 'logical_restore_file'
                && $context['driver'] === 'mysql'
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
                && $context['operation'] === 'physical_backup'
                && $context['driver'] === 'mysql'
                && $context['error'] === 'boom'
        ));

    $run = CommandRun::query()->create([
        'operation' => 'physical_backup',
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
        && $event->version === 1
        && $event->exception instanceof RuntimeException);
});
