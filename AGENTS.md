# Laravel Checkpoint — AI Agent & Contributor Guidelines

## Mission

Make backup, restore, PITR, replication, and recovery-drill operations **safe, auditable, and automation-friendly** for Laravel applications. Defaults must be trustworthy in production. Positioning: **"Database Reliability Layer"** — not a backup tool, a business continuity system.

---

## Philosophy

1. **Safety before convenience** — destructive ops (restore, replication apply) remain guarded at all times.
2. **Evidence over assumption** — "backup succeeded" is not enough; keep restore verification and drill evidence visible.
3. **Operator + agent dual UX** — human CLI stays concise, triage-first with `--brief`. `--agent` output stays deterministic, parseable, and versioned.
4. **Additive contracts** — evolve JSON payloads by adding fields, never removing or mutating existing keys.
5. **Frictionless install** — `composer require` + `php artisan checkpoint:install` gets you running. No DDEV, no Docker prerequisites.
6. **Pro/Free clean split** — advanced features (PITR, drills, replication) gate behind `class_exists(ProServiceProvider::class)`. Never break open-source users.

---

## Architecture

### Directory layout
```
src/
  Actions/           → single-responsibility action classes (EnqueueCommandRunAction)
  Console/           → Artisan commands (one class per command, final)
  Contracts/         → interfaces (BackupDriver, ReplicationEndpointParser)
  Drivers/           → backup driver implementations (Mysql, PgDump, PgBackRest, Shell, Postgres, Fake)
  Enums/             → native PHP 8.1 backed enums (CommandRunStatus, ReplicationEngine)
  Events/            → final readonly classes with constructor promotion
  Exceptions/        → package-specific exceptions extending RuntimeException
  Jobs/              → queue jobs (ProcessCommandRunJob)
  Models/            → Eloquent models with modern casts() syntax
  Services/          → domain logic services
  Support/           → formatting, payload building, event mapping utilities
  ValueObjects/      → readonly DTOs (ReplicationEndpoint, ReplicationRequest)
config/
  checkpoint.php     → single config file, 375 lines max, DB_OPS_* env prefix
tests/
  Feature/           → end-to-end Artisan command tests
  Unit/              → isolated class tests
  Fixtures/          → deterministic JSON snapshots (time frozen)
  Pest.php           → shared helpers (checkpoint_artisan, checkpoint_fixture_path)
  TestCase.php       → extends Orchestra Testbench, uses SQLite :memory:
```

### Layer rules
- **Commands** are the entry point. They validate input, delegate to services/actions, format output. No business logic.
- **Services** contain domain logic. They receive dependencies via constructor DI (interfaces, not facades).
- **Actions** are single-purpose orchestrators (enqueue, validate, build). One `execute()` method pattern.
- **Drivers** implement `BackupDriver` (one method: `execute(CommandRun $run): void`). Config-driven resolution via `config('checkpoint.drivers.{name}.class')`.
- **Models** own their persistence. Atomic state transitions (`claimPendingExecution`, `markAsSucceeded`) use `whereKey()->where()->update()` for race-condition safety.

---

## Code Conventions

### Mandatory
```php
declare(strict_types=1);                          // every file
final class ClassName                             // every class (except intentional seams)
final readonly class ClassName                    // services, actions, events, value objects
private readonly Type $dependency,                 // constructor promotion
public function __construct(...) {}                // no body when promotion covers all
public function handle(): int                     // commands return 0 (SUCCESS) or 1 (FAILURE)
```

### PHP 8.3+ features to use
- `readonly` on all properties
- Native enums (`enum Status: string { case Pending = 'pending'; }`)
- `match()` expressions, never long `switch`/`if-elseif` chains
- First-class callables: `$this->validator->validate(...)`
- `str_starts_with`, `str_contains`, `str()` helper
- `json_validate()` for input checking

### Strictly avoid
- **No comments** in source files (code should be self-documenting). PHPDoc only for `@param`/`@return` on public methods.
- **No facades** in services/actions. Use constructor DI. `config()` and `app()` helpers only in service providers and commands.
- **No error suppression** (`@file_put_contents`, `@fopen`, `@unlink`). Check return values.
- **No `catch (Throwable)` without logging**. Always `report($e)` or at minimum `logger()->error(...)`.
- **No `config()->set()` inside validators**. Validation reports, never mutates.
- **No `(new Model)->method()`**. Resolve from container or receive via DI.
- **No methods over 50 lines**. Extract private methods or separate classes.
- **No shell string concatenation**. All process commands use Symfony Process with array args.

---

## CLI Command Standards

### Naming
- All commands use `checkpoint:*` prefix. No `do:`, `check:`, `admin:` namespaces.
- Public surface: `install`, `doctor`, `status`, `report`, `enqueue`, `enqueue-backup`, `enqueue-drill`, `replicate`, `migrate-from-spatie`, `health-check`, `pitr-readiness`, `prune`, `recover-orphans`, `retention-policy`, `catalog-export`, `record-drill`, `test`.

