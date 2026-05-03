---
sidebar_position: 3
---

# Run A Drill

Recovery drills are the killer feature. Backups are a commodity — proving you can restore under RTO is real business continuity.

Drills are the verification layer that separates Checkpoint from backup tools. A backup without a verified restore path is just a file.

## What a drill validates

- the backup artifact is restorable (not corrupted)
- restore commands execute correctly in your environment
- restore completes within your RTO target
- the restored data passes your integrity markers
- you have evidence of recovery capability for audits

## Queue a drill

```bash
php artisan checkpoint:enqueue-drill
```

What it does:

- queues the `backup_drill` operation
- exercises your configured restore path end to end

## Record a drill result manually

```bash
php artisan checkpoint:record-drill \
  --run-uuid="00000000-0000-0000-0000-000000000000" \
  --overall-result=pass \
  --executed-at="2026-03-11T10:30:00+00:00"
```

Required values:

- `--run-uuid`
  A UUID for the drill run
- `--overall-result`
  `pass` or `fail`
- `--executed-at`
  A valid date or ISO-8601 timestamp

Optional values:

- `--executed-by`
  Example: `ops-bot` or `aditya`
- RTO and RPO fields — track target vs. actual recovery times
- marker fields — integrity verification markers

## Scheduling drills

Drills should run on a schedule, not just on-demand. Add to your scheduler:

```php
// app/Console/Kernel.php
$schedule->command('checkpoint:enqueue-drill')->weekly()->sundays()->at('03:00');
```

Weekly is the recommended minimum. High-reliability environments should drill daily.

## Drill report anatomy

After a drill completes, use:

```bash
php artisan checkpoint:status --summary
php artisan checkpoint:report --limit=10
```

The report includes:

- drill pass/fail status
- RTO compliance (target vs. actual)
- integrity marker results
- trend data across drill history

## Check drill-related output

```bash
php artisan checkpoint:status --summary
php artisan checkpoint:report --limit=10
```
