---
sidebar_position: 1
---

# Installation

Install the package:

```bash
composer require adityaa-codes/laravel-checkpoint
```

Run guided install (recommended):

```bash
php artisan checkpoint:install --preset=minimal
```

Available presets:

- `minimal`: local/testing baseline (`shell`)
- `postgres-prod`: production PostgreSQL baseline (`postgres` facade)
- `mysql-prod`: production MySQL baseline (`mysql`)

Minimal preset note:

- `DB_OPS_CMD_LOGICAL_BACKUP` is seeded with a local bootstrap placeholder command that creates the backup directory and a marker file.
- Replace it with your real backup command before relying on backup artifacts.

Install summary readiness labels:

- `dev-only`: suitable for local/testing bootstrap
- `staging-ready`: production preset applied, but warnings remain to resolve
- `prod-ready`: no blocker or warning checks after doctor
- `not-ready`: blocker checks failed; resolve before non-local rollout

If you need manual control, you can still publish and migrate directly:

```bash
php artisan vendor:publish --tag="checkpoint-config"
php artisan vendor:publish --tag="checkpoint-migrations"
php artisan migrate
```

## Baseline application requirements

Before the package can run reliability operations, your Laravel app needs:

- a working queue connection for long-running jobs
- a queue worker
- the selected backup binaries on the worker host

For production, you will also want:

- a shared cache backend such as Redis
- a scheduler process running `php artisan schedule:run`

## For contributors

Common package checks are:

```bash
composer install
composer quality
```

If you are working on the docs site:

```bash
cd website
npm install
npm run start
```
