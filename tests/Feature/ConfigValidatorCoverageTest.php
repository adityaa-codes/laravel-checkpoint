<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Foundation\Auth\User;

it('rejects a missing configured driver', function (): void {
    config()->set('checkpoint.driver', 'missing');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'messages.errors.config_driver_missing');
});

it('rejects a configured driver with a missing class', function (): void {
    config()->set('checkpoint.drivers.shell.class', '');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'messages.errors.config_class_missing');
});

it('rejects a configured driver class that does not implement the backup contract', function (): void {
    config()->set('checkpoint.drivers.shell.class', User::class);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, sprintf('Driver class %s must implement %s.', User::class, BackupDriver::class));
});

it('rejects a missing configured log channel', function (): void {
    config()->set('checkpoint.log_channel', 'missing-channel');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'messages.errors.config_log_missing');
});

it('rejects a missing configured user model', function (): void {
    config()->set('checkpoint.user_model', 'App\\Missing\\User');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'User model class App\\Missing\\User does not exist.');
});

it('rejects an empty pgbackrest stanza', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.stanza', '');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgbackrest.stanza must be a non-empty string.');
});

it('rejects non-array pgbackrest extra args', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.extra_args.info', '--output=json');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgbackrest.extra_args.info must be an array.');
});

it('rejects a pgbackrest config without repositories', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.repositories', []);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgbackrest.repositories must be a non-empty array.');
});

it('rejects a selected pgbackrest repo that is not configured', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.repo', 2);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgbackrest.repositories must define selected repo [2].');
});

it('rejects an s3 pgbackrest repo without required remote settings', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.repositories.1', [
        'type' => 's3',
        's3' => [
            'bucket' => 'checkpoint-backups',
            'endpoint' => '',
            'region' => 'ap-south-1',
            'key' => 'key-id',
            'secret' => 'top-secret',
            'uri_style' => 'host',
        ],
        'tls' => [
            'verify' => true,
            'ca_file' => null,
        ],
        'encryption' => [
            'enabled' => false,
            'cipher_type' => 'aes-256-cbc',
            'passphrase' => null,
        ],
    ]);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgbackrest.repositories.1.s3.endpoint must be a non-empty string.');
});

it('rejects an encrypted pgbackrest repo without a passphrase', function (): void {
    config()->set('checkpoint.drivers.pgbackrest.repositories.1.encryption.enabled', true);
    config()->set('checkpoint.drivers.pgbackrest.repositories.1.encryption.passphrase', '');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgbackrest.repositories.1.encryption.passphrase must be a non-empty string when encryption is enabled.');
});

it('rejects non-array restore safety environment lists', function (): void {
    config()->set('checkpoint.restore.allowed_environments', 'testing');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.restore.allowed_environments must be an array.');
});

it('rejects an empty restore confirmation phrase', function (): void {
    config()->set('checkpoint.restore.confirmation_phrase', '');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.restore.confirmation_phrase must be a non-empty string.');
});

it('rejects pgdump parallel jobs for non-directory formats', function (): void {
    config()->set('checkpoint.drivers.pgdump.format', 'custom');
    config()->set('checkpoint.drivers.pgdump.jobs', 4);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(
            ConfigurationException::class,
            'checkpoint.drivers.pgdump.jobs may only exceed one when format is directory.',
        );
});

it('rejects pgdump compression levels outside the supported range', function (): void {
    config()->set('checkpoint.drivers.pgdump.compress_level', 10);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.pgdump.compress_level must be between 0 and 9.');
});

it('rejects a queue timeout that is not lower than retry_after', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 3600);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(
            ConfigurationException::class,
            'checkpoint.queue.retry_after must be greater than checkpoint.queue.timeout to avoid duplicate job processing.',
        );
});

it('rejects a non-positive queue timeout', function (): void {
    config()->set('checkpoint.queue.timeout', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.timeout must be greater than zero.');
});

it('rejects a non-positive queue retry_after', function (): void {
    config()->set('checkpoint.queue.retry_after', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.retry_after must be greater than zero.');
});

it('rejects a non-positive unique queue lock duration', function (): void {
    config()->set('checkpoint.queue.unique_for', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.unique_for must be greater than zero.');
});

it('rejects a unique queue lock duration shorter than retry_after', function (): void {
    config()->set('checkpoint.queue.retry_after', 3660);
    config()->set('checkpoint.queue.unique_for', 300);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.unique_for must be greater than or equal to checkpoint.queue.retry_after.');
});

it('rejects an unknown queue lock store', function (): void {
    config()->set('checkpoint.queue.lock_store', 'redis-locks');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.lock_store [redis-locks] is not configured in cache.stores.');
});

it('accepts a valid fake driver configuration', function (): void {
    app()->instance(FakeDriver::class, new FakeDriver);
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 3660);
    config()->set('checkpoint.queue.unique_for', 3660);
    config()->set('checkpoint.queue.lock_store', 'array');

    expect(fn () => resolve(ConfigValidator::class)->validate())->not->toThrow(ConfigurationException::class);
});
