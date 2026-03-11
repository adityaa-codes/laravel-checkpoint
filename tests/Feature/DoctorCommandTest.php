<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;

it('renders the doctor health table', function (): void {
    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('Config: driver')
        ->expectsOutputToContain('Config: queue.name')
        ->expectsOutputToContain('DB: command_runs table')
        ->expectsOutputToContain('DB: backup_drill_runs table')
        ->expectsOutputToContain('Orphaned runs')
        ->assertSuccessful();
});

it('throws a configuration exception for invalid config in non-production', function (): void {
    config()->set('checkpoint.table_prefix', '');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.table_prefix must be a non-empty string.');
});
