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
        $this->validateRestoreSettings();
        $this->validateScheduleSettings();
        $this->validateObservabilitySettings();
        $this->validateReportingSettings();
        $this->validateOutputSettings();
        $this->validateCustomOperations();
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
        $repositories = $config['repositories'] ?? [];
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

        if (! is_array($repositories) || $repositories === []) {
            throw new ConfigurationException('checkpoint.drivers.pgbackrest.repositories must be a non-empty array.');
        }

        if (! array_key_exists($repo, $repositories) || ! is_array($repositories[$repo])) {
            throw new ConfigurationException(
                sprintf('checkpoint.drivers.pgbackrest.repositories must define selected repo [%d].', $repo),
            );
        }

        foreach ($repositories as $repositoryId => $repositoryConfig) {
            $this->validatePgBackRestRepositoryConfig($repositoryId, $repositoryConfig);
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

    private function validatePgBackRestRepositoryConfig(int|string $repositoryId, mixed $repositoryConfig): void
    {
        $repoId = is_int($repositoryId) ? $repositoryId : (int) $repositoryId;
        $prefix = sprintf('checkpoint.drivers.pgbackrest.repositories.%s', (string) $repositoryId);

        if ($repoId < 1 || ! is_array($repositoryConfig)) {
            throw new ConfigurationException(sprintf('%s must be an array keyed by a positive repo id.', $prefix));
        }

        $type = $repositoryConfig['type'] ?? null;

        if (! is_string($type) || ! in_array($type, ['posix', 's3'], true)) {
            throw new ConfigurationException(sprintf('%s.type must be posix or s3.', $prefix));
        }

        if ($type === 'posix') {
            $path = $repositoryConfig['path'] ?? null;

            if (! is_string($path) || trim($path) === '') {
                throw new ConfigurationException(sprintf('%s.path must be a non-empty string for posix repositories.', $prefix));
            }
        }

        if ($type === 's3') {
            $s3 = $repositoryConfig['s3'] ?? null;

            if (! is_array($s3)) {
                throw new ConfigurationException(sprintf('%s.s3 must be an array for s3 repositories.', $prefix));
            }

            foreach (['bucket', 'endpoint', 'region', 'key', 'secret'] as $field) {
                $value = $s3[$field] ?? null;

                if (! is_string($value) || trim($value) === '') {
                    throw new ConfigurationException(sprintf('%s.s3.%s must be a non-empty string.', $prefix, $field));
                }
            }

            $uriStyle = $s3['uri_style'] ?? 'host';

            if (! is_string($uriStyle) || ! in_array($uriStyle, ['host', 'path'], true)) {
                throw new ConfigurationException(sprintf('%s.s3.uri_style must be host or path.', $prefix));
            }
        }

        $tls = $repositoryConfig['tls'] ?? [];

        if (! is_array($tls)) {
            throw new ConfigurationException(sprintf('%s.tls must be an array.', $prefix));
        }

        if (! array_key_exists('verify', $tls) || ! is_bool($tls['verify'])) {
            throw new ConfigurationException(sprintf('%s.tls.verify must be a boolean.', $prefix));
        }

        $caFile = $tls['ca_file'] ?? null;

        if ($caFile !== null && (! is_string($caFile) || trim($caFile) === '')) {
            throw new ConfigurationException(sprintf('%s.tls.ca_file must be null or a non-empty string.', $prefix));
        }

        $encryption = $repositoryConfig['encryption'] ?? [];

        if (! is_array($encryption)) {
            throw new ConfigurationException(sprintf('%s.encryption must be an array.', $prefix));
        }

        $enabled = $encryption['enabled'] ?? false;

        if (! is_bool($enabled)) {
            throw new ConfigurationException(sprintf('%s.encryption.enabled must be a boolean.', $prefix));
        }

        $cipherType = $encryption['cipher_type'] ?? null;

        if (! is_string($cipherType) || trim($cipherType) === '') {
            throw new ConfigurationException(sprintf('%s.encryption.cipher_type must be a non-empty string.', $prefix));
        }

        $passphrase = $encryption['passphrase'] ?? null;

        if ($enabled && (! is_string($passphrase) || trim($passphrase) === '')) {
            throw new ConfigurationException(sprintf('%s.encryption.passphrase must be a non-empty string when encryption is enabled.', $prefix));
        }

        if (! $enabled && $passphrase !== null && (! is_string($passphrase) || trim($passphrase) === '')) {
            throw new ConfigurationException(sprintf('%s.encryption.passphrase must be null or a non-empty string.', $prefix));
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
        $orphanThreshold = (int) $this->config->get('checkpoint.queue.orphan_threshold', 0);
        $orphanClaimTimeout = (int) $this->config->get('checkpoint.queue.orphan_claim_timeout', 0);
        $orphanBatchSize = (int) $this->config->get('checkpoint.queue.orphan_batch_size', 0);
        $orphanEventMaxIds = (int) $this->config->get('checkpoint.queue.orphan_event_max_ids', 0);
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

        if ($orphanThreshold < 1) {
            throw new ConfigurationException('checkpoint.queue.orphan_threshold must be greater than zero.');
        }

        if ($orphanClaimTimeout < 1) {
            throw new ConfigurationException('checkpoint.queue.orphan_claim_timeout must be greater than zero.');
        }

        $minimumClaimTimeout = (int) ceil($retryAfter / 60);

        if ($orphanClaimTimeout < $minimumClaimTimeout) {
            throw new ConfigurationException(
                sprintf(
                    'checkpoint.queue.orphan_claim_timeout must be greater than or equal to %d minutes to align with checkpoint.queue.retry_after.',
                    $minimumClaimTimeout,
                ),
            );
        }

        if ($orphanBatchSize < 1) {
            throw new ConfigurationException('checkpoint.queue.orphan_batch_size must be greater than zero.');
        }

        if ($orphanEventMaxIds < 1) {
            throw new ConfigurationException('checkpoint.queue.orphan_event_max_ids must be greater than zero.');
        }

        if ($orphanEventMaxIds > 1000) {
            throw new ConfigurationException('checkpoint.queue.orphan_event_max_ids must not exceed 1000.');
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

    private function validateRestoreSettings(): void
    {
        $config = $this->config->get('checkpoint.restore', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.restore must be an array.');
        }

        foreach (['allowed_environments', 'allowed_databases'] as $key) {
            $value = $config[$key] ?? [];

            if (! is_array($value)) {
                throw new ConfigurationException(sprintf('checkpoint.restore.%s must be an array.', $key));
            }
        }

        foreach (['require_confirmation', 'allow_in_ci', 'ci', 'require_verified_backup'] as $key) {
            if (! is_bool($config[$key] ?? null)) {
                throw new ConfigurationException(sprintf('checkpoint.restore.%s must be a boolean.', $key));
            }
        }

        $confirmationPhrase = $config['confirmation_phrase'] ?? null;
        $confirmationToken = $config['confirmation_token'] ?? null;

        if (! is_string($confirmationPhrase) || trim($confirmationPhrase) === '') {
            throw new ConfigurationException('checkpoint.restore.confirmation_phrase must be a non-empty string.');
        }

        if ($confirmationToken !== null && (! is_string($confirmationToken) || trim($confirmationToken) === '')) {
            throw new ConfigurationException('checkpoint.restore.confirmation_token must be null or a non-empty string.');
        }
    }

    private function validateObservabilitySettings(): void
    {
        $config = $this->config->get('checkpoint.observability', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.observability must be an array.');
        }

        $maxAge = $config['max_last_known_good_age_hours'] ?? null;
        $factor = $config['backup_duration_anomaly_factor'] ?? null;
        $minSamples = $config['backup_duration_min_samples'] ?? null;
        $maxBackupDrillAgeDays = $config['max_backup_drill_age_days'] ?? null;
        $backupDrillPassRateWindowDays = $config['backup_drill_pass_rate_window_days'] ?? null;
        $backupDrillMinPassRate = $config['backup_drill_min_pass_rate'] ?? null;

        if (! is_int($maxAge) || $maxAge < 1) {
            throw new ConfigurationException('checkpoint.observability.max_last_known_good_age_hours must be greater than zero.');
        }

        if (! is_float($factor) && ! is_int($factor)) {
            throw new ConfigurationException('checkpoint.observability.backup_duration_anomaly_factor must be numeric.');
        }

        if ((float) $factor <= 1.0) {
            throw new ConfigurationException('checkpoint.observability.backup_duration_anomaly_factor must be greater than 1.');
        }

        if (! is_int($minSamples) || $minSamples < 2) {
            throw new ConfigurationException('checkpoint.observability.backup_duration_min_samples must be at least 2.');
        }

        if (! is_int($maxBackupDrillAgeDays) || $maxBackupDrillAgeDays < 1) {
            throw new ConfigurationException('checkpoint.observability.max_backup_drill_age_days must be greater than zero.');
        }

        if (! is_int($backupDrillPassRateWindowDays) || $backupDrillPassRateWindowDays < 1) {
            throw new ConfigurationException('checkpoint.observability.backup_drill_pass_rate_window_days must be greater than zero.');
        }

        if (! is_float($backupDrillMinPassRate) && ! is_int($backupDrillMinPassRate)) {
            throw new ConfigurationException('checkpoint.observability.backup_drill_min_pass_rate must be numeric.');
        }

        if ((float) $backupDrillMinPassRate < 0.0 || (float) $backupDrillMinPassRate > 100.0) {
            throw new ConfigurationException('checkpoint.observability.backup_drill_min_pass_rate must be between 0 and 100.');
        }
    }

    private function validateReportingSettings(): void
    {
        $config = $this->config->get('checkpoint.reporting', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.reporting must be an array.');
        }

        $maxRecentRuns = $config['max_recent_runs'] ?? null;

        if (! is_int($maxRecentRuns) || $maxRecentRuns < 1) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must be greater than zero.');
        }

        if ($maxRecentRuns > 1000) {
            throw new ConfigurationException('checkpoint.reporting.max_recent_runs must not exceed 1000.');
        }
    }

    private function validateOutputSettings(): void
    {
        $config = $this->config->get('checkpoint.output', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.output must be an array.');
        }

        $maxPersistedBytes = $config['max_persisted_bytes'] ?? null;

        if (! is_int($maxPersistedBytes) || $maxPersistedBytes < 1) {
            throw new ConfigurationException('checkpoint.output.max_persisted_bytes must be greater than zero.');
        }

        if ($maxPersistedBytes > 1048576) {
            throw new ConfigurationException('checkpoint.output.max_persisted_bytes must not exceed 1048576.');
        }
    }

    private function validateScheduleSettings(): void
    {
        $config = $this->config->get('checkpoint.schedule', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.schedule must be an array.');
        }

        foreach ([
            'logical_backup_enabled',
            'health_check_enabled',
            'recover_orphans_enabled',
            'prune_enabled',
            'without_overlapping',
            'on_one_server',
        ] as $key) {
            if (! is_bool($config[$key] ?? null)) {
                throw new ConfigurationException(sprintf('checkpoint.schedule.%s must be a boolean.', $key));
            }
        }

        $dailyAt = $config['logical_backup_daily_at'] ?? null;

        if (! is_string($dailyAt) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $dailyAt) !== 1) {
            throw new ConfigurationException('checkpoint.schedule.logical_backup_daily_at must use HH:MM 24-hour format.');
        }

        $timezone = $config['logical_backup_timezone'] ?? null;

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new ConfigurationException('checkpoint.schedule.logical_backup_timezone must be a valid timezone identifier.');
        }

        foreach (['overlap_expires_at', 'prune_keep_days', 'prune_keep_failed_days'] as $key) {
            if (! is_int($config[$key] ?? null) || $config[$key] < 1) {
                throw new ConfigurationException(sprintf('checkpoint.schedule.%s must be greater than zero.', $key));
            }
        }
    }

    private function validateCustomOperations(): void
    {
        $operations = $this->config->get('checkpoint.custom_operations', []);

        if (! is_array($operations)) {
            throw new ConfigurationException('checkpoint.custom_operations must be an array.');
        }

        foreach ($operations as $name => $operation) {
            $prefix = sprintf('checkpoint.custom_operations.%s', (string) $name);

            if (! is_string($name) || trim($name) === '') {
                throw new ConfigurationException('checkpoint.custom_operations keys must be non-empty strings.');
            }

            if (! is_array($operation)) {
                throw new ConfigurationException(sprintf('%s must be an array.', $prefix));
            }

            if (! is_string($operation['label'] ?? null) || trim((string) $operation['label']) === '') {
                throw new ConfigurationException(sprintf('%s.label must be a non-empty string.', $prefix));
            }

            if (! is_bool($operation['argument_required'] ?? null)) {
                throw new ConfigurationException(sprintf('%s.argument_required must be a boolean.', $prefix));
            }

            $argumentHint = $operation['argument_hint'] ?? null;

            if ($argumentHint !== null && (! is_string($argumentHint) || trim($argumentHint) === '')) {
                throw new ConfigurationException(sprintf('%s.argument_hint must be null or a non-empty string.', $prefix));
            }

            $validator = $operation['argument_validator'] ?? null;

            if ($validator !== null && ! is_callable($validator)) {
                throw new ConfigurationException(sprintf('%s.argument_validator must be null or callable.', $prefix));
            }

            foreach (['destructive', 'exclusive'] as $flag) {
                if (! is_bool($operation[$flag] ?? null)) {
                    throw new ConfigurationException(sprintf('%s.%s must be a boolean.', $prefix, $flag));
                }
            }
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
