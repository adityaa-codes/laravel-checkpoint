---
sidebar_position: 1
---

# Choose A Driver

Start with the simplest driver that matches your real backup tool.

## `postgres`

Use this as the default PostgreSQL choice. It is a unified facade:

- routes logical operations (`logical_*`, `replication_sync`) to `pgdump`
- routes `pgbackrest_*` operations to `pgbackrest`

Best for:

- consumer-friendly PostgreSQL setups with one driver key
- production PostgreSQL where you need both DR and logical workflows

## `shell`

Use this when you already have working shell scripts or wrapper commands.

Best for:

- simple custom scripts
- existing internal backup tooling
- getting started quickly

## `pgbackrest`

Use this for PostgreSQL disaster-recovery style backup and restore workflows.

Best for:

- repository-based PostgreSQL backups
- PostgreSQL restore and verification flows

## `pgdump`

Use this for PostgreSQL logical dumps.

Best for:

- export-style backups
- logical restore workflows

## `mysql`

Use this for MySQL logical dumps and optional binlog replay.

Best for:

- `mysqldump`
- MySQL PITR-style workflows

## Recommendation

If you are unsure:

- PostgreSQL: start with `postgres`
- MySQL: start with `mysql`
- custom/legacy commands: use `shell`
