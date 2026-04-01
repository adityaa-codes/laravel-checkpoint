<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

it('creates a pending command run and dispatches processing after commit', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    $run = resolve(EnqueueCommandRunAction::class)->execute('logical_restore_file', ' nightly.sql ');

    expect($run->exists)->toBeTrue()
        ->and($run->status)->toBe(CommandRunStatus::Pending)
        ->and($run->argument_text)->toBe('nightly.sql')
        ->and($run->attempts)->toBe(0);

    /** @var CommandRun|null $storedRun */
    $storedRun = CommandRun::query()->find($run->getKey());

    expect($storedRun)->not->toBeNull();
    expect($storedRun?->argument_text)->toBe('nightly.sql');

    Bus::assertDispatched(fn (ProcessCommandRunJob $job): bool => $job->run->is($run)
        && $job->queue === 'db-ops'
        && $job->afterCommit === true);

    Event::assertDispatched(fn (BackupQueued $event): bool => $event->run->is($run));
});

it('rejects invalid arguments without creating a run or dispatching a job', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    expect(fn () => resolve(EnqueueCommandRunAction::class)->execute('logical_restore_file'))
        ->toThrow(InvalidArgumentException::class);

    expect(CommandRun::query()->count())->toBe(0);

    Bus::assertNothingDispatched();
    Event::assertNotDispatched(BackupQueued::class);
});

it('normalizes replication enqueue payloads and stores redacted metadata only', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    config()->set('checkpoint.replication.profiles', [
        'pg-source' => ['engine' => 'pgsql'],
        'pg-destination' => ['engine' => 'pgsql'],
    ]);

    $run = resolve(EnqueueCommandRunAction::class)->execute(
        'replication_sync',
        '{"source":"profile:pg-source","destination":"pgsql://replicator:supersecret@db.internal/prod","dry_run":true}',
    );

    $serializedPayload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($run->operation)->toBe('replication_sync')
        ->and($serializedPayload)->toMatchArray([
            'source' => 'profile:pg-source',
            'destination' => 'pgsql://[REDACTED]',
            'dry_run' => true,
            'apply' => false,
            'force_overwrite' => false,
            'critical_tables' => [],
        ])
        ->and($run->argument_text)->not->toContain('supersecret')
        ->and($run->metadata)->toBeArray()
        ->and($run->metadata['replication']['engine'] ?? null)->toBe('pgsql')
        ->and($run->metadata['replication']['queue_only'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['dry_run_requested'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['apply_requested'] ?? null)->toBeFalse()
        ->and($run->metadata['replication']['force_requested'] ?? null)->toBeFalse()
        ->and($run->metadata['replication']['force_overwrite_requested'] ?? null)->toBeFalse()
        ->and($run->metadata['replication']['overwrite_destination'] ?? null)->toBeFalse()
        ->and($run->metadata['replication']['critical_tables'] ?? null)->toBe([])
        ->and($run->metadata['replication']['source']['kind'] ?? null)->toBe('config_profile')
        ->and($run->metadata['replication']['source']['identifier'] ?? null)->toBe('pg-source')
        ->and($run->metadata['replication']['destination']['kind'] ?? null)->toBe('dsn')
        ->and($run->metadata['replication']['destination']['redacted'] ?? null)->toBe('pgsql://[REDACTED]@db.internal')
        ->and(json_encode($run->metadata, JSON_THROW_ON_ERROR))->not->toContain('supersecret');

    Bus::assertDispatched(fn (ProcessCommandRunJob $job): bool => $job->run->is($run)
        && $job->queue === 'db-ops'
        && $job->afterCommit === true);
});

it('maps replication apply and force aliases into operation metadata', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    config()->set('checkpoint.replication.profiles', [
        'pg-source' => ['engine' => 'pgsql'],
        'pg-destination' => ['engine' => 'pgsql'],
    ]);

    $run = resolve(EnqueueCommandRunAction::class)->execute(
        'replication_sync',
        '{"source":"profile:pg-source","destination":"profile:pg-destination","dry_run":false,"apply":true,"force":true,"critical_tables":["users","orders"]}',
    );

    $payload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'dry_run' => false,
        'apply' => true,
        'force_overwrite' => true,
        'critical_tables' => ['users', 'orders'],
    ])->and($run->metadata['replication']['dry_run_requested'] ?? null)->toBeFalse()
        ->and($run->metadata['replication']['apply_requested'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['force_requested'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['force_overwrite_requested'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['overwrite_destination'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['critical_tables'] ?? null)->toBe(['users', 'orders']);
});
