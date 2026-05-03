---
sidebar_position: 2
---

# Quickstart

This is the simplest path for a first working setup.

## Command groups

- `checkpoint:enqueue*, checkpoint:status` → day-to-day operator actions
- `checkpoint:doctor, checkpoint:report` → health/readiness checks
- `checkpoint:prune, checkpoint:retention-policy` → maintenance/governance

## 1. Run guided install

```bash
php artisan checkpoint:install --preset=minimal
```

For PostgreSQL production, prefer:

```bash
php artisan checkpoint:install --preset=postgres-prod --write-env
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
php artisan checkpoint:enqueue-backup
```

## 5. Check that it worked

```bash
php artisan checkpoint:status --limit=10
php artisan checkpoint:status --summary
php artisan checkpoint:doctor
```

## 6. Replace placeholder backup command (minimal preset)

If you installed with `--preset=minimal`, the seeded shell command is:

```env
CP_CMD_LOGICAL_BACKUP="php -r if(!is_dir($argv[1]))mkdir($argv[1],0777,true);touch($argv[2]); {backup_dir} {output}"
```

Replace it with your real backup command once queue wiring is validated.

## What success looks like

- the backup job appears in `checkpoint:status`
- the summary page shows no obvious failure
- `checkpoint:doctor` does not report config problems

## Next: prove your recovery path

Get one backup working, then immediately set up a drill to prove your recovery path:

```bash
php artisan checkpoint:enqueue-drill
```

Read [Run A Drill](../common-tasks/run-a-drill.md) for the full workflow.
