---
sidebar_position: 2
---

# PITR And Drills

PITR and drills are separate workflows in the package:

- PITR evaluates whether a point-in-time restore is currently possible
- drills record evidence that restore procedures actually work

## PITR readiness

Evaluate readiness for now or a specific target:

```bash
php artisan db-ops:pitr-readiness
php artisan db-ops:pitr-readiness "2026-03-11 11:30:00" --format=json
php artisan db-ops:pitr-readiness "2026-03-11 11:30:00" --agent
```

The readiness command reports pass/fail checks and returns a non-zero exit code when readiness is `not_ready`.

## Queue a drill

```bash
php artisan db-ops:enqueue-drill
```

## Record a drill result

```bash
php artisan db-ops:record-drill \
  --run-uuid="00000000-0000-0000-0000-000000000000" \
  --overall-result=pass \
  --executed-at="2026-03-11T10:30:00+00:00"
```

Additional fields allow marker, RTO, and RPO evidence to be attached to the drill record.

## Reporting surfaces

Drill and PITR health show up in:

- `db-ops:status --summary`
- `db-ops:doctor`
- `db-ops:report`
