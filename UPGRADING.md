# Upgrading

## Framework Compatibility

This package supports Laravel `12.x` and `13.x`.

## Unreleased

Replication workflows now have a dedicated queue entrypoint: `db-ops:replicate`.
When adopting this flow:

1. configure replication policy env vars in each environment (`DB_OPS_REPLICATION_REQUIRE_CONFIRMATION_TOKEN`, `DB_OPS_REPLICATION_BLOCK_IN_CI`, `DB_OPS_REPLICATION_REQUIRE_DRY_RUN_BEFORE_APPLY`)
2. define allowed destination identifiers via `DB_OPS_REPLICATION_ALLOWLISTED_DESTINATIONS`
3. set default critical-table guardrails with `DB_OPS_REPLICATION_CRITICAL_TABLES`
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
2. run `php artisan db-ops:status --summary`, `php artisan db-ops:doctor --format=json`, and `php artisan db-ops:report --limit=10` to capture a pre-enforcement baseline
3. set restore posture controls first:
   - `DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS`
   - `DB_OPS_RESTORE_ALLOWED_DATABASES`
   - `DB_OPS_RESTORE_REQUIRE_CONFIRMATION=true`
4. keep `DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP=false` briefly while you establish a clean verified signal cadence
5. run at least one successful verification cycle (`pgbackrest_check` / `pgbackrest_verify` or logical backup verification path), then switch `DB_OPS_RESTORE_REQUIRE_VERIFIED_BACKUP=true`
6. keep `DB_OPS_RESTORE_ALLOW_IN_CI=false` unless CI restore execution is an explicit requirement

Recommended migration safety checks after rollout:

- confirm `db_ops_restore_decision_events` exists and is receiving `evaluate`, `block`, and `allow` events for restore attempts
- confirm command-run reporting indexes exist, including `db_ops_command_runs_status_updated_at_index`, for high-volume doctor/recovery/status scans
- verify restore runs persist `metadata.restore_audit` and that summary/report surfaces expose matching restore decision context
