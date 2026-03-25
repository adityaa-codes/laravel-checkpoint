<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver;
use AdityaaCodes\LaravelCheckpoint\Drivers\FakeDriver;
use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use Illuminate\Foundation\Auth\User;

it('defaults restore verification requirement outside local and testing environments', function (): void {
    putenv('APP_ENV=production');
    putenv('DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP');

    $config = require __DIR__.'/../../config/checkpoint.php';

    expect($config['restore']['require_verified_backup'])->toBeTrue();

    putenv('APP_ENV');
});

it('defaults restore verification requirement off in testing environment', function (): void {
    putenv('APP_ENV=testing');
    putenv('DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP');

    $config = require __DIR__.'/../../config/checkpoint.php';

    expect($config['restore']['require_verified_backup'])->toBeFalse();

    putenv('APP_ENV');
});

it('disables ci restore bypass by default', function (): void {
    putenv('DB_OPS_RESTORE_ALLOW_IN_CI');

    $config = require __DIR__.'/../../config/checkpoint.php';

    expect($config['restore']['allow_in_ci'])->toBeFalse();
});

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

it('rejects an invalid backup schedule time', function (): void {
    config()->set('checkpoint.schedule.logical_backup_daily_at', '25:00');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.schedule.logical_backup_daily_at must use HH:MM 24-hour format.');
});

it('rejects an invalid backup schedule timezone', function (): void {
    config()->set('checkpoint.schedule.logical_backup_timezone', 'Mars/Olympus');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.schedule.logical_backup_timezone must be a valid timezone identifier.');
});