### Structure
```php
final class ExampleCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:example {--format=table}';
    protected $description = 'One-line description.';

    public function __construct(
        private readonly SomeService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            // validation → logic → output
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->promptError($exception->getMessage());
            return self::FAILURE;
        }
    }
}
```

### Output modes
- `table` — human-readable, uses `$this->promptTable()` from `UsesLaravelPrompts`
- `json` — full JSON envelope via `CommandJsonContract`
- `agent` — AI-agent-friendly compact JSON
- `compact-json` — null-stripped JSON

### Shared utilities (add to UsesLaravelPrompts trait, NOT copy-paste)
- `stringOption(string $name): ?string`
- `policyProfileOverride(): ?string`
- `machineGateDecision(array $gateDecision): array`
- `shouldCollapsePassingChecks(): bool`
- `orderedChecksForDisplay(array $checks): array`
- `overallSloStatus(array $checks, int $failedCount, int $warnCount): array`
- Translation fallback helper: `translatedOr(string $key, string $default): string`

---

## Config Conventions

### Env naming
- All env vars use `DB_OPS_*` prefix
- Dot-notation config keys: `checkpoint.queue.name`, `checkpoint.drivers.mysql.binary`
- Every config key has a safe default. No `env()` without a second argument.

### Timeout chain (simplified)
```php
$timeoutBase = (int) env('DB_OPS_TIMEOUT', 3600);
// queue.timeout = $timeoutBase
// queue.retry_after = $timeoutBase + 60
// queue.unique_for = $timeoutBase + 60
```
Individual env vars (`DB_OPS_QUEUE_TIMEOUT`, etc.) override specific keys.

### Gate profiles
- `local`, `staging`, `production` profiles with safety/evidence gate rules
- Auto-detected from `app()->environment()` unless `--policy-profile=` overrides
- Exit codes: pass=0, warn=2, safety_fail=10, evidence_fail=11, policy_error=12

---

## Testing Standards

### Framework
- **Pest** only. No PHPUnit-style `test_*` methods. Use `it()` and `expect()`.
- `uses(TestCase::class)` declared in `tests/Pest.php`
- Arch tests in `tests/ArchTest.php`: enforce `strict_types`, `final`, no `dd`/`dump`, contracts are interfaces, drivers implement `BackupDriver`, Jobs implement `ShouldQueue`.

### Test helpers
```php
checkpoint_artisan('checkpoint:doctor --format=json')  // runs command
checkpoint_fixture_path('doctor.json')                  // resolves fixture
checkpoint_assert_matches_fixture($payload, $fixture)   // compares to snapshot
```

### Coverage requirements
- Every command gets: happy path, failure path, edge case (empty state, missing binary, invalid input)
- Every driver gets: execute success, execute failure, metadata correctness
- Every service gets: unit tests for logic, integration tests for side effects
- Every new config key gets: ConfigValidator validation test
- Fixtures: deterministic (time frozen), committed alongside tests

