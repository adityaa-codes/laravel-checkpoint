<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use Illuminate\Support\Facades\Artisan;

it('resolves the configured backup driver from the service provider binding', function (): void {
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);
    app()->instance(FakeDriver::class, new FakeDriver);

    expect(resolve(BackupDriver::class))->toBeInstanceOf(FakeDriver::class);
});

it('registers the public commands', function (): void {

    expect(Artisan::all())->toHaveKey('checkpoint:install')
        ->toHaveKey('checkpoint:status')
        ->toHaveKey('checkpoint:restore')
        ->toHaveKey('checkpoint:drill')
        ->toHaveKey('checkpoint:prune');
});

it('registers the replicate command interface', function (): void {
    expect(Artisan::all())->toHaveKey('checkpoint:replicate');
});