it('rejects custom operations with invalid safety flags', function (): void {
    config()->set('checkpoint.custom_operations.audit_snapshot', [
        'label' => 'Audit Snapshot',
        'argument_required' => false,
        'argument_hint' => null,
        'argument_validator' => null,
        'destructive' => 'sometimes',
        'exclusive' => true,
    ]);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.custom_operations.audit_snapshot.destructive must be a boolean.');
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

it('rejects observability anomaly factors that are not greater than one', function (): void {
    config()->set('checkpoint.observability.backup_duration_anomaly_factor', 1.0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.observability.backup_duration_anomaly_factor must be greater than 1.');
});

it('rejects a negative alert cooldown window', function (): void {
    config()->set('checkpoint.observability.alert_cooldown_seconds', -1);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.observability.alert_cooldown_seconds must be greater than or equal to zero.');
});

it('rejects a non-positive backup drill freshness threshold', function (): void {
    config()->set('checkpoint.observability.max_backup_drill_age_days', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.observability.max_backup_drill_age_days must be greater than zero.');
});

it('rejects a non-positive backup drill pass rate window', function (): void {
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.observability.backup_drill_pass_rate_window_days must be greater than zero.');
});

it('rejects a backup drill minimum pass rate outside 0 to 100', function (): void {
    config()->set('checkpoint.observability.backup_drill_min_pass_rate', 101.0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.observability.backup_drill_min_pass_rate must be between 0 and 100.');
});

it('rejects a non-positive reporting recent run cap', function (): void {
    config()->set('checkpoint.reporting.max_recent_runs', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.reporting.max_recent_runs must be greater than zero.');
});

it('rejects a non-positive backup drill prune retention', function (): void {
    config()->set('checkpoint.schedule.prune_keep_backup_drill_days', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.schedule.prune_keep_backup_drill_days must be greater than zero.');
});

it('rejects backup drill retention shorter than the drill freshness window', function (): void {
    config()->set('checkpoint.schedule.prune_keep_backup_drill_days', 6);
    config()->set('checkpoint.observability.max_backup_drill_age_days', 7);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.schedule.prune_keep_backup_drill_days must be greater than or equal to checkpoint.observability.max_backup_drill_age_days.');
});

it('rejects backup drill retention shorter than the drill pass rate window', function (): void {
    config()->set('checkpoint.schedule.prune_keep_backup_drill_days', 13);
    config()->set('checkpoint.observability.max_backup_drill_age_days', 7);
    config()->set('checkpoint.observability.backup_drill_pass_rate_window_days', 14);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.schedule.prune_keep_backup_drill_days must be greater than or equal to checkpoint.observability.backup_drill_pass_rate_window_days.');
});

it('rejects a reporting recent run cap that is too large', function (): void {
    config()->set('checkpoint.reporting.max_recent_runs', 1001);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.reporting.max_recent_runs must not exceed 1000.');
});

it('rejects a non-positive output capture limit', function (): void {
    config()->set('checkpoint.output.max_persisted_bytes', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.output.max_persisted_bytes must be greater than zero.');
});

it('rejects an output capture limit that is too large', function (): void {
    config()->set('checkpoint.output.max_persisted_bytes', 1048577);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.output.max_persisted_bytes must not exceed 1048576.');
});

it('rejects an unsupported output storage backend', function (): void {
    config()->set('checkpoint.output.storage', 's3');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.output.storage must be either database or filesystem.');
});

it('rejects filesystem inline bytes larger than the captured output limit', function (): void {
    config()->set('checkpoint.output.storage', 'filesystem');
    config()->set('checkpoint.output.max_persisted_bytes', 64);
    config()->set('checkpoint.output.filesystem.inline_bytes', 65);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.output.filesystem.inline_bytes must not exceed checkpoint.output.max_persisted_bytes.');
});

it('rejects a missing filesystem output disk', function (): void {
    config()->set('checkpoint.output.storage', 'filesystem');
    config()->set('checkpoint.output.filesystem.disk', 'missing-disk');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.output.filesystem.disk [missing-disk] is not configured.');
});

it('rejects an empty checkpoint temp directory path', function (): void {
    config()->set('checkpoint.temp_dir', '');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.temp_dir must be a non-empty string.');
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

it('rejects mysql pitr binlog file lists that are not arrays', function (): void {
    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', 'binlog.000001');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.mysql.pitr.binlog_files must be an array.');
});

it('rejects mysql pitr binlog file lists with empty entries', function (): void {
    config()->set('checkpoint.drivers.mysql.pitr.binlog_files', ['binlog.000001', '']);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.mysql.pitr.binlog_files values must be non-empty strings.');
});

it('rejects non-array mysql extra args', function (): void {
    config()->set('checkpoint.drivers.mysql.extra_args.drill', '--flag');

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.drivers.mysql.extra_args.drill must be an array.');
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

it('rejects a non-positive orphan queue threshold', function (): void {
    config()->set('checkpoint.queue.orphan_threshold', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.orphan_threshold must be greater than zero.');
});

it('rejects a non-positive orphan claim timeout', function (): void {
    config()->set('checkpoint.queue.orphan_claim_timeout', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.orphan_claim_timeout must be greater than zero.');
});

it('rejects an orphan claim timeout shorter than the queue retry window', function (): void {
    config()->set('checkpoint.queue.retry_after', 3660);
    config()->set('checkpoint.queue.orphan_claim_timeout', 60);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(
            ConfigurationException::class,
            'checkpoint.queue.orphan_claim_timeout must be greater than or equal to 61 minutes to align with checkpoint.queue.retry_after.',
        );
});

it('rejects a non-positive orphan recovery batch size', function (): void {
    config()->set('checkpoint.queue.orphan_batch_size', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.orphan_batch_size must be greater than zero.');
});

it('rejects a non-positive orphan lag event id cap', function (): void {
    config()->set('checkpoint.queue.orphan_event_max_ids', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.orphan_event_max_ids must be greater than zero.');
});

it('rejects a non-positive queue heartbeat interval', function (): void {
    config()->set('checkpoint.queue.heartbeat_interval_seconds', 0);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.heartbeat_interval_seconds must be greater than zero.');
});

it('rejects a queue heartbeat interval that is not lower than queue timeout', function (): void {
    config()->set('checkpoint.queue.timeout', 300);
    config()->set('checkpoint.queue.heartbeat_interval_seconds', 300);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.heartbeat_interval_seconds must be less than checkpoint.queue.timeout.');
});

it('rejects a negative queue heartbeat grace window', function (): void {
    config()->set('checkpoint.queue.heartbeat_grace_seconds', -1);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.heartbeat_grace_seconds must be greater than or equal to zero.');
});

it('rejects an orphan lag event id cap that is too large', function (): void {
    config()->set('checkpoint.queue.orphan_event_max_ids', 1001);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(ConfigurationException::class, 'checkpoint.queue.orphan_event_max_ids must not exceed 1000.');
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

it('rejects local-only queue lock stores outside local and testing environments', function (): void {
    $originalEnvironment = (string) config('app.env', 'testing');
    config()->set('app.env', 'production');
    config()->set('checkpoint.queue.lock_store', 'array');

    try {
        expect(fn () => resolve(ConfigValidator::class)->validate())
            ->toThrow(ConfigurationException::class, 'checkpoint.queue.lock_store [array] uses cache driver [array], which is not safe for production queue uniqueness or clustered scheduler coordination.');
    } finally {
        config()->set('app.env', $originalEnvironment);
    }
});

it('rejects local-only scheduler cache stores outside local and testing environments', function (): void {
    $originalEnvironment = (string) config('app.env', 'testing');
    $originalDefaultStore = (string) config('cache.default', 'array');

    config()->set('app.env', 'production');
    config()->set('checkpoint.schedule.without_overlapping', true);
    config()->set('checkpoint.schedule.on_one_server', true);
    config()->set('checkpoint.queue.lock_store', null);
    config()->set('cache.default', 'array');

    try {
        expect(fn () => resolve(ConfigValidator::class)->validate())
            ->toThrow(ConfigurationException::class, 'checkpoint.schedule cache store [array] uses cache driver [array], which is not safe for checkpoint.schedule.without_overlapping or checkpoint.schedule.on_one_server in non-local environments.');
    } finally {
        config()->set('app.env', $originalEnvironment);
        config()->set('cache.default', $originalDefaultStore);
    }
});

it('rejects missing scheduler cache default store outside local and testing environments', function (): void {
    $originalEnvironment = (string) config('app.env', 'testing');
    $originalDefaultStore = (string) config('cache.default', 'array');

    config()->set('app.env', 'production');
    config()->set('checkpoint.schedule.without_overlapping', true);
    config()->set('checkpoint.schedule.on_one_server', true);
    config()->set('checkpoint.queue.lock_store', null);
    config()->set('cache.default', '');

    try {
        expect(fn () => resolve(ConfigValidator::class)->validate())
            ->toThrow(ConfigurationException::class, 'checkpoint.schedule requires cache.default to reference a configured shared cache store when checkpoint.schedule.without_overlapping or checkpoint.schedule.on_one_server is enabled in non-local environments.');
    } finally {
        config()->set('app.env', $originalEnvironment);
        config()->set('cache.default', $originalDefaultStore);
    }
});

it('rejects shell command timeouts that exceed the queue timeout budget', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.drivers.shell.command_timeout_seconds', 3601);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(
            ConfigurationException::class,
            'checkpoint.drivers.shell.command_timeout_seconds [3601] must be less than or equal to checkpoint.queue.timeout [3600] so queued jobs are not terminated before the driver command finishes.',
        );
});

it('rejects pgbackrest command timeouts that exceed the queue timeout budget', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.drivers.pgbackrest.command_timeout_seconds', 3601);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(
            ConfigurationException::class,
            'checkpoint.drivers.pgbackrest.command_timeout_seconds [3601] must be less than or equal to checkpoint.queue.timeout [3600] so queued jobs are not terminated before the driver command finishes.',
        );
});

it('rejects pgdump command timeouts that exceed the queue timeout budget', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.drivers.pgdump.command_timeout_seconds', 3601);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(
            ConfigurationException::class,
            'checkpoint.drivers.pgdump.command_timeout_seconds [3601] must be less than or equal to checkpoint.queue.timeout [3600] so queued jobs are not terminated before the driver command finishes.',
        );
});

it('rejects mysql command timeouts that exceed the queue timeout budget', function (): void {
    config()->set('checkpoint.queue.timeout', 3600);
    config()->set('checkpoint.drivers.mysql.command_timeout_seconds', 3601);

    expect(fn () => resolve(ConfigValidator::class)->validate())
        ->toThrow(
            ConfigurationException::class,
            'checkpoint.drivers.mysql.command_timeout_seconds [3601] must be less than or equal to checkpoint.queue.timeout [3600] so queued jobs are not terminated before the driver command finishes.',
        );
});
