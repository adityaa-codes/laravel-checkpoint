---
sidebar_position: 1
---

# Driver Overview

Laravel Checkpoint resolves the active `BackupDriver` from `checkpoint.driver` and the matching `checkpoint.drivers.{name}.class`.

## Bundled drivers

- `postgres` — PostgreSQL backup and restore
- `mysql` — MySQL backup and restore
- `fake` — testing driver that simulates all operations without touching a real database

## How to choose

- Use `postgres` for PostgreSQL databases
- Use `mysql` for MySQL databases
- Use `fake` for testing environments

## Custom drivers

Run `php artisan checkpoint:make-driver` to scaffold a custom driver. Then bind your implementation to the `BackupDriver` contract and point the driver config at your class.
