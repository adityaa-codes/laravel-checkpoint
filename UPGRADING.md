# Upgrading

## Framework Compatibility

This package supports Laravel `12.x` and `13.x`.

When upgrading consumer applications from Laravel 12 to 13, review both guides and validate your application-level configuration and listeners:

1. Laravel 12 upgrade guide: https://laravel.com/docs/12.x/upgrade
2. Laravel 13 upgrade guide: https://laravel.com/docs/13.x/upgrade

Package maintainers and integrators should pay special attention to these Laravel 13 changes:

- CSRF middleware rename (`VerifyCsrfToken` -> `PreventRequestForgery`) when excluding middleware in tests or route groups.
- Queue event payload/property updates (`JobAttempted::$exception`, `QueueBusy::$connectionName`) for custom listeners.
- Contract additions for custom implementations (dispatcher / queue / response factory / cache store).
- Cache hardening defaults (`cache.serializable_classes`) in applications that serialize objects into cache.

## v1.0.0

`v1.0.0` is the first standalone release of `laravel-checkpoint`.

There is no prior package version to upgrade from inside this repository.

## Queue Rename (pre-v1)

The default queue name was changed from `db-ops` to `checkpoint`. If you were running a pre-release version, update your queue worker:

```bash
php artisan queue:work --queue=checkpoint
```

Override via `CP_QUEUE_NAME` if you need a custom queue name.

## Notification Configuration Restructure (pre-v1)

The notifications config was restructured from a simple `channels`/`on_success`/`on_failure` model to a spatie-style event-to-channel mapping. After upgrading, re-publish the config:

```bash
php artisan vendor:publish --tag=checkpoint-config --force
```

Then configure notification channels:

```env
CP_ALERT_EMAIL=ops@example.com
CP_SLACK_WEBHOOK=https://hooks.slack.com/...
CP_TELEGRAM_BOT_TOKEN=12345:abc
CP_TELEGRAM_CHAT_ID=-12345
```

## Encryption Simplification (pre-v1)

The `encryption.enabled` boolean was removed. Encryption is now active when `CP_BACKUP_ARCHIVE_PASSWORD` is set to a non-empty string. If you had `enabled: false` with a password set, encryption was previously disabled but is now active — review your configuration.

## Staged Enforcement Rollout

When upgrading existing environments, apply restore and observability hardening in stages:

1. publish latest package migrations and run `php artisan migrate`
2. capture a pre-enforcement baseline:
   ```bash
   php artisan checkpoint:status --summary --format=json
   php artisan checkpoint:status --health --format=json
   php artisan checkpoint:status --full --limit=10 --format=json
   ```
3. set restore posture controls:
   - `CP_RESTORE_ALLOWED_ENVIRONMENTS`
   - configure `restore.allowed_databases` in `config/checkpoint.php`
4. keep `restore.require_verified_backup=false` briefly while you establish a clean verified signal cadence
5. after verified runs are healthy, set `restore.require_verified_backup=true`

## Operator Acceptance Checklist

Before declaring upgrade completion:

- `checkpoint:status --health --format=json` has no `config.validation` failures
- `checkpoint:status --full --format=json` exposes expected `summary`, `breakdown`, `verification`, and `health` blocks
- restore attempts record `metadata.restore_audit` and append restore decision events
- notification payloads include actionable context for backup/drill failures

## Migrating From An Embedded Operations Module

If you previously used the checkpoint and recovery logic inside an application module such as `Modules/Operations`, the migration path is:

1. install `adityaa-codes/laravel-checkpoint`
2. publish and run this package's migrations
3. move command templates and queue settings into `config/checkpoint.php`
4. replace direct module service calls with:
   - `AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction`
   - or `AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint`
5. update any admin workflows to use the package command and model APIs
6. stop depending on internal classes that are not part of the documented public API

## Migrating From spatie/laravel-backup

Run the migration command for interactive guidance:

```bash
php artisan checkpoint:migrate-from-spatie
```

Key differences to be aware of:
- Checkpoint is database-only — spatie file backups must be managed separately
- Queued execution replaces spatie's synchronous cron execution
- Notifications use a class-to-channel mapping instead of boolean toggle
- Multi-tier retention is collapsed to a single `retention_days` value
