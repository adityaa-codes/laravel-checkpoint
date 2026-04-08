---
sidebar_position: 1
slug: /start-here
---

# Start Here

Laravel Checkpoint helps you run database backup and restore jobs from Laravel.

If you are new to the package, do these 3 things first:

1. install the package and publish the config
2. set a small group of required env values
3. run one backup and check the status

## What this package does

- queues backup and restore jobs
- keeps a record of runs
- gives you health and status commands
- adds safety checks before risky restore actions

## Best first path

Read these pages in order:

1. [Installation](./getting-started/installation.md)
2. [Quickstart](./getting-started/quickstart.md)
3. [Take Your First Backup](./common-tasks/take-your-first-backup.md)
4. [Check Health And Status](./common-tasks/check-health-and-status.md)

## Main commands most teams use first

```bash
php artisan db-ops:enqueue-backup
php artisan db-ops:status --summary
php artisan db-ops:doctor
```
