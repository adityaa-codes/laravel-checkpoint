---
sidebar_position: 1
---

# Basic Configuration

Checkpoint's configuration powers every layer of the reliability stack — from backup execution to recovery verification. Start simple and layer in safety gates as your confidence grows.

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

| Env var | Dot-notation equivalent |
|---|---|
| `DB_OPS_DRIVER` | `checkpoint.default_driver` |
| `DB_OPS_QUEUE_NAME` | `checkpoint.queue.name` |
| `DB_OPS_QUEUE_TIMEOUT` | `checkpoint.queue.timeout` |
| `DB_OPS_QUEUE_RETRY_AFTER` | `checkpoint.queue.retry_after` |
| `DB_OPS_QUEUE_UNIQUE_FOR` | `checkpoint.queue.unique_for` |
| `DB_OPS_CMD_TIMEOUT` | `checkpoint.drivers.shell.command_timeout_seconds` |
| `DB_OPS_CMD_LOGICAL_BACKUP` | `checkpoint.drivers.shell.commands.logical_backup` |

## What each one does

- `DB_OPS_DRIVER`
  Chooses the driver for reliability operations. Start with `shell` unless you already know you need `pgbackrest`, `pgdump`, `postgres`, or `mysql`.
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

This is enough for a first backup — the backup layer of the reliability stack.

Shell driver first-run prerequisite:

- define command templates for every operation you plan to run (`logical_backup`, restore, drill, and so on); if a template is missing, the run fails with a configuration error.

## Next layer: recovery verification and safety gates

Once backups are working, layer in:

```env
DB_OPS_RESTORE_ALLOWED_ENVIRONMENTS=local,staging
DB_OPS_RESTORE_ALLOWED_DATABASES=app
DB_OPS_RESTORE_REQUIRE_CONFIRMATION=true
DB_OPS_RESTORE_CONFIRMATION_PHRASE="I understand the risks"
```

See [Restore Guardrails](../safety/restore-guardrails.md) for the full safety surface.

## Important rule

Keep this relationship:

```text
driver timeout <= queue timeout < queue retry_after <= unique_for
```

If this is wrong, boot-time config validation can fail before jobs even start.
