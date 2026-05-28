---
sidebar_position: 1
---

# Installation

Install the package in your Laravel application:

```bash
composer require adityaa-codes/laravel-checkpoint
```

Run guided install:

```bash
php artisan checkpoint:install
```

`checkpoint:install` publishes config and migrations, then runs a health check.

Options:

- `--skip-publish` — skip publishing config and migrations
- `--skip-migrate` — skip running migrations
- `--skip-doctor` — skip the health check
- `--force` — skip confirmation prompts

If you need manual control:

```bash
php artisan vendor:publish --tag="checkpoint-config"
php artisan vendor:publish --tag="checkpoint-migrations"
php artisan migrate
```

## Environment variables

| Variable | Purpose | Default |
|---|---|---|
| `CP_DRIVER` | Active backup driver (`postgres`, `mysql`, `fake`) | *(required)* |
| `CP_QUEUE_NAME` | Queue name for async operations | `checkpoint` |
| `CP_BACKUP_ARCHIVE_PASSWORD` | Password for backup archives | — |
| `CP_RESTORE_ALLOWED_ENVIRONMENTS` | Envs where restore is permitted | — |
| `CP_ALERT_EMAIL` | Email address for alerts | — |
| `CP_SLACK_WEBHOOK` | Slack webhook URL for notifications | — |
| `CP_TELEGRAM_BOT_TOKEN` | Telegram bot token | — |
| `CP_TELEGRAM_CHAT_ID` | Telegram chat ID | — |

## Baseline requirements

Laravel Checkpoint assumes the host application provides:

- A working queue connection for long-running jobs
- A shared cache or lock backend for production scheduling and uniqueness
- A scheduler process running `php artisan schedule:run`
- Worker hosts with the required binaries for the selected driver

## Local package development

```bash
composer install
composer quality
```

For the docs site:

```bash
cd website
npm install
npm run start
```
