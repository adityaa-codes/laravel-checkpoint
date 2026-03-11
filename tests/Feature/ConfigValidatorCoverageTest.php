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

it('accepts a valid fake driver configuration', function (): void {
    app()->instance(FakeDriver::class, new FakeDriver);
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.queue.retry_after', 3660);

    expect(fn () => resolve(ConfigValidator::class)->validate())->not->toThrow(ConfigurationException::class);
});
