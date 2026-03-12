<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;

it('renders the doctor health table', function (): void {
    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('Config: driver')
        ->expectsOutputToContain('Config: queue.name')
        ->expectsOutputToContain('Config: pgbackrest.stanza')
        ->expectsOutputToContain('Config: pgbackrest.repositories')
        ->expectsOutputToContain('Repo: pgbackrest.active')
        ->expectsOutputToContain('Repo: pgbackrest.target')
        ->expectsOutputToContain('Repo: pgbackrest.tls')
        ->expectsOutputToContain('Repo: pgbackrest.encryption')
        ->expectsOutputToContain('Binary: pgBackRest')
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

it('fails doctor when queue timeout settings are unsafe', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 300);

    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('Config validation')
        ->assertFailed();
});

it('shows the configured pgbackrest binary when it is missing from path', function (): void {
    config()->set('checkpoint.driver', 'pgbackrest');
    config()->set('checkpoint.drivers.pgbackrest.binary', 'missing-pgbackrest-binary');

    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('Binary: pgBackRest')
        ->assertSuccessful();
});

it('shows selected remote repo hardening details without secrets', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.repositories.1', [
        'type' => 's3',
        'path' => null,
        's3' => [
            'bucket' => 'checkpoint-backups',
            'endpoint' => 's3.example.com',
            'region' => 'ap-south-1',
            'key' => 'hidden-key',
            'secret' => 'hidden-secret',
            'uri_style' => 'host',
        ],
        'tls' => [
            'verify' => false,
            'ca_file' => '/etc/ssl/checkpoint.pem',
        ],
        'encryption' => [
            'enabled' => true,
            'cipher_type' => 'aes-256-cbc',
            'passphrase' => 'hidden-passphrase',
        ],
    ]);

    checkpoint_artisan('db-ops:doctor')
        ->expectsOutputToContain('s3://checkpoint-backups via s3.example.com')
        ->expectsOutputToContain('verify disabled')
        ->expectsOutputToContain('enabled (aes-256-cbc)')
        ->doesntExpectOutputToContain('hidden-key')
        ->doesntExpectOutputToContain('hidden-secret')
        ->doesntExpectOutputToContain('hidden-passphrase')
        ->assertSuccessful();
});