### What NOT to test
- Framework internals (Laravel's queue dispatcher, scheduler)
- Third-party binary output (pg_dump, mysqldump)
- UI rendering (use FakeDriver for command execution)

---

## Driver Contract

### Interface
```php
interface BackupDriver
{
    public function execute(CommandRun $run): void;
}
```

### Driver requirements
1. Claim the run atomically (`claimPendingExecution`)
2. Compute planned metadata before execution
3. Run `RestoreSafetyGuard->evaluate()` for destructive operations
4. Build Symfony Process argv array (never shell strings)
5. Capture stdout/stderr via `CommandOutputStore`
6. Mark run as succeeded/failed with exit code
7. Dispatch appropriate events (BackupCompleted, BackupFailed)
8. Persist structured metadata for audit trail

### Adding a custom driver
1. Implement `BackupDriver`
2. Register class in `config/checkpoint.php` under `drivers.{name}.class`
3. Add binary validation to `ConfigValidator`
4. Add health checks to `OperationalReportBuilder::activeDriverBinaryChecks()`

---

## Known Anti-Patterns (from audit — NEVER do these)

| Anti-pattern | Example | Fix |
|-------------|---------|-----|
| Config coupling | `Repository $config` injected then `$config->get()` scattered | Pass typed value objects/DTOs |
| Copy-paste utility methods | `stringOption()` in 6 commands | Put in `UsesLaravelPrompts` trait |
| Swallowed exceptions | `catch (Throwable) {}` with no logging | `report($e)` or `logger()->error()` |
| Config mutation in validators | `config()->set(...)` inside `ConfigValidator` | Validation reports; caller fixes |
| Monolith classes | 1,930-line `OperationalReportBuilder` | Extract: HealthCheckComposer, BreakdownAggregator, DrillTrendAnalyzer |
| Global helpers in services | `app()->environment()` in GatePolicyEvaluator | Pass environment via constructor |
| Model instantiation | `(new CommandRun)->method()` | DI or `app()->make()` |
| Error suppression | `@file_put_contents()` | Check return value + log on failure |
| Duplicate binary lists | Same binary env/config keys in 3 files | Single source of truth in config |
| DSN regex duplication | Same DSN pattern in Parser + Redactor | Extract `DsnPattern` utility |

---

## JSON Output Contracts

### Envelope shape
```json
{
  "surface": "doctor|report|status",
  "version": 3,
  "ok": true,
  "driver": "mysql",
  "generated_at": "2026-01-01T00:00:00+00:00",
  "checks": [...],
  "gates": { "profile": "local", "verdict": "pass", "exit_code": 0 }
}
```

### Contract evolution rules
- **Add** new top-level keys: bump `version`
- **Add** fields inside existing objects: keep `version`, consumers ignore unknown fields
- **Never remove** a key without a new `surface` version
- **Never change** a field's type (string → int)
- Agent payloads get a `compact` block with `verdict`, `severity`, `top_issue`, `next_action`, `exit_code`

---

## Security Rules

1. **No hardcoded credentials** — ever. Everything through env vars or config.
2. **All shell commands use Symfony Process array args** — prevents injection.
3. **pgBackRest secrets** written to temp files with `chmod 0600`, cleaned in `finally`.
4. **DSN URLs** redacted before logging via `ReplicationSecretRedactor`.
5. **Command output** truncated/persisted via `CommandOutputStore`, not dumped to terminal.
6. **Restore confirmation** required for non-local environments (`DB_OPS_RESTORE_REQUIRE_CONFIRMATION`).
7. **Queue locks** prevent concurrent destructive operations (`ShouldBeUnique`).
8. **Temp files** cleaned in `finally` blocks. Accept that SIGKILL leaves orphans; document in ops guide.

---

## Quality Gates

### CI must include
```yaml
- PHP 8.3, 8.4, 8.5 × Laravel 12, 13 × prefer-stable, prefer-lowest (12 jobs)
- vendor/bin/pest --ci
- vendor/bin/phpstan analyse
- vendor/bin/pint --test
```

### Pre-commit
- `vendor/bin/pint` (auto-fix style)
- `vendor/bin/pest --stop-on-failure` (fast path)

### Pre-release
- Full test matrix passes
- PHPStan level max (or baseline documented)
- No `@` error suppression operators
- No `catch (Throwable) {}` without logging
- All `stringOption()` occurrences consolidated to trait
- No methods over 50 lines (except generated/migration code)

---

## Contribution Workflow

1. Pick an atomic task from `IMPLEMENTATION_PLAN_V2.md` or the audit report.
2. Create a feature branch: `feat/short-description` or `fix/short-description`.
3. Write the test first (Pest `it()` block).
4. Implement following architecture rules above.
5. Run `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`.
6. Commit using Conventional Commits: `feat(scope): description` / `fix(scope): description`.
7. Scopes: `core`, `cli`, `driver`, `config`, `test`, `docs`.

---

## Free vs Pro Boundary

### Open source (this repo)
- Backup, restore, prune, health checks, status, reporting
- Shell, pgdump, mysql drivers
- Basic scheduling and retention
- Migrate-from-spatie command

### Pro (private repo `laravel-checkpoint-pro`)
- PITR (WAL/binlog replay)
- Automated recovery drills
- Replication engine
- pgBackRest driver
- Blast radius and safety gates
- Tiered retention policies

### Integration pattern
```php
// In core service provider
if (class_exists(\AdityaaCodes\LaravelCheckpointPro\ProServiceProvider::class)) {
    // enable pro commands, drivers, gates
}
```

When the Pro package is installed, its `ProServiceProvider` bootstraps and injects pro-specific bindings, commands, and drivers. No core code references Pro classes directly.

---

## Laravel Boost Integration

Publish package guidelines for AI agents via Laravel Boost:

```blade
{{-- resources/boost/guidelines/laravel-checkpoint/core.blade.php --}}
Laravel Checkpoint — Database Reliability Layer for Laravel.
...
```

Package consumers run `php artisan boost:update --discover` to auto-include these guidelines in their agent's context.

---

## Reference

| Document | Purpose |
|----------|---------|
| `CHECKPOINT_V1_STRATEGY.md` | Product strategy, free/pro split, monetization |
| `IMPLEMENTATION_PLAN_V2.md` | 12-phase implementation roadmap with priorities |
| `CHECKPOINT_IMPROVEMENT_PLAN.md` | 6-initiative improvement plan (Pulse, streaming, etc.) |
| `impl.md` | MySQL implementation task breakdown |
| `reference.md` | MySQL/Laravel documentation references |
| `website/docs/` | User-facing documentation |
| [spatie/laravel-backup](https://github.com/spatie/laravel-backup) | Primary competitor (6k stars, 22M downloads) |
| [spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools) | Package foundation used by this project |
