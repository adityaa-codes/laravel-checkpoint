---
sidebar_position: 1
---

# Basic Configuration

Checkpoint's configuration powers every layer of the reliability stack — from backup execution to recovery verification. Start simple and layer in safety gates as your confidence grows.

You do not need every config key on day one.

For a simple setup, start with these values:

## Required basics

```env
CP_DRIVER=shell
CP_QUEUE_NAME=db-ops
CP_QUEUE_TIMEOUT=3600
CP_QUEUE_RETRY_AFTER=3660
CP_QUEUE_UNIQUE_FOR=3660
CP_CMD_TIMEOUT=3600
CP_CMD_LOGICAL_BACKUP="php -r if(!is_dir($argv[1]))mkdir($argv[1],0777,true);touch($argv[2]); {backup_dir} {output}"
```

| Env var | Dot-notation equivalent |
|---|---|
| `CP_DRIVER` | `checkpoint.default_driver` |
| `CP_QUEUE_NAME` | `checkpoint.queue.name` |
| `CP_QUEUE_TIMEOUT` | `checkpoint.queue.timeout` |
| `CP_QUEUE_RETRY_AFTER` | `checkpoint.queue.retry_after` |
| `CP_QUEUE_UNIQUE_FOR` | `checkpoint.queue.unique_for` |
| `CP_CMD_TIMEOUT` | `checkpoint.drivers.shell.command_timeout_seconds` |
| `CP_CMD_LOGICAL_BACKUP` | `checkpoint.drivers.shell.commands.logical_backup` |

## What each one does

- `CP_DRIVER`
  Chooses the driver for reliability operations. Start with `shell` unless you already know you need `pgbackrest`, `pgdump`, `postgres`, or `mysql`.
- `CP_QUEUE_NAME`
  The queue name used for checkpoint jobs.
- `CP_QUEUE_TIMEOUT`
  Maximum runtime for a checkpoint job before Laravel marks it as timed out.
- `CP_QUEUE_RETRY_AFTER`
  How long Laravel waits before retrying a job that looks lost.
- `CP_QUEUE_UNIQUE_FOR`
  How long uniqueness locks are kept for exclusive jobs.
- `CP_CMD_TIMEOUT`
  Maximum runtime for the shell command.
- `CP_CMD_LOGICAL_BACKUP`
  The command used for a logical backup.
  The guided minimal preset seeds a local bootstrap placeholder command only; replace it with your real backup command.

This is enough for a first backup — the backup layer of the reliability stack.

Shell driver first-run prerequisite:

- define command templates for every operation you plan to run (`logical_backup`, restore, drill, and so on); if a template is missing, the run fails with a configuration error.

## Next layer: recovery verification and safety gates

Once backups are working, layer in:

```env
CP_RESTORE_ALLOWED_ENVIRONMENTS=local,staging
CP_RESTORE_ALLOWED_DATABASES=app
CP_RESTORE_REQUIRE_CONFIRMATION=true
CP_RESTORE_CONFIRMATION_PHRASE="I understand the risks"
```

See [Restore Guardrails](../safety/restore-guardrails.md) for the full safety surface.

## Important rule

Keep this relationship:

```text
driver timeout <= queue timeout < queue retry_after <= unique_for
```

If this is wrong, boot-time config validation can fail before jobs even start.
