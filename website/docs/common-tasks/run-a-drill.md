---
sidebar_position: 3
---

# Run A Drill

A drill proves your restore path works before you need it in an incident. A backup without a verified restore is just a file.

## What a drill validates

- the backup artifact is restorable (not corrupt)
- restore commands execute correctly in your environment
- restore completes within your RTO target
- the restored data passes integrity checks
- you have evidence of recovery capability for audits

## Queue a drill

```bash
php artisan checkpoint:drill
```

This queues a drill job. It exercises your configured restore path end to end.

## Scheduling drills

Drills work best on a schedule, not on-demand. Add to `routes/console.php`:

```php
Schedule::command('checkpoint:drill')->weekly()->sundays()->at('03:00');
```

Weekly is the minimum. High-reliability environments drill daily.

## Check drill results

```bash
php artisan checkpoint:status --summary
php artisan checkpoint:status --full
```

The full report includes drill pass/fail status, RTO compliance, integrity marker results, and trend data.

## Drills and the status command

A healthy status shows backups and drill results, not just backup success. If `checkpoint:status --summary` shows drills failing, fix your restore path before you need it.
