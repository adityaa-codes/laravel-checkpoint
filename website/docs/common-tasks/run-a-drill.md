---
sidebar_position: 3
---

# Run A Drill

A drill helps you prove your restore process works, rather than only confirming backups exist.

## Queue a drill

```bash
php artisan db-ops:enqueue-drill
```

What it does:

- queues the `backup_drill` operation

## Record a drill result manually

```bash
php artisan db-ops:record-drill \
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
- RTO and RPO fields
- marker fields

## Check drill-related output

```bash
php artisan db-ops:status --summary
php artisan db-ops:report --limit=10
```
