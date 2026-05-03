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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
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

    expect($commands)->toContain('checkpoint:enqueue-backup')
        ->toContain('checkpoint:enqueue-drill')
        ->toContain('checkpoint:health-check')
        ->toContain('checkpoint:recover-orphans')
        ->toContain('checkpoint:prune');

    $events->each(function ($event): void {
        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(180)
            ->and($event->onOneServer)->toBeTrue();
    });
});

it('registers the public report and catalog commands', function (): void {

    expect(Artisan::all())->toHaveKey('checkpoint:report')
        ->toHaveKey('checkpoint:catalog-export')
        ->toHaveKey('checkpoint:install')
        ->toHaveKey('checkpoint:pitr-readiness')
        ->toHaveKey('checkpoint:retention-policy')
        ->toHaveKey('checkpoint:enqueue-drill');
});

it('registers the replicate command interface', function (): void {
    expect(Artisan::all())->toHaveKey('checkpoint:replicate');
});

it('registers the squashed migration on a fresh install', function (): void {
    $provider = new LaravelCheckpointServiceProvider(app());
    $reflector = new ReflectionClass($provider);
    $method = $reflector->getMethod('isExistingInstallation');
    $method->setAccessible(true);

    Schema::dropIfExists('db_ops_verification_runs');
    Schema::dropIfExists('db_ops_restore_decision_events');
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_command_runs');

    expect($method->invoke($provider))->toBeFalse();

    $package = new Package;

    $provider->configurePackage($package);

    expect($package->migrationFileNames)->toBe([
        'create_checkpoint_tables',
    ]);
});

it('registers incremental migrations when upgrading an existing installation', function (): void {
    Schema::dropIfExists('db_ops_verification_runs');
    Schema::dropIfExists('db_ops_restore_decision_events');
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_command_runs');

    Schema::create('db_ops_command_runs', function (Blueprint $table): void {
        $table->id();
    });

    $provider = new LaravelCheckpointServiceProvider(app());
    $reflector = new ReflectionClass($provider);
    $method = $reflector->getMethod('isExistingInstallation');
    $method->setAccessible(true);

    expect($method->invoke($provider))->toBeTrue();

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

    Schema::dropIfExists('db_ops_command_runs');
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
