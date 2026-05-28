---
sidebar_position: 1
---

# Basic Configuration

You do not need every config key on day one. Start with these.

## Required basics

```env
CP_DRIVER=postgres
CP_QUEUE_NAME=checkpoint
CP_BACKUP_ARCHIVE_PASSWORD=your-password-here
```

`CP_DRIVER` must be one of: `postgres`, `mysql`, or `fake`.

`CP_QUEUE_NAME` defaults to `checkpoint`. Change it only if your queue worker uses a different queue name.

`CP_BACKUP_ARCHIVE_PASSWORD` enables encryption. Leave unset to skip encryption.

## Config reference

| Env var | Config key (dot notation) | What it does |
|---|---|---|
| `CP_DRIVER` | `checkpoint.driver` | Backup driver (`postgres`, `mysql`, `fake`) |
| `CP_QUEUE_NAME` | `checkpoint.queue.name` | Queue name for checkpoint jobs (default: `checkpoint`) |
| `CP_BACKUP_ARCHIVE_PASSWORD` | `checkpoint.encryption.password` | Encryption password (unset = no encryption) |
| `CP_RESTORE_ALLOWED_ENVIRONMENTS` | `checkpoint.restore.allowed_environments` | Comma-separated envs where restore is allowed (default: `local,testing,staging`) |
| `CP_ALERT_EMAIL` | `checkpoint.notifications.mail.to` | Email for backup/drill failure notifications |
| `CP_SLACK_WEBHOOK` | `checkpoint.notifications.slack.webhook_url` | Slack incoming webhook URL |
| `CP_TELEGRAM_BOT_TOKEN` | `checkpoint.notifications.telegram.bot_token` | Telegram bot token |
| `CP_TELEGRAM_CHAT_ID` | `checkpoint.notifications.telegram.chat_id` | Telegram chat ID |

## Queue settings (config only, not env vars)

These live in `config/checkpoint.php`:

- `queue.timeout` — seconds before the queue worker kills the job (default: 3600)
- `queue.unique_for` — uniqueness lock duration (default: 3660)
- `queue.max_attempts` — max retries per job (default: 1)
- `queue.heartbeat_interval_seconds` — how often the driver records a heartbeat (default: 30)

Set your queue worker timeout to match:

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
```

## Binary paths

Binary paths for `pg_dump`, `pg_restore`, `mysqldump`, and `mysql` come from Laravel's `config/database.php` connections, not from Checkpoint config. Use the `dump.dump_binary_path` key, same as spatie/laravel-backup:

```php
// config/database.php
'pgsql' => [
    'dump' => [
        'dump_binary_path' => '/usr/bin',
        'timeout' => 60 * 5,
    ],
],
```

## Next: safety configuration

```env
CP_RESTORE_ALLOWED_ENVIRONMENTS=local,staging
```

Restore is blocked outside these environments. See [Restore Guardrails](../safety/restore-guardrails.md) for the full safety surface.
