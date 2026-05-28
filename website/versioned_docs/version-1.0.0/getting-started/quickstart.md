---
sidebar_position: 2
---

# Quickstart

1. Run guided install
2. Start worker and scheduler
3. Run your first backup

## 1. Run guided install

```bash
php artisan checkpoint:install
```

Set the driver in your `.env`:

```env
CP_DRIVER=mysql
```

## 2. Start worker and scheduler

Operations are async by default. Start a worker on the checkpoint queue:

```bash
php artisan queue:work --queue=checkpoint --timeout=3600
php artisan schedule:work
```

## 3. Run a backup

```bash
php artisan checkpoint:backup
php artisan checkpoint:status --limit=10
php artisan checkpoint:status --summary
```

Or run it synchronously:

```bash
php artisan checkpoint:backup --sync
```

## Before enabling restore

Configure these before running restores in non-local environments:

- `CP_RESTORE_ALLOWED_ENVIRONMENTS`
- Verify your backup artifacts with `checkpoint:restore --verify-only`
