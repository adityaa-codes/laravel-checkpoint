---
sidebar_position: 1
---

# Driver Overview

Laravel Checkpoint resolves the active `BackupDriver` from `checkpoint.driver` and the matching `checkpoint.drivers.{name}.class`.

## Bundled drivers

- `postgres`: unified PostgreSQL facade (`pgdump` + `pgbackrest` routing)
- `shell`: generic command-template driver
- `pgbackrest`: PostgreSQL backup and restore workflows around `pgbackrest`
- `pgdump`: PostgreSQL logical export and restore workflows
- `mysql`: MySQL logical dump and optional binlog-based PITR workflows

## How to choose

- use `postgres` for a single PostgreSQL driver experience in most consumer setups
- use `shell` when you already have a stable wrapper script or platform-specific command set
- use `pgbackrest` directly when you only want explicit pgBackRest operations
- use `pgdump` directly when you only want explicit logical-export operations
- use `mysql` for `mysqldump`-based exports and MySQL binlog replay workflows

## Custom drivers

If you need a custom runtime, bind your own implementation to the `BackupDriver` contract and point the driver config at your class.

Keep the operation catalog stable when possible so status, reporting, and safety behavior continue to work without custom UI code.
