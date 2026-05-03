# Laravel Checkpoint

Queue-based database backup and restore orchestration for Laravel. Provides
scheduled logical backups, point-in-time recovery, replication, automated
recovery drills, and multi-tier safety gates — all processed through a
dedicated queue for reliable execution of long-running operations.

## Installation

```bash
composer require adityaa-codes/laravel-checkpoint
php artisan checkpoint:install
```

## Quick Start

```bash
# Run health checks
php artisan checkpoint:doctor

# Queue a backup
php artisan checkpoint:enqueue-backup

# View status
php artisan checkpoint:status

# Start the queue worker
php artisan queue:work --queue=db-ops
```

## Documentation

Full documentation is available at [laravel-checkpoint.com](https://laravel-checkpoint.com).
