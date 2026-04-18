---
sidebar_position: 2
---

# Quickstart

This package boots a config validator during service-provider startup, so a working quickstart has two parts:

1. run guided install
2. run worker and scheduler

Command groups:

- `db-ops:do:*` → operator workflow
- `db-ops:check:*` → diagnostics/readiness
- `db-ops:admin:*` → maintenance/governance

## 1. Run guided install

```bash
php artisan db-ops:do:install --preset=minimal
```

For PostgreSQL production, prefer:

```bash
php artisan db-ops:do:install --preset=postgres-prod --write-env
```

This uses the unified `postgres` facade driver.

## 2. Run the package

Start worker and scheduler:

```bash
php artisan queue:work --queue=db-ops --timeout=3600
php artisan schedule:work
```

Queue and inspect:

```bash
php artisan db-ops:do:backup
php artisan db-ops:do:status --limit=10
php artisan db-ops:do:status --summary
php artisan db-ops:check:doctor
php artisan db-ops:check:report --limit=10
```

## Before enabling restore

Do not enable restore workflows until you have configured:

- `DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS`
- `DB_OPS_RESTORE_ALLOWED_DATABASES`
- `DB_OPS_RESTORE_REQUIRE_CONFIRMATION`
- a verified-backup path that matches your driver and environment posture
