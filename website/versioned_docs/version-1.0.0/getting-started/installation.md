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
php artisan db-ops:install --preset=minimal
```

Available presets:

- `minimal`: local/testing baseline (`shell`)
- `postgres-prod`: production PostgreSQL baseline (`postgres` facade)
- `mysql-prod`: production MySQL baseline (`mysql`)

If you need manual control, you can still publish and migrate directly:

```bash
php artisan vendor:publish --tag="laravel-checkpoint-config"
php artisan vendor:publish --tag="laravel-checkpoint-migrations"
php artisan migrate
```

## Baseline application requirements

Laravel Checkpoint assumes the host application provides:

- a working queue connection for long-running jobs
- a shared cache or lock backend for production scheduling and uniqueness
- a scheduler process running `php artisan schedule:run`
- worker hosts with the required backup binaries installed for the selected driver

## Local package development

This repository is developed inside DDEV. Common package checks are:

```bash
ddev exec composer install
ddev composer quality
```

If you are working on the docs site itself:

```bash
cd website
npm install
npm run start
```
