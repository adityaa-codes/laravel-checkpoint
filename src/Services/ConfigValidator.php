<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\MysqlDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgBaseBackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PgDumpDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\PostgresDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\ShellCommandDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Support\NotificationEventMap;
use Illuminate\Contracts\Config\Repository;

/** @internal */
final readonly class ConfigValidator
{
    public function __construct(
        private Repository $config,
    ) {}

    public function validate(): void
    {
        $errors = [];

        foreach ([
            'validateDriver',
            'validatePgDumpConfig',
            'validateMysqlConfig',
            'validateQueueSettings',
            'validateDriverTimeoutBudgets',
            'validateRestoreSettings',
            'validateScheduleSettings',
            'validateRetentionSettings',
            'validateObservabilitySettings',
            'validateReportingSettings',
            'validateGateSettings',
            'validateNotificationSettings',
            'validateOutputSettings',
            'validateReplicationSettings',
            'validateCustomOperations',
            'validateLogChannel',
            'validateUserModel',
            'validateTablePrefix',
            'validateTempDirectory',
        ] as $method) {
            try {
                $this->$method();
            } catch (ConfigurationException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($errors !== []) {
            throw new ConfigurationException(implode("\n", $errors));
        }
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

        $class = $drivers[$driver]['class'] ?? $this->defaultDriverClass($driver);

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

    private function defaultDriverClass(string $driver): ?string
    {
        return match ($driver) {
            'shell' => ShellCommandDriver::class,
            'postgres' => PostgresDriver::class,
            'pgbasebackup' => PgBaseBackupDriver::class,
            'pgdump' => PgDumpDriver::class,
            'mysql' => MysqlDriver::class,
            default => null,
        };
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

    private function validateMysqlConfig(): void
    {
        $config = $this->config->get('checkpoint.drivers.mysql', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.drivers.mysql must be an array.');
        }

        foreach (['dump_binary', 'mysql_binary', 'mysqlbinlog_binary', 'output_dir', 'output_prefix', 'file_extension'] as $key) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new ConfigurationException(sprintf('checkpoint.drivers.mysql.%s must be a non-empty string.', $key));
            }
        }

        foreach (['single_transaction', 'quick', 'skip_lock_tables'] as $key) {
            if (! is_bool($config[$key] ?? null)) {
                throw new ConfigurationException(sprintf('checkpoint.drivers.mysql.%s must be a boolean.', $key));
            }
        }

        if (! is_string($config['drill_command'] ?? null)) {
            throw new ConfigurationException('checkpoint.drivers.mysql.drill_command must be a string.');
        }

        $timeout = $config['command_timeout_seconds'] ?? null;

        if (! is_int($timeout) || $timeout < 1) {
            throw new ConfigurationException('checkpoint.drivers.mysql.command_timeout_seconds must be greater than zero.');
        }

        $pitr = $config['pitr'] ?? [];

        if (! is_array($pitr)) {
            throw new ConfigurationException('checkpoint.drivers.mysql.pitr must be an array.');
        }

        $binlogFiles = $pitr['binlog_files'] ?? [];

        if (! is_array($binlogFiles)) {
            throw new ConfigurationException('checkpoint.drivers.mysql.pitr.binlog_files must be an array.');
        }

        foreach ($binlogFiles as $binlogFile) {
            if (! is_string($binlogFile) || trim($binlogFile) === '') {
                throw new ConfigurationException('checkpoint.drivers.mysql.pitr.binlog_files values must be non-empty strings.');
            }
        }

        $extraArgs = $config['extra_args'] ?? [];

        if (! is_array($extraArgs)) {
            throw new ConfigurationException('checkpoint.drivers.mysql.extra_args must be an array.');
        }

        foreach (['backup', 'restore', 'pitr_binlog', 'pitr_replay', 'drill'] as $key) {
            if (! array_key_exists($key, $extraArgs) || ! is_array($extraArgs[$key])) {
                throw new ConfigurationException(
                    sprintf('checkpoint.drivers.mysql.extra_args.%s must be an array.', $key),
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
        $heartbeatIntervalSeconds = (int) $this->config->get('checkpoint.queue.heartbeat_interval_seconds', 0);
        $heartbeatGraceSeconds = (int) $this->config->get('checkpoint.queue.heartbeat_grace_seconds', 0);
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

        if ($heartbeatIntervalSeconds < 1) {
            throw new ConfigurationException('checkpoint.queue.heartbeat_interval_seconds must be greater than zero.');
        }

        if ($heartbeatGraceSeconds < 0) {
            throw new ConfigurationException('checkpoint.queue.heartbeat_grace_seconds must be greater than or equal to zero.');
        }

        if ($heartbeatIntervalSeconds >= $timeout) {
            throw new ConfigurationException(
                'checkpoint.queue.heartbeat_interval_seconds must be less than checkpoint.queue.timeout.',
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

        $store = $stores[$lockStore];
        $environment = (string) $this->config->get('app.env', 'production');
        $driver = is_array($store) ? (string) ($store['driver'] ?? '') : '';

        if (! in_array($environment, ['local', 'testing'], true) && in_array($driver, ['array', 'file'], true)) {
            throw new ConfigurationException(
                sprintf(
                    'checkpoint.queue.lock_store [%s] uses cache driver [%s], which is not safe for production queue uniqueness or clustered scheduler coordination.',
                    $lockStore,
                    $driver,
                ),
            );
        }
    }

    private function validateDriverTimeoutBudgets(): void
    {
        $queueTimeout = (int) $this->config->get('checkpoint.queue.timeout', 0);

        if ($queueTimeout < 1) {
            return;
        }

        $timeoutPaths = [
            'shell' => 'checkpoint.drivers.shell.command_timeout_seconds',
            'pgdump' => 'checkpoint.drivers.pgdump.command_timeout_seconds',
            'mysql' => 'checkpoint.drivers.mysql.command_timeout_seconds',
        ];

        foreach ($timeoutPaths as $path) {
            $driverTimeout = $this->config->get($path);
            if (! is_int($driverTimeout)) {
                continue;
            }
            if ($driverTimeout < 1) {
                continue;
            }

            if ($driverTimeout > $queueTimeout) {
                throw new ConfigurationException(sprintf(
                    '%s [%d] must be less than or equal to checkpoint.queue.timeout [%d] so queued jobs are not terminated before the driver command finishes.',
                    $path,
                    $driverTimeout,
                    $queueTimeout,
                ));
            }
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

        $blastRadius = $config['blast_radius'] ?? [];

        if (! is_array($blastRadius)) {
            throw new ConfigurationException('checkpoint.restore.blast_radius must be an array.');
        }

        if (! is_bool($blastRadius['enabled'] ?? null)) {
            throw new ConfigurationException('checkpoint.restore.blast_radius.enabled must be a boolean.');
        }

        foreach (['warn_score', 'block_score'] as $key) {
            $value = $blastRadius[$key] ?? null;

            if (! is_int($value) || $value < 0 || $value > 100) {
                throw new ConfigurationException(sprintf('checkpoint.restore.blast_radius.%s must be between 0 and 100.', $key));
            }
        }

        if (($blastRadius['warn_score'] ?? 0) > ($blastRadius['block_score'] ?? 100)) {
            throw new ConfigurationException('checkpoint.restore.blast_radius.warn_score must be less than or equal to checkpoint.restore.blast_radius.block_score.');
        }

        $weights = $blastRadius['weights'] ?? [];

        if (! is_array($weights)) {
            throw new ConfigurationException('checkpoint.restore.blast_radius.weights must be an array.');
        }

        foreach (['environment', 'database', 'target', 'verification'] as $key) {
            $value = $weights[$key] ?? null;

            if (! is_int($value) || $value < 0 || $value > 100) {
                throw new ConfigurationException(sprintf('checkpoint.restore.blast_radius.weights.%s must be between 0 and 100.', $key));
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
        $alertCooldownSeconds = $config['alert_cooldown_seconds'] ?? null;

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

        if (! is_int($alertCooldownSeconds) || $alertCooldownSeconds < 0) {
            throw new ConfigurationException('checkpoint.observability.alert_cooldown_seconds must be greater than or equal to zero.');
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

    private function validateGateSettings(): void
    {
        $config = $this->config->get('checkpoint.gates', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.gates must be an array.');
        }

        $defaultProfile = $config['default_profile'] ?? 'production';

        if (! is_string($defaultProfile)) {
            throw new ConfigurationException('checkpoint.gates.default_profile must be a non-empty string.');
        }

        $defaultProfile = trim($defaultProfile);

        if ($defaultProfile === '') {
            $defaultProfile = 'production';
        }

        $profiles = $config['profiles'] ?? null;

        if (! is_array($profiles) || $profiles === []) {
            throw new ConfigurationException('checkpoint.gates.profiles must be a non-empty array.');
        }

        if (! array_key_exists($defaultProfile, $profiles)) {
            throw new ConfigurationException(sprintf('checkpoint.gates.default_profile [%s] must exist in checkpoint.gates.profiles.', $defaultProfile));
        }

        $overrideProfile = $config['override_profile'] ?? null;

        if (! is_null($overrideProfile) && ! is_string($overrideProfile)) {
            throw new ConfigurationException('checkpoint.gates.override_profile must be null or a non-empty string.');
        }

        if (is_string($overrideProfile)) {
            $overrideProfile = trim($overrideProfile);

            if ($overrideProfile === '') {
                $overrideProfile = null;
            } elseif (! array_key_exists($overrideProfile, $profiles)) {
                throw new ConfigurationException(sprintf(
                    'checkpoint.gates.override_profile references unknown profile [%s].',
                    $overrideProfile,
                ));
            } else {
                $overrideProfile = $overrideProfile;
            }
        }

        $environmentProfileMap = $config['environment_profile_map'] ?? [];

        if (! is_array($environmentProfileMap)) {
            throw new ConfigurationException('checkpoint.gates.environment_profile_map must be an array.');
        }

        foreach ($environmentProfileMap as $environment => $profile) {
            if (! is_string($environment) || trim($environment) === '') {
                throw new ConfigurationException('checkpoint.gates.environment_profile_map keys must be non-empty strings.');
            }

            if (! is_string($profile)) {
                throw new ConfigurationException('checkpoint.gates.environment_profile_map values must be non-empty strings.');
            }

            $profile = trim($profile);

            if ($profile === '') {
                $profile = $defaultProfile;
            }

            if (! array_key_exists($profile, $profiles)) {
                throw new ConfigurationException(sprintf(
                    'checkpoint.gates.environment_profile_map[%s] references unknown profile [%s].',
                    $environment,
                    $profile,
                ));
            }
        }

        $codeMap = $config['code_map'] ?? [];

        if (! is_array($codeMap)) {
            throw new ConfigurationException('checkpoint.gates.code_map must be an array.');
        }

        foreach (['pass', 'warn', 'safety_fail', 'evidence_fail', 'policy_error'] as $code) {
            if (! array_key_exists($code, $codeMap) || ! is_int($codeMap[$code]) || $codeMap[$code] < 0) {
                throw new ConfigurationException(sprintf('checkpoint.gates.code_map.%s must be an integer greater than or equal to zero.', $code));
            }
        }

        foreach ($profiles as $name => $profile) {
            if (! is_array($profile)) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s must be an array.', $name));
            }

            if (! is_bool($profile['exit_on_warn'] ?? null)) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.exit_on_warn must be a boolean.', $name));
            }

            $safety = $profile['safety'] ?? null;

            if (! is_array($safety)) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.safety must be an array.', $name));
            }

            $failOnStatuses = $safety['fail_on_statuses'] ?? null;
            if (! is_array($failOnStatuses) || $failOnStatuses === []) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.safety.fail_on_statuses must be a non-empty array.', $name));
            }

            foreach ($failOnStatuses as $status) {
                if (! is_string($status) || trim($status) === '') {
                    throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.safety.fail_on_statuses must contain non-empty strings.', $name));
                }
            }

            $failOnWarningCodes = $safety['fail_on_warning_codes'] ?? [];
            if (! is_array($failOnWarningCodes)) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.safety.fail_on_warning_codes must be an array.', $name));
            }

            foreach ($failOnWarningCodes as $code) {
                if (! is_string($code) || trim($code) === '') {
                    throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.safety.fail_on_warning_codes must contain non-empty strings.', $name));
                }
            }

            $evidence = $profile['evidence'] ?? null;

            if (! is_array($evidence)) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.evidence must be an array.', $name));
            }

            if (! is_bool($evidence['enabled'] ?? null)) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.evidence.enabled must be a boolean.', $name));
            }

            $failOnCodes = $evidence['fail_on_codes'] ?? [];
            if (! is_array($failOnCodes)) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.evidence.fail_on_codes must be an array.', $name));
            }

            foreach ($failOnCodes as $code) {
                if (! is_string($code) || trim($code) === '') {
                    throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.evidence.fail_on_codes must contain non-empty strings.', $name));
                }
            }

            $maxAge = $evidence['max_restore_verification_age_days'] ?? null;
            if (! is_int($maxAge) || $maxAge < 0) {
                throw new ConfigurationException(sprintf('checkpoint.gates.profiles.%s.evidence.max_restore_verification_age_days must be an integer greater than or equal to zero.', $name));
            }
        }
    }

    private function validateNotificationSettings(): void
    {
        $config = $this->config->get('checkpoint.notifications', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.notifications must be an array.');
        }

        if (! is_bool($config['enabled'] ?? null)) {
            throw new ConfigurationException('checkpoint.notifications.enabled must be a boolean.');
        }

        $events = $config['events'] ?? [];

        if (! is_array($events)) {
            throw new ConfigurationException('checkpoint.notifications.events must be an array.');
        }

        foreach ($events as $event) {
            if (! is_string($event) || trim($event) === '') {
                throw new ConfigurationException('checkpoint.notifications.events must contain non-empty strings.');
            }

            if (! NotificationEventMap::supportsKey($event)) {
                throw new ConfigurationException(sprintf('checkpoint.notifications.events contains unsupported event key [%s].', $event));
            }
        }

        $routing = $config['routing'] ?? [];

        if (! is_array($routing)) {
            throw new ConfigurationException('checkpoint.notifications.routing must be an array.');
        }

        foreach (['info', 'warning', 'critical'] as $level) {
            $channels = $routing[$level] ?? null;

            if (! is_array($channels)) {
                throw new ConfigurationException(sprintf('checkpoint.notifications.routing.%s must be an array.', $level));
            }

            foreach ($channels as $channel) {
                if (! is_string($channel) || ! in_array($channel, ['log', 'mail', 'webhook'], true)) {
                    throw new ConfigurationException(
                        sprintf('checkpoint.notifications.routing.%s must only contain: log, mail, webhook.', $level),
                    );
                }
            }
        }

        $mail = $config['mail'] ?? [];

        if (! is_array($mail)) {
            throw new ConfigurationException('checkpoint.notifications.mail must be an array.');
        }

        $mailTo = $mail['to'] ?? [];

        if (! is_array($mailTo)) {
            throw new ConfigurationException('checkpoint.notifications.mail.to must be an array.');
        }

        foreach ($mailTo as $address) {
            if (! is_string($address) || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new ConfigurationException('checkpoint.notifications.mail.to must contain valid email addresses.');
            }
        }

        $webhook = $config['webhook'] ?? [];

        if (! is_array($webhook)) {
            throw new ConfigurationException('checkpoint.notifications.webhook must be an array.');
        }

        $webhookUrl = $webhook['url'] ?? null;

        if ($webhookUrl !== null && (! is_string($webhookUrl) || trim($webhookUrl) === '' || ! filter_var($webhookUrl, FILTER_VALIDATE_URL))) {
            throw new ConfigurationException('checkpoint.notifications.webhook.url must be null or a valid URL.');
        }

        $timeoutSeconds = $webhook['timeout_seconds'] ?? null;

        if (! is_int($timeoutSeconds) || $timeoutSeconds < 1 || $timeoutSeconds > 60) {
            throw new ConfigurationException('checkpoint.notifications.webhook.timeout_seconds must be between 1 and 60.');
        }

        $provider = $webhook['provider'] ?? 'generic';

        if (! is_string($provider) || ! in_array($provider, ['generic', 'slack', 'telegram'], true)) {
            throw new ConfigurationException('checkpoint.notifications.webhook.provider must be generic, slack, or telegram.');
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

        $storage = $config['storage'] ?? null;

        if (! is_string($storage) || ! in_array($storage, ['database', 'filesystem'], true)) {
            throw new ConfigurationException('checkpoint.output.storage must be either database or filesystem.');
        }

        $filesystem = $config['filesystem'] ?? [];

        if (! is_array($filesystem)) {
            throw new ConfigurationException('checkpoint.output.filesystem must be an array.');
        }

        $inlineBytes = $filesystem['inline_bytes'] ?? null;

        if (! is_int($inlineBytes) || $inlineBytes < 0) {
            throw new ConfigurationException('checkpoint.output.filesystem.inline_bytes must be zero or greater.');
        }

        if ($inlineBytes > $maxPersistedBytes) {
            throw new ConfigurationException('checkpoint.output.filesystem.inline_bytes must not exceed checkpoint.output.max_persisted_bytes.');
        }

        if ($storage === 'filesystem') {
            $disk = $filesystem['disk'] ?? null;
            $pathPrefix = $filesystem['path_prefix'] ?? null;

            if (! is_string($disk) || trim($disk) === '') {
                throw new ConfigurationException('checkpoint.output.filesystem.disk must be a non-empty string.');
            }

            if (! array_key_exists($disk, (array) config('filesystems.disks', []))) {
                throw new ConfigurationException(sprintf('checkpoint.output.filesystem.disk [%s] is not configured.', $disk));
            }

            if (! is_string($pathPrefix) || trim($pathPrefix) === '') {
                throw new ConfigurationException('checkpoint.output.filesystem.path_prefix must be a non-empty string.');
            }
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
            'backup_drill_enabled',
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

        $backupDrillDailyAt = $config['backup_drill_daily_at'] ?? null;

        if (! is_string($backupDrillDailyAt) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $backupDrillDailyAt) !== 1) {
            throw new ConfigurationException('checkpoint.schedule.backup_drill_daily_at must use HH:MM 24-hour format.');
        }

        $timezone = $config['logical_backup_timezone'] ?? null;

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new ConfigurationException('checkpoint.schedule.logical_backup_timezone must be a valid timezone identifier.');
        }

        $backupDrillTimezone = $config['backup_drill_timezone'] ?? null;

        if (! is_string($backupDrillTimezone) || ! in_array($backupDrillTimezone, timezone_identifiers_list(), true)) {
            throw new ConfigurationException('checkpoint.schedule.backup_drill_timezone must be a valid timezone identifier.');
        }

        foreach (['overlap_expires_at', 'prune_keep_days', 'prune_keep_failed_days', 'prune_keep_backup_drill_days'] as $key) {
            if (! is_int($config[$key] ?? null) || $config[$key] < 1) {
                throw new ConfigurationException(sprintf('checkpoint.schedule.%s must be greater than zero.', $key));
            }
        }

        $backupDrillRetentionDays = $config['prune_keep_backup_drill_days'];
        $observability = $this->config->get('checkpoint.observability', []);

        if (! is_array($observability)) {
            throw new ConfigurationException('checkpoint.observability must be an array.');
        }

        $maxBackupDrillAgeDays = $observability['max_backup_drill_age_days'] ?? null;
        $backupDrillPassRateWindowDays = $observability['backup_drill_pass_rate_window_days'] ?? null;

        if (is_int($maxBackupDrillAgeDays) && $backupDrillRetentionDays < $maxBackupDrillAgeDays) {
            throw new ConfigurationException('checkpoint.schedule.prune_keep_backup_drill_days must be greater than or equal to checkpoint.observability.max_backup_drill_age_days.');
        }

        if (is_int($backupDrillPassRateWindowDays) && $backupDrillRetentionDays < $backupDrillPassRateWindowDays) {
            throw new ConfigurationException('checkpoint.schedule.prune_keep_backup_drill_days must be greater than or equal to checkpoint.observability.backup_drill_pass_rate_window_days.');
        }

        $environment = (string) $this->config->get('app.env', 'production');
        $usesClusterSchedulingGuards = (bool) ($config['without_overlapping'] ?? false)
            || (bool) ($config['on_one_server'] ?? false);

        if (! in_array($environment, ['local', 'testing'], true) && $usesClusterSchedulingGuards) {
            $defaultCacheStore = (string) $this->config->get('cache.default', '');
            $stores = $this->config->get('cache.stores', []);

            if ($defaultCacheStore === '' || ! is_array($stores) || ! is_array($stores[$defaultCacheStore] ?? null)) {
                throw new ConfigurationException(
                    'checkpoint.schedule requires cache.default to reference a configured shared cache store when checkpoint.schedule.without_overlapping or checkpoint.schedule.on_one_server is enabled in non-local environments.',
                );
            }

            $driver = (string) ($stores[$defaultCacheStore]['driver'] ?? '');

            if (in_array($driver, ['array', 'file'], true)) {
                throw new ConfigurationException(
                    sprintf(
                        'checkpoint.schedule cache store [%s] uses cache driver [%s], which is not safe for checkpoint.schedule.without_overlapping or checkpoint.schedule.on_one_server in non-local environments.',
                        $defaultCacheStore,
                        $driver,
                    ),
                );
            }
        }
    }

    private function validateRetentionSettings(): void
    {
        $config = $this->config->get('checkpoint.retention', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.retention must be an array.');
        }

        if (! is_bool($config['enabled'] ?? null)) {
            throw new ConfigurationException('checkpoint.retention.enabled must be a boolean.');
        }

        foreach (['default_days', 'failed_days'] as $key) {
            if (! is_int($config[$key] ?? null) || $config[$key] < 1) {
                throw new ConfigurationException(sprintf('checkpoint.retention.%s must be greater than zero.', $key));
            }
        }

        $tiers = $config['tiers'] ?? [];

        if (! is_array($tiers)) {
            throw new ConfigurationException('checkpoint.retention.tiers must be an array.');
        }

        foreach ($tiers as $tier => $days) {
            if (! is_string($tier) || trim($tier) === '') {
                throw new ConfigurationException('checkpoint.retention.tiers keys must be non-empty strings.');
            }

            $normalizedTier = strtolower(trim($tier));

            if (preg_match('/^[a-z][a-z0-9_-]*$/', $normalizedTier) !== 1) {
                throw new ConfigurationException(sprintf('checkpoint.retention.tiers.%s key must use lowercase alphanumeric, underscore, or hyphen characters.', $tier));
            }

            if (! is_int($days) || $days < 1) {
                throw new ConfigurationException(sprintf('checkpoint.retention.tiers.%s must be greater than zero.', $normalizedTier));
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

            if (! is_string($operation['label'] ?? null) || trim($operation['label']) === '') {
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

    private function validateReplicationSettings(): void
    {
        $config = $this->config->get('checkpoint.replication', []);

        if (! is_array($config)) {
            throw new ConfigurationException('checkpoint.replication must be an array.');
        }

        foreach ([
            'require_confirmation_token',
            'block_in_ci',
            'require_dry_run_before_apply',
            'enforce_change_window',
        ] as $flag) {
            if (! is_bool($config[$flag] ?? null)) {
                throw new ConfigurationException(sprintf('checkpoint.replication.%s must be a boolean.', $flag));
            }
        }

        $changeWindowTimezone = $config['change_window_timezone'] ?? null;

        if (! is_string($changeWindowTimezone) || trim($changeWindowTimezone) === '' || ! in_array($changeWindowTimezone, timezone_identifiers_list(), true)) {
            throw new ConfigurationException('checkpoint.replication.change_window_timezone must be a valid timezone identifier.');
        }

        $changeWindowDays = $config['change_window_days'] ?? [];

        if (! is_array($changeWindowDays)) {
            throw new ConfigurationException('checkpoint.replication.change_window_days must be an array.');
        }

        foreach ($changeWindowDays as $day) {
            if (! is_string($day) || ! preg_match('/^(mon|tue|wed|thu|fri|sat|sun)$/i', trim($day))) {
                throw new ConfigurationException('checkpoint.replication.change_window_days must only contain mon,tue,wed,thu,fri,sat,sun.');
            }
        }

        foreach (['change_window_start', 'change_window_end'] as $windowKey) {
            $value = $config[$windowKey] ?? null;

            if (! is_string($value) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', trim($value))) {
                throw new ConfigurationException(sprintf('checkpoint.replication.%s must use HH:MM 24-hour format.', $windowKey));
            }
        }

        $allowlistedDestinations = $config['allowlisted_destinations'] ?? [];

        if (! is_array($allowlistedDestinations)) {
            throw new ConfigurationException('checkpoint.replication.allowlisted_destinations must be an array.');
        }

        foreach ($allowlistedDestinations as $destination) {
            if (! is_string($destination) || trim($destination) === '') {
                throw new ConfigurationException('checkpoint.replication.allowlisted_destinations must contain non-empty strings.');
            }
        }

        $criticalTables = $config['critical_tables'] ?? [];

        if (! is_array($criticalTables)) {
            throw new ConfigurationException('checkpoint.replication.critical_tables must be an array.');
        }

        foreach ($criticalTables as $table) {
            if (! is_string($table) || trim($table) === '') {
                throw new ConfigurationException('checkpoint.replication.critical_tables must contain non-empty strings.');
            }
        }

        $profiles = $config['profiles'] ?? [];

        if (! is_array($profiles)) {
            throw new ConfigurationException('checkpoint.replication.profiles must be an array.');
        }

        foreach ($profiles as $name => $profile) {
            $prefix = sprintf('checkpoint.replication.profiles.%s', (string) $name);

            if (! is_string($name) || trim($name) === '') {
                throw new ConfigurationException('checkpoint.replication.profiles keys must be non-empty strings.');
            }

            if (! is_array($profile)) {
                throw new ConfigurationException(sprintf('%s must be an array.', $prefix));
            }

            $engine = $profile['engine'] ?? null;

            if (! is_string($engine) || ! in_array(strtolower(trim($engine)), ['pgsql', 'mysql'], true)) {
                throw new ConfigurationException(sprintf('%s.engine must be pgsql or mysql.', $prefix));
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

    private function validateTempDirectory(): void
    {
        $tempDir = $this->config->get('checkpoint.temp_dir');

        if (! is_string($tempDir) || trim($tempDir) === '') {
            throw new ConfigurationException('checkpoint.temp_dir must be a non-empty string.');
        }
    }
}
