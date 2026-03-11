<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

/** @internal */
final readonly class ConfigValidator
{
    public function __construct(
        private Repository $config,
    ) {}

    public function validate(): void
    {
        $this->validateDriver();
        $this->validatePgBackRestConfig();
        $this->validatePgDumpConfig();
        $this->validateQueueSettings();
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

    private function validatePgBackRestConfig(): void
    {
        $config = $this->config->get('checkpoint.drivers.pgbackrest', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest must be an array.');
        }

        $binary = $config['binary'] ?? null;
        $stanza = $config['stanza'] ?? null;
        $repo = $config['repo'] ?? null;
        $processMax = $config['process_max'] ?? null;
        $timeout = $config['command_timeout_seconds'] ?? null;

        if (! is_string($binary) || trim($binary) === '') {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.binary must be a non-empty string.');
        }

        if (! is_string($stanza) || trim($stanza) === '') {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.stanza must be a non-empty string.');
        }

        if (! is_int($repo) || $repo < 1) {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.repo must be an integer greater than zero.');
        }

        if (! is_int($processMax) || $processMax < 1) {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.process_max must be an integer greater than zero.');
        }

        if (! is_int($timeout) || $timeout < 1) {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.command_timeout_seconds must be greater than zero.');
        }

        $extraArgs = $config['extra_args'] ?? [];

        if (! is_array($extraArgs)) {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.extra_args must be an array.');
        }

        foreach (['backup', 'restore', 'verify', 'check', 'info'] as $key) {
            if (! array_key_exists($key, $extraArgs) || ! is_array($extraArgs[$key])) {
                throw new ConfigurationException(
                    sprintf('checkpoint.drivers.pgbackrest.extra_args.%s must be an array.', $key),
                );
            }
        }
    }

    private function validatePgDumpConfig(): void
    {
        $config = $this->config->get('checkpoint.drivers.pgdump', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.drivers.pgdump must be an array.');
        }

        $dumpBinary = $config['dump_binary'] ?? null;
        $restoreBinary = $config['restore_binary'] ?? null;
        $format = $config['format'] ?? null;
        $jobs = $config['jobs'] ?? null;
        $compressLevel = $config['compress_level'] ?? null;
        $outputDir = $config['output_dir'] ?? null;
        $outputPrefix = $config['output_prefix'] ?? null;
        $fileExtension = $config['file_extension'] ?? null;
        $timeout = $config['command_timeout_seconds'] ?? null;

        if (! is_string($dumpBinary) || trim($dumpBinary) === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.dump_binary must be a non-empty string.');
        }

        if (! is_string($restoreBinary) || trim($restoreBinary) === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.restore_binary must be a non-empty string.');
        }

        if (! is_string($format) || ! in_array($format, ['directory', 'custom'], true)) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.format must be directory or custom.');
        }

        if (! is_int($jobs) || $jobs < 1) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.jobs must be greater than zero.');
        }

        if ($format !== 'directory' && $jobs !== 1) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.jobs may only exceed one when format is directory.');
        }

        if (! is_int($compressLevel) || $compressLevel < 0 || $compressLevel > 9) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.compress_level must be between 0 and 9.');
        }

        if (! is_string($outputDir) || trim($outputDir) === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.output_dir must be a non-empty string.');
        }

        if (! is_string($outputPrefix) || trim($outputPrefix) === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.output_prefix must be a non-empty string.');
        }

        if (! is_string($fileExtension) || trim($fileExtension) === '') {
            throw new ConfigurationException('checkpoint.drivers.pgdump.file_extension must be a non-empty string.');
        }

        if (! is_int($timeout) || $timeout < 1) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.command_timeout_seconds must be greater than zero.');
        }

        $extraArgs = $config['extra_args'] ?? [];

        if (! is_array($extraArgs)) {
            throw new ConfigurationException('checkpoint.drivers.pgdump.extra_args must be an array.');
        }

        foreach (['backup', 'restore'] as $key) {
            if (! array_key_exists($key, $extraArgs) || ! is_array($extraArgs[$key])) {
                throw new ConfigurationException(
                    sprintf('checkpoint.drivers.pgdump.extra_args.%s must be an array.', $key),
                );
            }
        }
    }

    private function validateQueueSettings(): void
    {
        $timeout = (int) $this->config->get('checkpoint.queue.timeout', 0);
        $retryAfter = (int) $this->config->get('checkpoint.queue.retry_after', 0);
        $uniqueFor = (int) $this->config->get('checkpoint.queue.unique_for', 0);
        $lockStore = $this->config->get('checkpoint.queue.lock_store');

        if ($timeout < 1) {
            throw new ConfigurationException('checkpoint.queue.timeout must be greater than zero.');
        }

        if ($retryAfter < 1) {
            throw new ConfigurationException('checkpoint.queue.retry_after must be greater than zero.');
        }

        if ($retryAfter <= $timeout) {
            throw new ConfigurationException(
                'checkpoint.queue.retry_after must be greater than checkpoint.queue.timeout to avoid duplicate job processing.',
            );
        }

        if ($uniqueFor < 1) {
            throw new ConfigurationException('checkpoint.queue.unique_for must be greater than zero.');
        }

        if ($uniqueFor < $retryAfter) {
            throw new ConfigurationException(
                'checkpoint.queue.unique_for must be greater than or equal to checkpoint.queue.retry_after.',
            );
        }

        if ($lockStore === null || $lockStore === '') {
            return;
        }

        $stores = $this->config->get('cache.stores', []);

        if (! is_string($lockStore) || ! is_array($stores) || ! array_key_exists($lockStore, $stores)) {
            throw new ConfigurationException(
                sprintf('checkpoint.queue.lock_store [%s] is not configured in cache.stores.', (string) $lockStore),
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
