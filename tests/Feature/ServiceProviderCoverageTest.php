<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;

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

    expect($commands)->toContain('checkpoint:backup')
        ->toContain('checkpoint:drill')
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
        ->toHaveKey('checkpoint:drill');
});

it('registers the replicate command interface', function (): void {
    expect(Artisan::all())->toHaveKey('checkpoint:replicate');
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
