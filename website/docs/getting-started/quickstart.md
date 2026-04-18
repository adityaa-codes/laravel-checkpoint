---
sidebar_position: 2
---

# Quickstart

This is the simplest path for a first working setup.

Command groups:

- `db-ops:do:*` → day-to-day operator actions
- `db-ops:check:*` → health/readiness checks
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

## 2. Start a queue worker

```bash
php artisan queue:work --queue=db-ops --timeout=3600
```

## 3. Start scheduler loop

```bash
php artisan schedule:work
```

## 4. Queue your first backup

```bash
php artisan db-ops:do:backup
```

## 5. Check that it worked

```bash
php artisan db-ops:do:status --limit=10
php artisan db-ops:do:status --summary
php artisan db-ops:check:doctor
```

## What success looks like

- the backup job appears in `db-ops:status`
- the summary page shows no obvious failure
- `db-ops:doctor` does not report config problems

## Do not do this first

Do not start with:

- restore
- replication
- PITR
- drills
- deep safety tuning

Get one backup working first.
