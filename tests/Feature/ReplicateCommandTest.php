<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

it('queues dry-run replication when endpoints are provided as arguments', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    checkpoint_artisan('db-ops:replicate profile:pg-source profile:pg-destination')
        ->expectsOutput('Queued Replication Sync run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();
    $payload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($run->operation)->toBe('replication_sync')
        ->and($payload)->toMatchArray([
            'source' => 'profile:pg-source',
            'destination' => 'profile:pg-destination',
            'dry_run' => true,
            'apply' => false,
            'force_overwrite' => false,
            'critical_tables' => [],
        ])
        ->and($run->metadata['replication']['queue_only'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['dry_run_requested'] ?? null)->toBeTrue();

    Bus::assertDispatched(fn (ProcessCommandRunJob $job): bool => $job->run->is($run));
    Event::assertDispatched(fn (BackupQueued $event): bool => $event->run->is($run));
});

it('prompts for missing source and destination endpoints using hidden input', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    checkpoint_artisan('db-ops:replicate')
        ->expectsQuestion('Enter source replication endpoint', 'profile:pg-source')
        ->expectsQuestion('Enter destination replication endpoint', 'profile:pg-destination')
        ->expectsOutput('Queued Replication Sync run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();
    $payload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['source'] ?? null)->toBe('profile:pg-source')
        ->and($payload['destination'] ?? null)->toBe('profile:pg-destination')
        ->and($payload['dry_run'] ?? null)->toBeTrue();
});

it('queues apply mode with force-overwrite and explicit critical tables', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);
    config()->set('checkpoint.replication.allowlisted_destinations', ['profile:pg-destination']);

    checkpoint_artisan('db-ops:replicate --source=profile:pg-source --destination=profile:pg-destination --apply --force-overwrite --critical-table=users --critical-table=orders')
        ->expectsOutput('Queued Replication Sync run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();
    $payload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toMatchArray([
        'dry_run' => false,
        'apply' => true,
        'force_overwrite' => true,
        'critical_tables' => ['users', 'orders'],
    ])
        ->and($run->metadata['replication']['force_overwrite_requested'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['overwrite_destination'] ?? null)->toBeTrue()
        ->and($run->metadata['replication']['critical_tables'] ?? null)->toBe(['users', 'orders']);
});

it('uses configured critical table fallback when option is omitted', function (): void {
    config()->set('checkpoint.replication.critical_tables', ['accounts', 'invoices']);

    Bus::fake();
    Event::fake([BackupQueued::class]);

    checkpoint_artisan('db-ops:replicate profile:pg-source profile:pg-destination')
        ->expectsOutput('Queued Replication Sync run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();
    $payload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['critical_tables'] ?? null)->toBe(['accounts', 'invoices'])
        ->and($run->metadata['replication']['critical_tables'] ?? null)->toBe(['accounts', 'invoices']);
});

it('surfaces parser validation errors from invalid replication input', function (): void {
    Bus::fake();

    checkpoint_artisan('db-ops:replicate --source=invalid-source --destination=profile:pg-destination')
        ->expectsOutput('Replication endpoint must be one of: profile:<id>, <engine>:// DSN, or key=value pairs.')
        ->assertFailed();

    expect(CommandRun::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('uses option values over positional arguments when both are present', function (): void {
    Bus::fake();
    Event::fake([BackupQueued::class]);

    checkpoint_artisan('db-ops:replicate invalid-source invalid-destination --source=profile:pg-source --destination=profile:pg-destination')
        ->expectsOutput('Queued Replication Sync run #1.')
        ->assertSuccessful();

    $run = CommandRun::query()->sole();
    $payload = json_decode((string) $run->argument_text, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['source'] ?? null)->toBe('profile:pg-source')
        ->and($payload['destination'] ?? null)->toBe('profile:pg-destination');
});

it('fails when apply mode is requested with empty critical-table values', function (): void {
    Bus::fake();

    checkpoint_artisan('db-ops:replicate --source=profile:pg-source --destination=profile:pg-destination --apply --critical-table=')
        ->expectsOutput('Critical tables must be non-empty strings.')
        ->assertFailed();

    expect(CommandRun::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});

it('fails apply mode when destination is outside replication governance allowlist', function (): void {
    Bus::fake();

    config()->set('checkpoint.replication.profiles', [
        'pg-source' => ['engine' => 'pgsql'],
        'prod-destination' => ['engine' => 'pgsql'],
    ]);
    config()->set('checkpoint.replication.allowlisted_destinations', ['staging-replica']);

    checkpoint_artisan('db-ops:replicate --source=profile:pg-source --destination=profile:prod-destination --apply')
        ->expectsOutput('Replication apply is blocked by governance preflight: destination_not_allowlisted.')
        ->assertFailed();

    expect(CommandRun::query()->count())->toBe(0);
    Bus::assertNothingDispatched();
});
