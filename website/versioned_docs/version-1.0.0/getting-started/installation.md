---
sidebar_position: 1
---

# Installation

Install the package in your Laravel application:

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

Laravel Checkpoint assumes the host application provides:

- a working queue connection for long-running jobs
- a shared cache or lock backend for production scheduling and uniqueness
- a scheduler process running `php artisan schedule:run`
- worker hosts with the required backup binaries installed for the selected driver

## Local package development

Common package checks are:

```bash
composer install
composer quality
```

If you are working on the docs site itself:

```bash
cd website
npm install
npm run start
```
