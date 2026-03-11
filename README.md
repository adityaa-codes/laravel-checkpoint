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
- `queue.connection`, `queue.name`, `queue.max_attempts`, `queue.timeout`, `queue.orphan_threshold`
- `schedule.logical_backup_*`, `schedule.health_check_enabled`, `schedule.recover_orphans_enabled`, `schedule.prune_enabled`
- `driver`, `drivers.shell.*`
- `log_channel`
- `custom_operations`

Common environment variables:

```env
DB_OPS_QUEUE_NAME=db-ops
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_ORPHAN_THRESHOLD=10
DB_OPS_LOG_CHANNEL=stack

DB_OPS_CMD_LOGICAL_BACKUP=
DB_OPS_CMD_RESTORE_LATEST=
DB_OPS_CMD_RESTORE_FILE=
DB_OPS_CMD_PITR_RESTORE=
DB_OPS_CMD_BACKUP_DRILL=
DB_OPS_CMD_PGBACKREST_CHECK=
DB_OPS_CMD_PGBACKREST_INFO=
```

Command templates are configured as space-delimited argv-style strings and are parsed into Symfony Process argument arrays. User input is validated before it reaches the driver.

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

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See the contributing guide in `CONTRIBUTING.md`.

## Security

See `SECURITY.md` for reporting instructions.

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
