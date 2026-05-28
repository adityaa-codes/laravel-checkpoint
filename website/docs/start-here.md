---
sidebar_position: 1
---

# Start Here

Laravel Checkpoint is a database reliability layer for Laravel: backup, restore, PITR, replication, and recovery drills in one package.

## The 3-layer model

- **Backup** — run logical backups reliably, on schedule
- **Recovery** — restore safely with guardrails and confirmation
- **Verification** — automated recovery drills and health checks. This is what separates Checkpoint from backup tools.

## What this package does

- runs backup, restore, and drill operations
- keeps an auditable record of every run
- gives you status, health, and reporting commands
- adds safety checks before destructive operations

## Main commands

```bash
php artisan checkpoint:backup
php artisan checkpoint:restore --sync
php artisan checkpoint:drill
php artisan checkpoint:status --summary
php artisan checkpoint:status --health
php artisan checkpoint:status --full
```

## Best first path

Read in order:

1. [Installation](./getting-started/installation.md)
2. [Quickstart](./getting-started/quickstart.md)
3. [Take Your First Backup](./common-tasks/take-your-first-backup.md)
4. [Check Health And Status](./common-tasks/check-health-and-status.md)
