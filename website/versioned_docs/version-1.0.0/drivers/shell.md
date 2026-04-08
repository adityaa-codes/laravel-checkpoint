---
sidebar_position: 2
---

# Shell Driver

The `shell` driver is the default package driver. It is configured through command templates in `checkpoint.drivers.shell.commands`.

## Supported command templates

- `logical_backup`
- `logical_restore_latest`
- `logical_restore_file`
- `pitr_restore`
- `backup_drill`
- `pgbackrest_check`
- `pgbackrest_info`

## Key config

- `drivers.shell.backup_dir`
- `drivers.shell.backup_prefix`
- `drivers.shell.pre_restore_snapshot`
- `drivers.shell.command_timeout_seconds`

## When to use it

Use the shell driver when your team already has:

- vetted wrapper scripts
- environment-specific backup tools
- platform automation outside the package

The package handles orchestration, validation, queueing, persistence, and reporting. Your scripts remain responsible for the backup mechanics.
