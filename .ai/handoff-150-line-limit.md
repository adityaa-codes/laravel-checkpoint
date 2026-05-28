# Handoff: Enforce 150-Line File Limit

## Completed

12 new classes extracted, 6 source files reduced, 97 tests passing.

| Original File | Before | After | New Classes |
|---|---|---|---|
| `src/Drivers/PostgresDriver.php` | 367 | 294 | `PostgresMetadataEnricher` (93) |
| `src/Drivers/Postgres/PostgresReplicationOrchestrator.php` | 467 | 280 | `PostgresReplicationResultBuilder` (180) |
| `src/Services/RestoreSafetyGuard.php` | 564 | 186 | `BlastRadiusAssessor` (141), `RestoreVerificationSignalLocator` (201) |
| `src/Services/OperationalReportBuilder.php` | 665 | 348 | `RunPayloadFormatter` (127), `RunMetadataExtractor` (85) |
| `src/Console/StatusCommand.php` | 663 | 499 | `StatusSloBuilder` (108), `StatusSuggestionsCollector` (94) |
| `src/Drivers/MysqlDriver.php` | 1336 | 677 | `MysqlConfiguration` (198), `MysqlProcessBuilder` (202), `MysqlRestoreTargetValidator` (184), `MysqlMetadataBuilder` (170) |

## Remaining Files >150 Lines

```
677 src/Drivers/MysqlDriver.php
595 src/Models/CommandRun.php
499 src/Console/StatusCommand.php
489 src/Rendering/DoctorAgentRenderer.php
457 src/Console/MigrateFromSpatieCommand.php
434 src/Drivers/ShellCommandDriver.php
352 src/Drivers/Concerns/ExecutesReplicationSync.php
348 src/Services/OperationalReportBuilder.php
342 src/Rendering/DoctorTableRenderer.php
327 src/Services/CommandOutputStore.php
313 src/LaravelCheckpointServiceProvider.php
308 src/Services/GatePolicyEvaluator.php
294 src/Drivers/PostgresDriver.php
288 src/Console/PublishFullConfigCommand.php
280 src/Drivers/Postgres/PostgresReplicationOrchestrator.php
277 src/Services/ReplicationGovernanceEvaluator.php
277 src/Console/CatalogExportCommand.php
245 src/Services/ReplicationFailureSuggestionMapper.php
236 src/Actions/BuildBackupCatalogExportAction.php
235 src/Console/InstallCommand.php
230 src/Console/DoctorReportCommand.php
229 src/Actions/ComposeRestorePostureHealthChecksAction.php
224 src/Jobs/ProcessCommandRunJob.php
224 src/Actions/ComposeBinaryHealthChecksAction.php
223 src/Services/CommandRunCatalog.php
217 src/Console/SweepCommand.php
210 src/Services/CommandJsonContract.php
202 src/Drivers/MysqlProcessBuilder.php
201 src/Services/RestoreVerificationSignalLocator.php
199 src/Actions/ComposeBackupDrillHealthChecksAction.php
199 src/Actions/BuildDrillRemediationPlaybookAction.php
198 src/Drivers/MysqlConfiguration.php
186 src/Services/RestoreSafetyGuard.php
184 src/Drivers/MysqlRestoreTargetValidator.php
180 src/Drivers/Postgres/PostgresReplicationResultBuilder.php
176 src/Drivers/Postgres/PostgresDriverConfig.php
171 src/Rendering/StatusAgentRenderer.php
170 src/Drivers/MysqlMetadataBuilder.php
167 src/Actions/BuildPitrReadinessReportAction.php
164 src/Drivers/Postgres/PostgresRestoreTargetResolver.php
162 src/Rendering/Concerns/FormatsHealthData.php
161 src/Console/ReplicateCommand.php
156 src/Services/BackupArtifactUploader.php
156 src/Console/CheckpointCommand.php
152 src/Actions/EvaluateRetentionPolicyAction.php
```

## Extraction Plans for Remaining Files (from earlier analysis)

