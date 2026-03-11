<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

class ConfigValidator
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function validate(): void
    {
        $this->validateDriver();
        $this->validateLogChannel();
        $this->validateUserModel();
        $this->validateTablePrefix();
    }

    private function validateDriver(): void
    {
        $driver = (string) $this->config->get('checkpoint.driver', '');
        $drivers = $this->config->get('checkpoint.drivers', []);

        if (! is_array($drivers) || ! array_key_exists($driver, $drivers)) {
            throw new ConfigurationException(
                (string) __('messages.errors.config_driver_missing', ['driver' => $driver]),
            );
        }

        $class = $drivers[$driver]['class'] ?? null;

        if (! is_string($class) || $class === '' || ! class_exists($class)) {
            throw new ConfigurationException(
                (string) __('messages.errors.config_class_missing', ['class' => (string) $class]),
            );
        }

        if (! is_subclass_of($class, BackupDriver::class)) {
            throw new ConfigurationException(
                sprintf('Driver class %s must implement %s.', $class, BackupDriver::class),
            );
        }
    }

    private function validateLogChannel(): void
    {
        $channel = (string) $this->config->get('checkpoint.log_channel', '');
        $channels = $this->config->get('logging.channels', []);

        if (! is_array($channels) || ! array_key_exists($channel, $channels)) {
            throw new ConfigurationException(
                (string) __('messages.errors.config_log_missing', ['channel' => $channel]),
            );
        }
    }

    private function validateUserModel(): void
    {
        $userModel = (string) $this->config->get('checkpoint.user_model', '');

        if ($userModel === '' || ! class_exists($userModel)) {
            throw new ConfigurationException(
                sprintf('User model class %s does not exist.', $userModel),
            );
        }
    }

    private function validateTablePrefix(): void
    {
        $tablePrefix = $this->config->get('checkpoint.table_prefix');

        if (! is_string($tablePrefix) || trim($tablePrefix) === '') {
            throw new ConfigurationException('checkpoint.table_prefix must be a non-empty string.');
        }
    }
}
