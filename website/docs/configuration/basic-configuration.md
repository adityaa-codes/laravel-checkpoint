---
sidebar_position: 1
---

# Basic Configuration

You do not need to understand every config key to get started.

For a simple setup, focus on these values:

## Required basics

```env
DB_OPS_DRIVER=shell
DB_OPS_QUEUE_NAME=db-ops
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_CMD_TIMEOUT=3600
DB_OPS_CMD_LOGICAL_BACKUP="/usr/local/bin/checkpoint-backup"
```

## What each one does

- `DB_OPS_DRIVER`
  Chooses which driver runs backup commands. Start with `shell` unless you already know you need `pgbackrest`, `pgdump`, or `mysql`.
- `DB_OPS_QUEUE_NAME`
  The queue name used for checkpoint jobs.
- `DB_OPS_QUEUE_TIMEOUT`
  How long a checkpoint job may run before Laravel considers it timed out.
- `DB_OPS_QUEUE_RETRY_AFTER`
  How long Laravel waits before retrying a job that seems lost.
- `DB_OPS_QUEUE_UNIQUE_FOR`
  How long the package keeps uniqueness locks for exclusive jobs.
- `DB_OPS_CMD_TIMEOUT`
  How long the shell driver command may run.
- `DB_OPS_CMD_LOGICAL_BACKUP`
  The command used for a logical backup.

This is the minimum setup for a first backup. Restore, drill, and PITR commands can be added later.

## Important rule

Keep this relationship:

```text
driver timeout <= queue timeout < queue retry_after <= unique_for
```

If this is wrong, the package can fail during app boot because config validation runs early.
