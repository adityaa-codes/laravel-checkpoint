---
sidebar_position: 2
---

# PITR And Drills

## PITR restore

Restore to a point in time:

```bash
php artisan checkpoint:restore --pitr="2026-03-11 11:30:00"
```

Evaluate PITR readiness without executing:

```bash
php artisan checkpoint:restore --pitr-dry-run
```

## Queue a drill

```bash
php artisan checkpoint:drill
```

## Drill results

Drill results appear in:

- `checkpoint:status --summary`
- `checkpoint:status --health`
- `checkpoint:status --full`
