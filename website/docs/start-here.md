---
sidebar_position: 1
slug: /start-here
---

# Start Here

Laravel Checkpoint is the Database Reliability Layer for Laravel — backup, recovery, verification, and business continuity in one package.

If you are new to the package, do these 3 things first:

1. install the package and publish the config
2. set a small group of required env values
3. run one backup and check the status

## The 3-layer model

- **Backup** (commodity) — run logical backups reliably, on schedule
- **Recovery** (rare) — restore safely with guardrails, confirmation, blast-radius analysis
- **Verification** (moat) — automated recovery drills, PITR readiness checks, gate verdicts — this is what separates Checkpoint from backup tools

## Killer features

- **Recovery drills** — prove your restore path works before you need it
- **PITR readiness** — know if point-in-time recovery is actually available
- **Safety guardrails** — environment-aware gates that block destructive operations in production
- **Multi-driver** — MySQL, PostgreSQL (pg_dump, pgBackRest), shell scripts

## What this package does

- queues backup, restore, and drill jobs
- keeps an auditable record of every run
- gives you health, status, and reporting commands
- adds safety checks before risky restore and replication actions

## Best first path

Read these pages in order:

1. [Installation](./getting-started/installation.md)
2. [Quickstart](./getting-started/quickstart.md)
3. [Take Your First Backup](./common-tasks/take-your-first-backup.md)
4. [Check Health And Status](./common-tasks/check-health-and-status.md)

## Main commands most teams use first

```bash
php artisan checkpoint:enqueue-backup
php artisan checkpoint:status --summary
php artisan checkpoint:doctor
```
