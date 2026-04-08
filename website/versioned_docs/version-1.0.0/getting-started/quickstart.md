---
sidebar_position: 2
---

# Quickstart

This package boots a config validator during service-provider startup, so a working quickstart has two parts:

1. publish the config and migrations
2. make the selected driver configuration internally consistent before the app boots

## Minimal shell-driver setup

The default driver is `shell`. Configure the queue and shell driver first:

```env
DB_OPS_DRIVER=shell
DB_OPS_QUEUE_NAME=db-ops
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_CMD_TIMEOUT=3600

DB_OPS_CMD_LOGICAL_BACKUP="/usr/local/bin/checkpoint-backup"
DB_OPS_CMD_RESTORE_LATEST="/usr/local/bin/checkpoint-restore-latest"
DB_OPS_CMD_RESTORE_FILE="/usr/local/bin/checkpoint-restore-file {argument}"
DB_OPS_CMD_PITR_RESTORE="/usr/local/bin/checkpoint-pitr-restore {argument}"
DB_OPS_CMD_BACKUP_DRILL="/usr/local/bin/checkpoint-drill"
```

## Run the package

Queue a logical backup:

```bash
php artisan db-ops:enqueue-backup
```

Inspect recent runs:

```bash
php artisan db-ops:status --limit=10
php artisan db-ops:status --summary
```

Run operator diagnostics:

```bash
php artisan db-ops:doctor
php artisan db-ops:report --limit=10
```

## Before enabling restore

Do not enable restore workflows until you have configured:

- `DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS`
- `DB_OPS_RESTORE_ALLOWED_DATABASES`
- `DB_OPS_RESTORE_REQUIRE_CONFIRMATION`
- a verified-backup path that matches your driver and environment posture
