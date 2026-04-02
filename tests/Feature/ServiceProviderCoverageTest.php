<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\LaravelCheckpointServiceProvider;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;

it('resolves the configured backup driver from the service provider binding', function (): void {
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);
    app()->instance(FakeDriver::class, new FakeDriver);

    expect(resolve(BackupDriver::class))->toBeInstanceOf(FakeDriver::class);
});

it('registers command and drill policies on boot', function (): void {
    expect(Gate::getPolicyFor(CommandRun::class))->toBeInstanceOf(CommandRunPolicy::class)
        ->and(Gate::getPolicyFor(BackupDrillRun::class))->toBeInstanceOf(BackupDrillRunPolicy::class);
});

it('registers the default scheduled checkpoint commands', function (): void {
    config()->set('checkpoint.schedule.backup_drill_enabled', true);

    app()->forgetInstance(Schedule::class);

    $events = collect(resolve(Schedule::class)->events());
    $commands = $events
        ->map(static fn ($event): ?string => $event->command)
        ->filter()
        ->implode("\n");

    expect($commands)->toContain('db-ops:enqueue-backup')
        ->toContain('db-ops:enqueue-drill')
        ->toContain('db-ops:health-check')
        ->toContain('db-ops:recover-orphans')
        ->toContain('db-ops:prune');

    $events->each(function ($event): void {
        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(180)
            ->and($event->onOneServer)->toBeTrue();
    });
});

it('registers the public report and catalog commands', function (): void {

    expect(Artisan::all())->toHaveKey('db-ops:report')
        ->toHaveKey('db-ops:catalog-export')
        ->toHaveKey('db-ops:pitr-readiness')
        ->toHaveKey('db-ops:retention-policy')
        ->toHaveKey('db-ops:enqueue-drill');
});

it('registers the replicate command interface', function (): void {
    expect(Artisan::all())->toHaveKey('db-ops:replicate');
});

it('registers published migrations in dependency order', function (): void {
    $provider = new LaravelCheckpointServiceProvider(app());
    $package = new Package;

    $provider->configurePackage($package);

    expect($package->migrationFileNames)->toBe([
        'create_checkpoint_command_runs_table',
        'add_checkpoint_metadata_to_command_runs_table',
        'add_orphan_recovery_claim_to_command_runs_table',
        'add_heartbeat_to_command_runs_table',
        'add_operator_summary_columns_to_command_runs_table',
        'create_checkpoint_restore_decision_events_table',
        'create_checkpoint_backup_drill_runs_table',
        'create_checkpoint_verification_runs_table',
        'add_reporting_indexes_to_checkpoint_tables',
    ]);
});

it('can disable schedule overlap and cluster guards', function (): void {
    config()->set('checkpoint.schedule.without_overlapping', false);
    config()->set('checkpoint.schedule.on_one_server', false);

    app()->forgetInstance(Schedule::class);

    collect(resolve(Schedule::class)->events())->each(function ($event): void {
        expect($event->withoutOverlapping)->toBeFalse()
            ->and($event->onOneServer)->toBeFalse();
    });
});

it('validates config during production boot', function (): void {
    $provider = new LaravelCheckpointServiceProvider(app());

    app()['env'] = 'production';
    config()->set('checkpoint.table_prefix', '');

    expect(fn () => $provider->packageBooted())
        ->toThrow(ConfigurationException::class, 'checkpoint.table_prefix must be a non-empty string.');

    app()['env'] = 'testing';
});

it('validates config during non-production boot', function (): void {
    $provider = new LaravelCheckpointServiceProvider(app());

    app()['env'] = 'testing';
    config()->set('checkpoint.table_prefix', '');

    expect(fn () => $provider->packageBooted())
        ->toThrow(ConfigurationException::class, 'checkpoint.table_prefix must be a non-empty string.');
});
