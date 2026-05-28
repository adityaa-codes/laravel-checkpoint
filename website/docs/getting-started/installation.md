---
sidebar_position: 1
---

# Installation

Install the package:

```bash
composer require adityaa-codes/laravel-checkpoint
```

Run guided install:

```bash
php artisan checkpoint:install
```

The wizard auto-detects your database driver from `DB_CONNECTION` and walks you through setup. It publishes config, runs migrations, and checks health.

If you need manual control:

```bash
php artisan vendor:publish --tag="checkpoint-config"
php artisan vendor:publish --tag="checkpoint-migrations"
php artisan migrate
```

## What you need before running Checkpoint

- a working queue connection (database, Redis, or SQS)
- a running queue worker
- the database binaries on the worker host (`pg_dump`, `pg_restore` for Postgres; `mysqldump`, `mysql` for MySQL)

For production:

- set `CP_DRIVER` to `postgres` or `mysql`
- set `CP_BACKUP_ARCHIVE_PASSWORD` for encryption
- set `CP_ALERT_EMAIL` for failure notifications
- configure a scheduler: `php artisan schedule:run` every minute

## For contributors

```bash
composer install
composer quality
```

Docs site:

```bash
cd website
npm install
npm run start
```
