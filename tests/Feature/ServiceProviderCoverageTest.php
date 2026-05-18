<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Policies\BackupDrillRunPolicy;
use AdityaaCodes\LaravelCheckpoint\Policies\CommandRunPolicy;
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

it('registers the public commands', function (): void {

    expect(Artisan::all())->toHaveKey('checkpoint:doctor')
        ->toHaveKey('checkpoint:install')
        ->toHaveKey('checkpoint:status')
        ->toHaveKey('checkpoint:drill')
        ->toHaveKey('checkpoint:prune');
});

it('registers the replicate command interface', function (): void {
    expect(Artisan::all())->toHaveKey('checkpoint:replicate');
});
