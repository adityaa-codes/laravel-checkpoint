# Laravel Checkpoint Restore Guardrails

Read this file only when working on restore posture or restore diagnosis.

## Restore operations

- `logical_restore_latest`
- `logical_restore_file`
- `pitr_restore`
- `pgbackrest_restore`

## Guard sequence

1. Environment allowlist
2. Database allowlist
3. Confirmation requirement or CI bypass handling
4. Restore target validation
5. Verified-backup or provenance signal lookup

## Key expectations

- Non-local environments should keep verified-backup enforcement enabled.
- Confirmation should remain required unless the task is deliberately reducing safety in a non-production context.
- PITR must use a valid datetime target and matching backup-chain context.
- Restore decisions should remain auditable through restore decision events and run metadata.

## Files to inspect when behavior changes

- `src/Services/RestoreSafetyGuard.php`
- `src/Services/ConfigValidator.php`
- `tests/Feature/RestoreSafetyGuardTest.php`
- `README.md`
