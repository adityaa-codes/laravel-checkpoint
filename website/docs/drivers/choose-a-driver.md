---
sidebar_position: 1
---

# Choose A Driver

Start with the simplest driver that matches your database engine and reliability requirements.

## Feature matrix

| Driver | Backup | PITR | Verification | Drills | Replication |
|---|---|---|---|---|---|
| `shell` | Yes | — | — | — | — |
| `mysql` | Yes | Yes (binlog) | Yes | Yes | — |
| `pgdump` | Yes | — | Yes | Yes | — |
| `pgbackrest` | Yes | Yes (WAL) | Yes | Yes | — |
| `postgres` (facade) | Yes | via pgbackrest | Yes | Yes | — |
| `fake` | Test only | — | — | — | — |

## `postgres` (facade)

Use this as the default PostgreSQL choice. It is a unified facade:

- routes logical operations (`logical_*`, `replication_sync`) to `pgdump`
- routes `pgbackrest_*` operations to `pgbackrest`

Best for:

- production PostgreSQL setups with one driver key
- environments where you need both disaster recovery and logical workflows

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

## `fake`

Use this for testing. Returns controlled output without touching any real binary.

Best for:

- CI pipelines
- package development and test suites

## Recommendation

If you are unsure:

- PostgreSQL: start with `postgres`
- MySQL: start with `mysql`
- custom/legacy commands: use `shell`
- testing/CI: use `fake`
