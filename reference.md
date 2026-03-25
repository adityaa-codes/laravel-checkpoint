# MySQL + Laravel Implementation Reference

## 1) Task Template (Atomic Work Unit)
Use this template for each implementation task in `impl.md` execution:

- **Task ID**: short unique id (e.g. `T04`)
- **Objective**: one-sentence outcome
- **Files touched**: explicit file list
- **Inputs / prerequisites**: config, classes, dependencies
- **Implementation steps**: 3-8 concrete steps
- **Acceptance criteria**: testable checks
- **Tests to add/update**: exact test files and scenarios
- **Risks**: what can break
- **Rollback/mitigation**: how to back out safely

## 2) Research Notes (MySQL Backup / PITR)

### Official MySQL references
- Backup and recovery overview:
  - https://dev.mysql.com/doc/refman/8.0/en/backup-and-recovery.html
- `mysqldump` reference:
  - https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html
- Using `mysqldump`:
  - https://dev.mysql.com/doc/refman/8.0/en/using-mysqldump.html
- PITR overview:
  - https://dev.mysql.com/doc/refman/8.0/en/point-in-time-recovery.html
- PITR with binlog:
  - https://dev.mysql.com/doc/refman/8.0/en/point-in-time-recovery-binlog.html
- `mysqlbinlog` backup + replay notes:
  - https://dev.mysql.com/doc/refman/8.0/en/mysqlbinlog-backup.html

### Key operational implications
- PITR depends on binary logging being enabled and accessible.
- Typical PITR flow: restore baseline backup, then replay binlogs up to a bounded target.
- `mysqldump` is useful for logical backups but can be slow at large scale; design should keep extension points for physical/enterprise backup tooling.

## 3) Laravel Standards References (Package + Operations)

### Package and config standards
- Package development:
  - https://laravel.com/docs/13.x/packages
- Configuration and env safety:
  - https://laravel.com/docs/13.x/configuration

### Runtime standards used by this package
- Queues:
  - https://laravel.com/docs/13.x/queues
- Scheduling:
  - https://laravel.com/docs/13.x/scheduling
- Testing:
  - https://laravel.com/docs/13.x/testing
- Error handling:
  - https://laravel.com/docs/13.x/errors

## 4) Guidelines for MySQL Feature Work

- Keep operation names stable; swap implementation by driver.
- Build commands as argv arrays (Symfony Process), not shell-concatenated strings.
- Route all restore operations through `RestoreSafetyGuard`.
- Persist structured metadata for auditability (artifact path, replay bounds, verified signal linkage).
- Keep output capture consistent with existing drivers.
- Ensure secret redaction for CLI arguments and logged command lines.
- Fail fast on invalid config with precise error messages.
- Keep compatibility with queue uniqueness and scheduler overlap protections.

## 5) Do / Don’t

### Do
- Do enforce explicit validation for MySQL config keys.
- Do require bounded PITR targets (`datetime` and/or position constraints).
- Do test both success and unsafe/misconfigured paths.
- Do document production preconditions (binlog retention, privileges, restore target isolation).
- Do preserve existing event and model semantics for command runs.

### Don’t
- Don’t bypass restore guardrails for convenience.
- Don’t add broad exception swallowing that hides command failures.
- Don’t log raw credentials, connection URIs with passwords, or secret flags.
- Don’t depend on shell redirection when Process argv/file-based APIs can avoid it.
- Don’t couple MySQL behavior to PostgreSQL-only metadata assumptions.

## 6) Common Mistakes to Avoid

- Treating PITR as “run mysqlbinlog” without ensuring a known baseline restore point.
- Replaying binlogs without strict stop boundary.
- Assuming path inputs are safe (missing traversal/symlink defenses).
- Marking runs succeeded when command output indicates partial failure.
- Forgetting to update doctor/report checks after adding new required config.
- Updating docs after code lands instead of in the same change.
- Omitting tests for lock/queue uniqueness behavior under new driver mode.

## 7) Planning/Execution Checklist

- [ ] Config schema and env keys finalized
- [ ] Validator coverage for every mysql key
- [ ] MysqlDriver integrated and selectable
- [ ] Backup/restore/latest/pitr/drill operation parity implemented
- [ ] Restore guard + verification behavior validated
- [ ] Doctor/report observability updated
- [ ] Unit + feature tests updated
- [ ] README operator docs updated
- [ ] Full test suite green

