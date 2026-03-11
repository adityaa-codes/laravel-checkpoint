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

it('accepts a valid fake driver configuration', function (): void {
    app()->instance(FakeDriver::class, new FakeDriver);
    config()->set('checkpoint.driver', 'fake');
    config()->set('checkpoint.drivers.fake.class', FakeDriver::class);

    expect(fn () => resolve(ConfigValidator::class)->validate())->not->toThrow(ConfigurationException::class);
});
