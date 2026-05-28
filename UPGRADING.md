# Upgrading

## Framework Compatibility

This package supports Laravel `12.x` and `13.x`.

## Unreleased

Replication workflows now have a dedicated queue entrypoint: `checkpoint:replicate`.
When adopting this flow:

1. configure replication policy env vars in each environment (`CP_REPLICATION_REQUIRE_CONFIRMATION_TOKEN`, `CP_REPLICATION_BLOCK_IN_CI`, `CP_REPLICATION_REQUIRE_DRY_RUN_BEFORE_APPLY`)
2. define allowed destination identifiers via `CP_REPLICATION_ALLOWLISTED_DESTINATIONS`
3. set default critical-table guardrails with `CP_REPLICATION_CRITICAL_TABLES`
4. verify operator runbooks use dry-run first, then `--apply`/`--force-overwrite` only through approved change windows

When upgrading consumer applications from Laravel 12 to 13, review both guides and then validate your application-level configuration and listeners:

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

## Notes

- shell command templates are configured through package config instead of module-local classes
- queue safety behavior such as destructive-operation retry limits and orphan recovery is handled by the package
- package consumers should treat drivers, jobs, and config validation as internal unless explicitly documented otherwise

## Staged Enforcement Rollout

When upgrading existing environments, apply restore and observability hardening in stages so operator workflows stay predictable:

1. publish latest package migrations and run `php artisan migrate`
2. run `php artisan checkpoint:status --summary`, `php artisan checkpoint:doctor --format=json`, and `php artisan checkpoint:report --limit=10` to capture a pre-enforcement baseline
3. set restore posture controls first:
   - `CP_RESTORE_ALLOWED_ENVIRONMENTS`
   - `CP_RESTORE_ALLOWED_DATABASES`
   - `CP_RESTORE_REQUIRE_CONFIRMATION=true`
4. keep `CP_RESTORE_REQUIRE_VERIFIED_BACKUP=false` briefly while you establish a clean verified signal cadence
5. run at least one successful verification cycle (`pgbackrest_check` / `pgbackrest_verify` or logical backup verification path), then switch `CP_RESTORE_REQUIRE_VERIFIED_BACKUP=true`
6. keep `CP_RESTORE_ALLOW_IN_CI=false` unless CI restore execution is an explicit requirement

Recommended migration safety checks after rollout:

- confirm `db_ops_restore_decision_events` exists and is receiving `evaluate`, `block`, and `allow` events for restore attempts
- confirm command-run reporting indexes exist, including `db_ops_command_runs_status_updated_at_index`, for high-volume doctor/recovery/status scans
- verify restore runs persist `metadata.restore_audit` and that summary/report surfaces expose matching restore decision context

## Enforcement Matrix (Recommended Order)

Use this matrix to roll out strict controls without surprising operators:

1. **Schema and baseline first**
   - Run migrations.
   - Capture baseline outputs:
     - `php artisan checkpoint:status --summary --format=json`
     - `php artisan checkpoint:doctor --format=json`
     - `php artisan checkpoint:report --limit=10 --format=json`

2. **Restore posture controls**
   - Enforce:
     - `CP_RESTORE_ALLOWED_ENVIRONMENTS`
     - `CP_RESTORE_ALLOWED_DATABASES`
     - `CP_RESTORE_REQUIRE_CONFIRMATION=true`
   - Keep:
     - `CP_RESTORE_ALLOW_IN_CI=false` (unless explicitly required)

3. **Verification provenance enforcement**
   - Temporarily keep `CP_RESTORE_REQUIRE_VERIFIED_BACKUP=false` only while building verified signal coverage.
   - After verified runs are healthy, set `CP_RESTORE_REQUIRE_VERIFIED_BACKUP=true`.

4. **Scheduler and lock-store safety**
   - Keep `checkpoint.schedule.without_overlapping=true` and `checkpoint.schedule.on_one_server=true` in clustered environments.
   - Ensure `cache.default` and `checkpoint.queue.lock_store` use a shared non-local driver (for example Redis).

5. **Drill posture and remediation**
   - Enable scheduled drills:
     - `CP_BACKUP_DRILL_SCHEDULE_ENABLED=true`
     - `CP_BACKUP_DRILL_DAILY_AT`
     - `CP_BACKUP_DRILL_TIMEZONE`
   - Monitor:
     - `summary.backup_drill_trend`
     - `summary.backup_drill_remediation_playbook`
     - health checks `backup_drill.trend` and `backup_drill.playbook`

6. **Notification routing and incident handoff**
   - Enable routing:
     - `CP_NOTIFICATIONS_ENABLED=true`
     - `CP_NOTIFICATIONS_ROUTE_WARNING`
     - `CP_NOTIFICATIONS_ROUTE_CRITICAL`
   - For chat/webhooks, verify drill alarms include `payload.remediation` and playbook context fields in message payloads.

## Rollback Strategy Per Stage

If a stage creates operational friction, roll back only that stage and keep earlier safety wins:

- restore posture stage:
  - relax allowlists temporarily while preserving confirmation requirement
- verification stage:
  - set `CP_RESTORE_REQUIRE_VERIFIED_BACKUP=false` briefly, then re-enable after fixing verification pipeline
- scheduler/lock-store stage:
  - disable `on_one_server`/`without_overlapping` only as a last resort and only in non-clustered contexts
- drill stage:
  - keep drill recording manual (`checkpoint:record-drill`) if schedule cadence needs adjustment
- notification stage:
  - reduce routing fan-out (for example, log-only) while preserving event emission

## Operator Acceptance Checklist

Before declaring upgrade completion:

- `checkpoint:doctor --format=json` has no unexpected `config.validation` failures
- `checkpoint:report --format=json` exposes expected `summary`, `breakdown`, `verification`, and `health` blocks
- restore attempts record `metadata.restore_audit` and append restore decision events
- drill outputs include trend and remediation playbook payloads
- notification payloads include actionable commands for critical/warn drill alarms
