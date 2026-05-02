---
sidebar_position: 1
---

# Basic Configuration

You do not need every config key on day one.

For a simple setup, start with these values:

## Required basics

```env
DB_OPS_DRIVER=shell
DB_OPS_QUEUE_NAME=db-ops
DB_OPS_QUEUE_TIMEOUT=3600
DB_OPS_QUEUE_RETRY_AFTER=3660
DB_OPS_QUEUE_UNIQUE_FOR=3660
DB_OPS_CMD_TIMEOUT=3600
DB_OPS_CMD_LOGICAL_BACKUP="php -r if(!is_dir($argv[1]))mkdir($argv[1],0777,true);touch($argv[2]); {backup_dir} {output}"
```

## What each one does

- `DB_OPS_DRIVER`
  Chooses the backup driver. Start with `shell` unless you already know you need `pgbackrest`, `pgdump`, or `mysql`.
- `DB_OPS_QUEUE_NAME`
  The queue name used for checkpoint jobs.
- `DB_OPS_QUEUE_TIMEOUT`
  Maximum runtime for a checkpoint job before Laravel marks it as timed out.
- `DB_OPS_QUEUE_RETRY_AFTER`
  How long Laravel waits before retrying a job that looks lost.
- `DB_OPS_QUEUE_UNIQUE_FOR`
  How long uniqueness locks are kept for exclusive jobs.
- `DB_OPS_CMD_TIMEOUT`
  Maximum runtime for the shell command.
- `DB_OPS_CMD_LOGICAL_BACKUP`
  The command used for a logical backup.
  The guided minimal preset seeds a local bootstrap placeholder command only; replace it with your real backup command.

This is enough for a first backup. You can add restore, drill, and PITR settings later.

Shell driver first-run prerequisite:

- define command templates for every operation you plan to run (`logical_backup`, restore, drill, and so on); if a template is missing, the run fails with a configuration error.

## Important rule

Keep this relationship:

```text
driver timeout <= queue timeout < queue retry_after <= unique_for
```

If this is wrong, boot-time config validation can fail before jobs even start.
