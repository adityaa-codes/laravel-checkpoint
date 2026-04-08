---
sidebar_position: 3
---

# pgBackRest Driver

The `pgbackrest` driver is the PostgreSQL disaster-recovery-oriented driver in this package.

## Key config

- `drivers.pgbackrest.binary`
- `drivers.pgbackrest.stanza`
- `drivers.pgbackrest.repo`
- `drivers.pgbackrest.repositories`
- `drivers.pgbackrest.process_max`
- `drivers.pgbackrest.resume`
- `drivers.pgbackrest.start_fast`
- `drivers.pgbackrest.backup_standby`
- `drivers.pgbackrest.checksum_page`
- `drivers.pgbackrest.delta`
- `drivers.pgbackrest.command_timeout_seconds`

## Repository model

Repository configuration is explicit. The package currently models repository `1` with support for:

- `type`
- `path`
- `s3.bucket`
- `s3.endpoint`
- `s3.region`
- `s3.key`
- `s3.secret`
- `s3.uri_style`
- `tls.verify`
- `tls.ca_file`
- `encryption.enabled`
- `encryption.cipher_type`
- `encryption.passphrase`

## Operations

The operation catalog includes:

- `pgbackrest_backup_full`
- `pgbackrest_backup_diff`
- `pgbackrest_backup_incr`
- `pgbackrest_restore`
- `pgbackrest_verify`
- `pgbackrest_check`
- `pgbackrest_info`

Use this driver when repository-aware backup, restore, verify, and info flows are part of the production posture.
