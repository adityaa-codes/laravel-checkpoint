---
sidebar_position: 1
slug: /overview
---

# Laravel Checkpoint

Laravel Checkpoint is a Laravel package for queue-driven backup, restore, drill, reporting, and operational safety workflows.

This docs site is generated from the package's current code and configuration surface:

- registered Artisan commands in `LaravelCheckpointServiceProvider`
- operation definitions in `CommandRunCatalog`
- runtime config in `config/checkpoint.php`
- scheduling hooks and guardrails enforced at boot

## What the package exposes

- queueable operations for backup, restore, PITR, drills, and replication
- scheduled backup, drill, health-check, orphan-recovery, and prune hooks
- operator-facing status, doctor, report, catalog, and PITR-readiness commands
- driver implementations for `shell`, `pgbackrest`, `pgdump`, and `mysql`
- restore guardrails for environment, target database, confirmation, verification, and blast radius

## Public operator surface

The package currently registers these commands:

- `checkpoint:enqueue`
- `checkpoint:enqueue-backup`
- `checkpoint:enqueue-drill`
- `checkpoint:status`
- `checkpoint:doctor`
- `checkpoint:report`
- `checkpoint:catalog-export`
- `checkpoint:pitr-readiness`
- `checkpoint:retention-policy`
- `checkpoint:record-drill`
- `checkpoint:replicate`
- `checkpoint:health-check`
- `checkpoint:recover-orphans`
- `checkpoint:prune`

## Built-in operations

`CommandRunCatalog` currently defines these queueable operations:

- `logical_backup`
- `logical_restore_latest`
- `logical_restore_file`
- `pitr_restore`
- `backup_drill`
- `pgbackrest_backup_full`
- `pgbackrest_backup_diff`
- `pgbackrest_backup_incr`
- `pgbackrest_restore`
- `pgbackrest_verify`
- `pgbackrest_check`
- `pgbackrest_info`
- `replication_sync`

Some operations are destructive and exclusive by design. The package enforces those semantics before the job reaches the driver.
