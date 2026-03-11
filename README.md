# Laravel Checkpoint

[![Latest Version on Packagist](https://img.shields.io/packagist/v/adityaa-codes/laravel-checkpoint.svg?style=flat-square)](https://packagist.org/packages/adityaa-codes/laravel-checkpoint)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/adityaa-codes/laravel-checkpoint/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/adityaa-codes/laravel-checkpoint/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/adityaa-codes/laravel-checkpoint/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/adityaa-codes/laravel-checkpoint/actions?query=workflow%3A%22Fix+PHP+code+style+issues%22+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/adityaa-codes/laravel-checkpoint.svg?style=flat-square)](https://packagist.org/packages/adityaa-codes/laravel-checkpoint)

Core Laravel package for database checkpoint, backup, restore, drill recording, and operational safety workflows.

## Installation

Install the package:

```bash
composer require adityaa-codes/laravel-checkpoint
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag="laravel-checkpoint-config"
php artisan vendor:publish --tag="laravel-checkpoint-migrations"
php artisan migrate
```

## Configuration

Important config groups in `config/checkpoint.php`:

- `user_model`, `user_name_column`, `table_prefix`
- `queue.connection`, `queue.name`, `queue.max_attempts`, `queue.retry_after`, `queue.timeout`, `queue.unique_for`, `queue.lock_store`, `queue.orphan_threshold`
- `schedule.logical_backup_*`, `schedule.health_check_enabled`, `schedule.recover_orphans_enabled`, `schedule.prune_enabled`, `schedule.without_overlapping`, `schedule.overlap_expires_at`, `schedule.on_one_server`
- `driver`, `drivers.shell.*`, `drivers.pgbackrest.*`, `drivers.pgdump.*`
- `log_channel`
- `custom_operations`

Common environment variables:

```env
DB_OPS_QUEUE_NAME=db-ops
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_QUEUE_LOCK_STORE=redis
DB_OPS_QUEUE_ORPHAN_THRESHOLD=10
DB_OPS_LOG_CHANNEL=stack

DB_OPS_CMD_LOGICAL_BACKUP=
DB_OPS_CMD_RESTORE_LATEST=
DB_OPS_CMD_RESTORE_FILE=
DB_OPS_CMD_PITR_RESTORE=
DB_OPS_CMD_BACKUP_DRILL=
DB_OPS_CMD_PGBACKREST_CHECK=
DB_OPS_CMD_PGBACKREST_INFO=

DB_OPS_PGBACKREST_BINARY=pgbackrest
DB_OPS_PGBACKREST_STANZA=main
DB_OPS_PGDUMP_BINARY=pg_dump
DB_OPS_PGRESTORE_BINARY=pg_restore
DB_OPS_PGDUMP_FORMAT=directory
DB_OPS_PGDUMP_JOBS=4
DB_OPS_PGDUMP_COMPRESS_LEVEL=6
DB_OPS_PGDUMP_OUTPUT_DIR=/var/app/checkpoint/logical-exports
```

Command templates are configured as space-delimited argv-style strings and are parsed into Symfony Process argument arrays. User input is validated before it reaches the driver.

### Production Queue And Locking Guidance

For production, the package assumes long-running jobs and shared infrastructure.

- `DB_OPS_QUEUE_RETRY_AFTER` must be greater than `DB_OPS_QUEUE_TIMEOUT`
- `DB_OPS_QUEUE_UNIQUE_FOR` must be greater than or equal to `DB_OPS_QUEUE_RETRY_AFTER`
- `DB_OPS_QUEUE_LOCK_STORE` should point at a shared lock backend, typically `redis`
- scheduled commands are configured to use `withoutOverlapping()` and `onOneServer()` by default

Recommended production values:

```env
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_QUEUE_LOCK_STORE=redis
DB_OPS_SCHEDULE_WITHOUT_OVERLAPPING=true
DB_OPS_SCHEDULE_OVERLAP_EXPIRES_AT=180
DB_OPS_SCHEDULE_ON_ONE_SERVER=true
```

Worker alignment matters:

- the Laravel queue worker `--timeout` should be a few seconds shorter than `DB_OPS_QUEUE_RETRY_AFTER`
- this package validates the config contract, but your worker process must still be started with a compatible timeout

Example worker command:

```bash
php artisan queue:work --queue=db-ops --timeout=3600
```

Recommended production cache config:

- use Redis for queue uniqueness and scheduler overlap locks
- avoid local-only cache drivers for multi-node deployments, because they cannot coordinate uniqueness or `onOneServer()` safely across hosts

## Usage

Queue a run through the facade:

```php
use AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint;

$run = LaravelCheckpoint::execute('logical_backup');
```

Queue a run through the action directly:

```php
use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;

$run = app(EnqueueCommandRunAction::class)->execute('logical_restore_file', 'nightly.sql');
```

Artisan commands:

```bash
php artisan db-ops:enqueue-backup
php artisan db-ops:enqueue logical_backup
php artisan db-ops:status --limit=10
php artisan db-ops:record-drill --run-uuid=... --overall-result=pass --executed-at=2026-03-11T10:30:00+00:00
php artisan db-ops:health-check
php artisan db-ops:recover-orphans
php artisan db-ops:prune
php artisan db-ops:doctor
```

## Driver Customization

The shipped shell driver is PostgreSQL-oriented, but the contract is database-engine agnostic. You can override command templates for another engine.

Example MySQL dump command:

```env
DB_OPS_CMD_LOGICAL_BACKUP="mysqldump --single-transaction {db}"
```

If you need a custom implementation, bind `AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver` to your own driver class and point `checkpoint.driver` / `checkpoint.drivers` at it.

### PostgreSQL Driver Strategy

For PostgreSQL production environments, use the drivers with different roles:

- `pgbackrest`: primary disaster-recovery and PITR workflow
- `pgdump`: optional logical export workflow for large databases, schema export, selective restore preparation, and migration/archive use cases
- `shell`: escape hatch for legacy or custom commands

Logical exports are not the primary DR strategy for huge PostgreSQL systems. The recommended production path is:

1. use `pgbackrest` for regular backup, restore, and verification
2. use `pgdump` only when you specifically need a logical export artifact

### pgDump Large-Export Configuration

The bundled `pgdump` driver defaults to PostgreSQL directory format so large exports use parallel dump jobs:

```env
DB_OPS_DRIVER=pgdump
DB_OPS_PGDUMP_FORMAT=directory
DB_OPS_PGDUMP_JOBS=8
DB_OPS_PGDUMP_COMPRESS_LEVEL=3
DB_OPS_PGDUMP_OUTPUT_DIR=/var/app/checkpoint/logical-exports
DB_OPS_PGDUMP_OUTPUT_PREFIX=huge-export
```

Behavior notes:

- directory format exports use `pg_dump --format=directory --jobs=<n>`
- parallel dump jobs are only valid for directory format
- restore commands use `pg_restore`
- `logical_restore_latest` resolves the newest export in the configured output directory
- `logical_restore_file` resolves relative export names inside the configured output directory

## Extending The Catalog

You can extend available operations at runtime or through config.

Runtime extension:

```php
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;

$catalog = app(CommandRunCatalog::class);

$catalog->extend('tenant_backup', [
    'label' => 'Tenant Backup',
    'argument_required' => true,
    'argument_hint' => 'tenant id',
    'argument_validator' => static fn (?string $value): bool => $value !== null && ctype_digit($value),
    'destructive' => false,
    'exclusive' => true,
]);
```

Config extension:

```php
'custom_operations' => [
    'tenant_backup' => [
        'label' => 'Tenant Backup',
        'argument_required' => true,
        'argument_hint' => 'tenant id',
        'argument_validator' => static fn (?string $value): bool => $value !== null && ctype_digit($value),
        'destructive' => false,
        'exclusive' => true,
    ],
],
```

## Public API

Supported extension points:

- `AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver`
- `AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction`
- `AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog`
- `AdityaaCodes\LaravelCheckpoint\Models\CommandRun`
- `AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun`
- `AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus`
- `AdityaaCodes\LaravelCheckpoint\Testing\InteractsWithCheckpoint`
- All event classes in `AdityaaCodes\LaravelCheckpoint\Events`

Internal implementation details such as drivers, queue jobs, and config validation are marked `@internal` and should not be depended on directly.

## Testing

Run the focused package checks:

```bash
composer test
composer analyse
composer format
```

In this repository, PHP and Composer commands are run through DDEV:

```bash
ddev exec vendor/bin/pest
ddev exec vendor/bin/phpstan analyse
```

For coverage in DDEV:

```bash
ddev exec env XDEBUG_MODE=coverage vendor/bin/pest --coverage
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See the contributing guide in `CONTRIBUTING.md`.

## Security

See `SECURITY.md` for reporting instructions.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
