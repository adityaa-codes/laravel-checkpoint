---
sidebar_position: 1
---

# Installation

Install the package:

```bash
composer require adityaa-codes/laravel-checkpoint
```

Then publish the config and migrations:

```bash
php artisan vendor:publish --tag="laravel-checkpoint-config"
php artisan vendor:publish --tag="laravel-checkpoint-migrations"
php artisan migrate
```

## Baseline application requirements

Before the package can run jobs, your Laravel app needs:

- a working queue connection for long-running jobs
- a queue worker
- the selected backup binaries on the worker host

For production, you will also want:

- a shared cache backend such as Redis
- a scheduler process running `php artisan schedule:run`

## Local package development

This repository is developed inside DDEV. Common package checks are:

```bash
ddev exec composer install
ddev composer quality
```

If you are working on the docs site:

```bash
cd website
npm install
npm run start
```
