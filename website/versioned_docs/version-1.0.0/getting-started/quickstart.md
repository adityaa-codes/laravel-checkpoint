---
sidebar_position: 2
---

# Quickstart

This package boots a config validator during service-provider startup, so a working quickstart has two parts:

1. run guided install
2. run worker and scheduler

## 1. Run guided install

```bash
php artisan checkpoint:install --preset=minimal
```

For PostgreSQL production, prefer:

```bash
php artisan checkpoint:install --preset=postgres-prod --write-env
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
php artisan checkpoint:enqueue-backup
php artisan checkpoint:status --limit=10
php artisan checkpoint:status --summary
php artisan checkpoint:doctor
php artisan checkpoint:report --limit=10
```

## 3. Replace placeholder backup command (minimal preset)

If you installed with `--preset=minimal`, the seeded shell command is:

```env
CP_CMD_LOGICAL_BACKUP="php -r if(!is_dir($argv[1]))mkdir($argv[1],0777,true);touch($argv[2]); {backup_dir} {output}"
```

Replace it with your real backup command once queue wiring is validated.

## Before enabling restore

Do not enable restore workflows until you have configured:

- `CP_RESTORE_ALLOWED_ENVIRONMENTS`
- `CP_RESTORE_ALLOWED_DATABASES`
- `CP_RESTORE_REQUIRE_CONFIRMATION`
- a verified-backup path that matches your driver and environment posture