### Priority 1 (>300 lines)

**MysqlDriver.php (677 → ~400):** Already partially extracted. Remaining: extract `execute()` sub-strategies into `MysqlExecutionStrategies`, merge `ExecutesReplicationSync` trait into `MysqlReplicationHandler`.

**CommandRun.php (595 → ~250):** Eloquent model. Use traits:
- `Concerns/ManagesState` — `markAsRunning`, `claimPendingExecution`, `markAsSucceeded`, `markAsFailed`, `timingMetrics`
- `Concerns/ManagesHeartbeat` — `recordHeartbeat`, `recordHeartbeatIfDue`, `claimForOrphanRecovery`, `releaseOrphanRecoveryClaim`
- `Concerns/ManagesMetadata` — `recordMetadata`, `resolvedDriverName`, `denormalizedMetadataColumns`
- `Concerns/HasRestoreAudit` — `restoreAuditSummary`, `restorePostVerificationSummary`
- `Concerns/Prunable` — `prunable`, `pruneAll`
- `Concerns/RecordsVerification` — `persistVerificationOutcome`, `resolvedVerificationErrorDetail`

**StatusCommand.php (499 → ~200):** Extract `StatusJsonRenderer` (6 methods), `StatusTableRenderer` (5 methods), `StatusWatchPoller` (2 methods).

**DoctorAgentRenderer.php (489):** Extract `DoctorJsonRenderer`, `DoctorTableRenderer`.

**MigrateFromSpatieCommand.php (457):** Extract `MigrateConfigMapper`, `MigrateTableRenderer`.

**ShellCommandDriver.php (434):** Extract `ShellCommandConfig`, `ShellCommandProcessBuilder`.

**ExecutesReplicationSync.php (352):** Fold into `MysqlReplicationHandler` (this is a trait, not a class — merge with MysqlDriver's replication methods).

### Priority 2 (150–300 lines)

Many of these are borderline. Only extract if clear boundaries exist:
- `OperationalReportBuilder.php` (348) — already reduced from 665, can extract `SnapshotCollector` (~80), `RestoreRunPayloadFormatter` (~110)
- `DoctorTableRenderer.php` (342) — extract alongside DoctorAgentRenderer
- `CommandOutputStore.php` (327) — likely single-responsibility already
- `LaravelCheckpointServiceProvider.php` (313) — extract `PostgresDriverWiring` (~60)
- `GatePolicyEvaluator.php` (308) — extract `ProfileResolver`, `CodeEvaluator`

## Conventions to Follow

1. **Invoke `laravel-package-development` skill** before writing any code.
2. **Constructor DI everywhere** — no facades in services/actions.
3. **Named exception constructors** — `ConfigurationException::mustNotBeEmpty('key')` not `new ConfigurationException('msg')`.
4. **No type guards** (`is_array`, `is_string`, `is_int`, etc.) — trust framework types, use null coalescing (`??`).
5. **No type casts** (`(string)`, `(int)`, `(bool)`) unless at true boundaries (config reads, DB queries).
6. **New classes in same namespace** as original file.
7. **Update service provider bindings** when adding constructor dependencies.
8. **Update tests** that directly construct refactored classes.
9. **Run all commands via DDEV**: `ddev exec vendor/bin/pint`, `ddev exec vendor/bin/phpstan analyse --memory-limit=512M`, `ddev exec vendor/bin/pest`.

## Verification Script

```bash
# Start DDEV if not running
ddev start

# Run all affected tests
ddev exec vendor/bin/pest tests/Unit/Postgres/ tests/Unit/PostgresDriverTest.php tests/Unit/MysqlDriverTest.php tests/Feature/OperationalReportBuilderTest.php tests/Feature/RestoreSafetyGuardTest.php tests/Feature/StatusCommandTest.php

# Run code style
ddev exec vendor/bin/pint

# Run static analysis (may need memory increase)
ddev exec vendor/bin/phpstan analyse --memory-limit=512M
```
