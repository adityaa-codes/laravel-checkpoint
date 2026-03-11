# Implementation Plan: Laravel DB Operations Packages

## Problem Statement

Extract the PITR/backup/recovery system from the `Modules/Operations` module in
`route-cost-api` into **two standalone, environment-agnostic Composer packages**:

1. **`laravel-checkpoint`** — Core package (no Filament dependency). Provides
   models, migrations, enums, actions, catalog service, artisan commands, a
   pluggable driver layer, queue-based execution, events, and operational safety.

2. **`laravel-checkpoint-filament`** — Filament v5 plugin. Provides
   Filament Panel resources, tables, infolists, and a fluent Plugin class. Depends
   on the core package.

---

## Resolved Design Decisions

| # | Question | Decision |
|---|---|---|
| 1 | Queue name | **Dedicated `db-ops` queue.** `ProcessCommandRunJob` defaults to `db-ops`; overridable via `DB_OPS_QUEUE_NAME`. |
| 2 | MySQL support | **Driver-open.** `BackupDriver` contract has zero DB-engine assumptions. `ShellCommandDriver` ships with Postgres-oriented defaults but all command templates are overridable via env vars for MySQL, etc. |
| 3 | Filament resource registration | **Auto-register.** Plugin's `register(Panel $panel)` calls `$panel->resources([...])`. No manual resource registration needed. `->hideCommandRuns()` / `->hideDrillRuns()` for conditional exclusion. |
| 4 | BackupDrillRun creation | **Dedicated artisan command** `db-ops:record-drill`. External scripts (CI, drill shell script) pipe results in via this command. Fires `BackupDrillCompleted` event. |

---

## LaravelCheckpoint Templates

- **Core package**: `spatie/package-laravel-checkpoint-laravel`
  - Requires: `php ^8.4`, `illuminate/contracts ^11.0|^12.0`, `spatie/laravel-package-tools ^1.16`, `symfony/process ^7.0`
  - Dev: Pest 4, Larastan 3, Pint, Orchestra Testbench 10, Rector 2, `infection/infection`
- **Filament plugin**: `filamentphp/plugin-laravel-checkpoint`
  - Requires: `filament/filament ^5.0`, core package `^1.0`
  - Dev: additionally `pestphp/pest-plugin-livewire ^4`

---

## Package 1: `laravel-checkpoint`

### Package Identity
```
vendor:    your-vendor
package:   laravel-checkpoint
namespace: YourVendor\Checkpoint
```

### Directory Structure
```
laravel-checkpoint/
├── .github/
│   ├── dependabot.yml                    # Weekly composer + actions updates
│   └── workflows/
│       ├── run-tests.yml                 # Pest + composer audit
│       ├── fix-php-code-style.yml        # Pint auto-fix
│       └── release.yml                   # release-please automation
├── config/
│   └── checkpoint.php
├── database/
│   ├── factories/
│   │   ├── CommandRunFactory.php
│   │   └── BackupDrillRunFactory.php
│   └── migrations/
│       ├── create_checkpoint_command_runs_table.php
│       └── create_checkpoint_backup_drill_runs_table.php
├── lang/
│   └── en/
│       └── messages.php                  # All translatable strings
├── src/
│   ├── Contracts/
│   │   └── BackupDriver.php              # PUBLIC API: interface execute(CommandRun): void
│   ├── Drivers/
│   │   ├── ShellCommandDriver.php        # @internal: Symfony Process, array args only
│   │   └── FakeDriver.php                # @internal: for testing
│   ├── Enums/
│   │   └── CommandRunStatus.php          # PUBLIC API
│   ├── Events/                           # PUBLIC API: all events
│   │   ├── BackupQueued.php
│   │   ├── BackupStarted.php
│   │   ├── BackupCompleted.php
│   │   ├── BackupFailed.php
│   │   └── BackupDrillCompleted.php
│   ├── Exceptions/
│   │   ├── InvalidOperationException.php
│   │   ├── InvalidArgumentException.php
│   │   └── ConfigurationException.php    # Thrown by ConfigValidator at boot
│   ├── Models/
│   │   ├── CommandRun.php                # PUBLIC API
│   │   └── BackupDrillRun.php            # PUBLIC API
│   ├── Actions/
│   │   └── EnqueueCommandRunAction.php   # PUBLIC API
│   ├── Services/
│   │   ├── CommandRunCatalog.php         # PUBLIC API
│   │   └── ConfigValidator.php           # @internal
│   ├── Jobs/
│   │   └── ProcessCommandRunJob.php      # @internal
│   ├── Console/
│   │   ├── EnqueueLogicalBackupCommand.php
│   │   ├── EnqueueCommand.php
│   │   ├── StatusCommand.php
│   │   ├── RecordDrillRunCommand.php
│   │   ├── RecoverOrphansCommand.php     # Re-dispatches stuck Pending runs
│   │   ├── HealthCheckCommand.php        # Marks timed-out Running runs as Failed
│   │   ├── PruneCommand.php              # Prunes old CommandRun records
│   │   └── DoctorCommand.php             # Validates config + binary availability
│   ├── Testing/
│   │   └── InteractsWithCheckpoint.php # PUBLIC API
│   ├── Facades/
│   │   └── Checkpoint.php
│   └── CheckpointServiceProvider.php
├── tests/
│   ├── ArchTest.php                      # Pest arch: no App\ refs, contracts are interfaces, etc.
│   ├── Pest.php
│   ├── TestCase.php
│   ├── Feature/
│   │   ├── EnqueueCommandRunActionTest.php
│   │   ├── CommandRunCatalogTest.php
│   │   ├── EnqueueLogicalBackupCommandTest.php
│   │   ├── ProcessCommandRunJobTest.php
│   │   ├── BackupDrillRunTest.php
│   │   ├── CommandRunModelTest.php
│   │   ├── RecoverOrphansCommandTest.php
│   │   ├── HealthCheckCommandTest.php
│   │   ├── PruneCommandTest.php
│   │   └── DoctorCommandTest.php
│   └── Unit/
│       └── ShellCommandDriverTest.php    # Command-building only, no real exec
├── workbench/
│   └── app/
│       └── Providers/TestbenchServiceProvider.php
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE.md
├── README.md
├── SECURITY.md
├── UPGRADING.md
├── composer.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── phpstan-baseline.neon
├── pint.json
└── rector.php
```

---

### Key File Specifications

#### `config/checkpoint.php`
```php
return [
    'user_model'       => env('DB_OPS_USER_MODEL', \App\Models\User::class),
    'user_name_column' => 'name',
    'table_prefix'     => 'db_ops_',

    'queue' => [
        'connection'   => env('DB_OPS_QUEUE_CONNECTION', null),
        'name'         => env('DB_OPS_QUEUE_NAME', 'db-ops'),    // dedicated queue
        'max_attempts' => 1,      // override per job; destructive ops always 1
        'retry_after'  => 90,
        'timeout'      => 3600,   // seconds before a Running run is considered orphaned
    ],

    'schedule' => [
        'logical_backup_enabled'  => env('DB_OPS_BACKUP_SCHEDULE_ENABLED', true),
        'logical_backup_daily_at' => env('DB_OPS_BACKUP_DAILY_AT', '16:00'),
        'logical_backup_timezone' => env('DB_OPS_BACKUP_TIMEZONE', 'UTC'),
        'health_check_enabled'    => env('DB_OPS_HEALTH_CHECK_ENABLED', true),
        'prune_enabled'           => env('DB_OPS_PRUNE_ENABLED', true),
        'prune_keep_days'         => env('DB_OPS_PRUNE_KEEP_DAYS', 90),
        'prune_keep_failed_days'  => env('DB_OPS_PRUNE_KEEP_FAILED_DAYS', 365),
    ],

    'driver'  => env('DB_OPS_DRIVER', 'shell'),
    'drivers' => [
        'shell' => [
            'class'   => \YourVendor\Checkpoint\Drivers\ShellCommandDriver::class,
            // Each value is an array of argv tokens, NOT a shell string.
            // Placeholders: {db}, {stanza}, {target}, {output}, {file}, {backup_dir}
            'commands' => [
                'logical_backup'         => env('DB_OPS_CMD_LOGICAL_BACKUP', ''),
                'logical_restore_latest' => env('DB_OPS_CMD_RESTORE_LATEST', ''),
                'logical_restore_file'   => env('DB_OPS_CMD_RESTORE_FILE', ''),
                'pitr_restore'           => env('DB_OPS_CMD_PITR_RESTORE', ''),
                'backup_drill'           => env('DB_OPS_CMD_BACKUP_DRILL', ''),
                'pgbackrest_check'       => env('DB_OPS_CMD_PGBACKREST_CHECK', ''),
                'pgbackrest_info'        => env('DB_OPS_CMD_PGBACKREST_INFO', ''),
            ],
            'pgbackrest_stanza'       => env('DB_OPS_PGBACKREST_STANZA', 'main'),
            'backup_dir'              => env('DB_OPS_BACKUP_DIR', storage_path('db-backups')),
            'backup_prefix'           => env('DB_OPS_BACKUP_PREFIX', 'backup'),
            'pre_restore_snapshot'    => env('DB_OPS_PRE_RESTORE_SNAPSHOT', true),
            'command_timeout_seconds' => env('DB_OPS_CMD_TIMEOUT', 7200),
        ],
    ],

    'log_channel'       => env('DB_OPS_LOG_CHANNEL', 'stack'),
    'custom_operations' => [],
];
```

---

#### `src/Contracts/BackupDriver.php` — PUBLIC API
```php
interface BackupDriver
{
    /**
     * Execute the operation described by $run.
     * Responsible for:
     *   - Marking $run as Running (calling $run->markAsRunning())
     *   - Performing the operation
     *   - Writing command_line, command_output, exit_code, started_at, finished_at
     *   - Marking $run as Succeeded or Failed
     *   - Firing BackupStarted, BackupCompleted/BackupFailed events
     */
    public function execute(CommandRun $run): void;
}
```

---

#### `src/Drivers/ShellCommandDriver.php` — @internal — SECURITY CRITICAL

**Must use `Symfony\Component\Process\Process` with an argument array, never a shell string.**

```
WRONG:  proc_open("pgbackrest --target=" . $argument, ...)
WRONG:  shell_exec('pgbackrest --target=' . escapeshellarg($argument))
RIGHT:  new Process(['pgbackrest', '--stanza=main', '--target=' . $argument])
        // Symfony Process with array — OS exec(), no shell interpretation
```

Flow per operation:
1. Look up command template from config
2. Parse template string into argv array by splitting on whitespace
3. Substitute placeholders into the correct argv positions
4. Construct `new Process($argv, workdir: null, env: null, input: null, timeout: config timeout)`
5. `$run->markAsRunning()` → fire `BackupStarted`
6. `$process->run()` — capture stdout+stderr merged
7. Write `command_line` = `$process->getCommandLine()` (sanitized), `command_output`, `exit_code`, `finished_at`
8. On `exitCode === 0` → `$run->markAsSucceeded()` → fire `BackupCompleted`
9. On `exitCode !== 0` → `$run->markAsFailed()` → fire `BackupFailed`
10. Log at INFO for start/finish, ERROR for failures

**Pre-restore safety snapshot** (when `pre_restore_snapshot: true`):
- For operations: `logical_restore_latest`, `logical_restore_file`, `pitr_restore`
- Before executing: create a new `CommandRun` for `logical_backup` and execute it synchronously
- If snapshot exits non-zero: mark current run as `Failed` with message "Pre-restore snapshot failed"
- Log at ERROR with full snapshot output
- Never proceed to restore if snapshot failed

---

#### `src/Jobs/ProcessCommandRunJob.php` — @internal

```php
class ProcessCommandRunJob implements ShouldQueue, ShouldBeUnique
{
    // ShouldBeUnique per run ID (prevents duplicate processing of same record)
    public function uniqueId(): string
    {
        // For exclusive ops: also lock the operation type globally
        return $this->catalog->isDestructive($this->run->operation)
            ? 'db-ops-exclusive:' . $this->run->operation
            : 'db-ops-run:' . $this->run->id;
    }

    public function tries(): int
    {
        // Destructive operations MUST NOT auto-retry — hardcoded regardless of config
        return $this->catalog->isDestructive($this->run->operation)
            ? 1
            : (int) config('checkpoint.queue.max_attempts', 1);
    }

    public function handle(BackupDriver $driver): void { ... }

    public function failed(\Throwable $e): void
    {
        $this->run->markAsFailed();
        event(new BackupFailed($this->run, -1, $e->getMessage(), $e));
        Log::channel(config('checkpoint.log_channel'))
            ->error('ProcessCommandRunJob failed', ['run_id' => $this->run->id, 'error' => $e->getMessage()]);
    }
}
```

**Destructive operations** (returned by `CommandRunCatalog::isDestructive()`):
- `logical_restore_latest`
- `logical_restore_file`
- `pitr_restore`
- `backup_drill`

**Exclusive-lock operations** (only one may run at a time via `uniqueId`):
All destructive ops plus `logical_backup` (prevent concurrent dumps).
Non-exclusive: `pgbackrest_info`, `pgbackrest_check`.

---

#### `src/Actions/EnqueueCommandRunAction.php` — PUBLIC API

Atomicity via `afterCommit`:
```php
public function execute(string $operation, ?string $argument, ?Model $requestedBy = null): CommandRun
{
    $normalizedArgument = $this->catalog->validate($operation, $argument);

    $run = DB::transaction(function () use ($operation, $normalizedArgument, $requestedBy) {
        return CommandRun::create([...]);
    });

    // Dispatch only after DB commit — safe if queue broker is down at this point
    ProcessCommandRunJob::dispatch($run)
        ->onQueue(config('checkpoint.queue.name'))
        ->afterCommit();

    event(new BackupQueued($run));

    return $run;
}
```

---

#### `src/Models/CommandRun.php` — PUBLIC API

- `MassPrunable` trait: `prunable()` returns runs older than `prune_keep_days` (non-failed) or `prune_keep_failed_days` (failed)
- Polymorphic `requestedBy()` via `nullableMorphs('requested_by')`
- Scopes: `scopePending()`, `scopeRunning()`, `scopeSucceeded()`, `scopeFailed()`, `scopeTerminal()`
- Helpers: `markAsRunning()`, `markAsSucceeded(int $exitCode, string $output)`, `markAsFailed(int $exitCode = -1, string $output = '')`

---

#### `src/Services/CommandRunCatalog.php` — PUBLIC API

New methods:
- `isDestructive(string $operation): bool` — returns true for restore/drill ops
- `isExclusive(string $operation): bool` — returns true for ops that cannot run concurrently
- `extend(string $operation, array $definition): void` — runtime extension
- Config-merged `custom_operations` at construction

---

#### `src/Services/ConfigValidator.php` — @internal

Called from `packageBooted()` in non-production (`app()->environment() !== 'production'`).
Checks:
1. `driver` key exists in `drivers` config
2. Driver `class` exists and implements `BackupDriver`
3. `log_channel` is a valid channel
4. `user_model` class exists
5. `table_prefix` is a non-empty string

Throws `ConfigurationException` with specific actionable message per failure.

---

#### `src/Console/DoctorCommand.php`

`artisan db-ops:doctor` — prints a health table:
```
+------------------------------+--------+--------------------------------------------------+
| Check                        | Status | Notes                                            |
+------------------------------+--------+--------------------------------------------------+
| Config: driver               | PASS   | shell                                            |
| Config: queue.name           | PASS   | db-ops                                           |
| Config: log_channel          | PASS   | stack                                            |
| Binary: pg_dump              | PASS   | /usr/bin/pg_dump (PostgreSQL 18.0)               |
| Binary: pgbackrest           | PASS   | /usr/bin/pgbackrest 2.50                         |
| Binary: gzip                 | PASS   | /bin/gzip 1.12                                   |
| DB: command_runs table       | PASS   | 142 rows                                         |
| DB: backup_drill_runs table  | PASS   | 12 rows                                          |
| Queue: db-ops                | WARN   | Cannot verify queue without running worker       |
| Orphaned runs                | PASS   | 0 runs stuck in Running state                    |
+------------------------------+--------+--------------------------------------------------+
```

---

#### `src/Console/RecoverOrphansCommand.php`

`artisan db-ops:recover-orphans`

- Finds `CommandRun` records with `status = Pending` AND `created_at < now() - threshold` AND no in-progress job (approximated by checking Laravel's jobs table for matching payload)
- Re-dispatches `ProcessCommandRunJob` for each
- Logs each re-dispatch at WARNING level
- Scheduled every 10 minutes by service provider

---

#### `src/Console/HealthCheckCommand.php`

`artisan db-ops:health-check`

- Finds `CommandRun` records with `status = Running` AND `started_at < now() - config(queue.timeout)`
- Marks each as `Failed` with `command_output = 'Timed out by health check'`
- Fires `BackupFailed` for each
- Logs each at ERROR level
- Scheduled every 5 minutes by service provider

---

#### `src/Console/PruneCommand.php`

`artisan db-ops:prune`

- Calls `CommandRun::pruneAll()` (MassPrunable)
- Reports count of pruned records
- Scheduled weekly
- Does NOT prune `BackupDrillRun` (these are audit records — kept indefinitely unless explicitly configured)

---

#### `tests/ArchTest.php`
```php
arch('src has no App\ references')
    ->expect('YourVendor\Checkpoint')
    ->not->toUse('App\\');

arch('Contracts contain only interfaces')
    ->expect('YourVendor\Checkpoint\Contracts')
    ->toBeInterfaces();

arch('Events are readonly classes')
    ->expect('YourVendor\Checkpoint\Events')
    ->toBeReadonly();

arch('Jobs implement ShouldQueue')
    ->expect('YourVendor\Checkpoint\Jobs')
    ->toImplement(\Illuminate\Contracts\Queue\ShouldQueue::class);

arch('Drivers implement BackupDriver')
    ->expect('YourVendor\Checkpoint\Drivers')
    ->toImplement(\YourVendor\Checkpoint\Contracts\BackupDriver::class);
```

---

#### `lang/en/messages.php` — Translation inventory

All 30 strings needing translation:

```php
// Operation labels (used in Filament UI + CLI)
'operations.logical_backup'         => 'Logical Backup',
'operations.logical_restore_latest' => 'Logical Restore (Latest)',
'operations.logical_restore_file'   => 'Logical Restore (Specific File)',
'operations.pitr_restore'           => 'PITR Restore',
'operations.backup_drill'           => 'Backup Drill',
'operations.pgbackrest_check'       => 'pgBackRest Check',
'operations.pgbackrest_info'        => 'pgBackRest Info',

// Status labels (Filament UI)
'status.pending'    => 'Pending',
'status.running'    => 'Running',
'status.succeeded'  => 'Succeeded',
'status.failed'     => 'Failed',
'status.cancelled'  => 'Cancelled',

// Validation errors (CLI + action exceptions)
'errors.invalid_operation'      => 'Unsupported operation: :operation',
'errors.argument_required'      => 'Operation :operation requires an argument.',
'errors.invalid_argument'       => 'Invalid argument for :operation. Expected: :hint',
'errors.config_driver_missing'  => 'Driver ":driver" is not defined in checkpoint.drivers config.',
'errors.config_class_missing'   => 'Driver class :class does not exist.',
'errors.config_log_missing'     => 'Log channel ":channel" is not configured.',
'errors.pre_restore_failed'     => 'Pre-restore snapshot failed. Restore aborted.',
'errors.operation_exclusive'    => 'Operation :operation is already running. Only one instance allowed at a time.',

// CLI output (artisan commands only, not translated in Filament)
'cli.backup_queued'         => 'Queued :operation run #:id.',
'cli.orphan_redispatched'   => 'Re-dispatched orphaned run #:id.',
'cli.health_check_failed'   => 'Marked run #:id as failed (timed out after :seconds seconds).',
'cli.pruned'                => 'Pruned :count command run records.',
'cli.doctor_pass'           => 'PASS',
'cli.doctor_warn'           => 'WARN',
'cli.doctor_fail'           => 'FAIL',
'cli.drill_recorded'        => 'Recorded backup drill run :uuid (overall: :result).',
```

---

#### `SECURITY.md`

```markdown
## Security Policy

### Supported Versions
| Version | Supported |
|---------|-----------|
| 1.x     | ✅ Yes     |

### Reporting a Vulnerability
Please do NOT open a public GitHub issue for security vulnerabilities.

Report via GitHub's private advisory feature:
https://github.com/your-vendor/laravel-checkpoint/security/advisories/new

We will acknowledge within 48 hours and provide a fix timeline within 7 days.
```

---

#### `CONTRIBUTING.md` highlights

- Branch naming: `feat/`, `fix/`, `chore/`
- Commit messages: Conventional Commits (`feat:`, `fix:`, `chore:`, `docs:`)
- All PRs: must pass `composer test`, `composer analyse`, `composer test:lint`
- New operations: must add to `CommandRunCatalog`, add translation key, add test
- Driver changes: must not break `BackupDriver` interface signature
- `@internal` classes: can change in minor versions
- Public API classes: require major version bump for breaking changes

---

## Package 2: `laravel-checkpoint-filament`

### Package Identity
```
vendor:    your-vendor
package:   laravel-checkpoint-filament
namespace: YourVendor\CheckpointFilament
```

### Directory Structure
```
laravel-checkpoint-filament/
├── .github/
│   ├── dependabot.yml
│   └── workflows/
│       ├── run-tests.yml
│       ├── fix-php-code-style.yml
│       └── release.yml
├── bin/
│   └── build-assets
├── config/
│   └── checkpoint-filament.php
├── resources/
│   ├── css/index.css
│   ├── dist/
│   └── views/
├── src/
│   ├── CheckpointFilamentPlugin.php
│   ├── CheckpointFilamentServiceProvider.php
│   ├── CheckpointFilamentTheme.php
│   ├── Resources/
│   │   ├── CommandRunResource.php
│   │   │   ├── Pages/
│   │   │   │   ├── ListCommandRuns.php     # 30s polling, stat widgets
│   │   │   │   └── ViewCommandRun.php
│   │   │   ├── Tables/
│   │   │   │   └── CommandRunsTable.php    # all actions + bulk cancel
│   │   │   └── Infolists/
│   │   │       └── CommandRunInfolist.php
│   │   └── BackupDrillRunResource.php
│   │       ├── Pages/
│   │       │   ├── ListBackupDrillRuns.php  # nav badge
│   │       │   └── ViewBackupDrillRun.php
│   │       ├── Tables/
│   │       │   └── BackupDrillRunsTable.php
│   │       └── Infolists/
│   │           └── BackupDrillRunInfolist.php
│   ├── Notifications/
│   │   └── CommandRunCompletedNotification.php
│   └── Testing/
│       └── InteractsWithCheckpointFilament.php
├── tests/
│   ├── ArchTest.php
│   ├── Pest.php
│   ├── TestCase.php
│   └── Feature/
│       ├── CommandRunResourceTest.php
│       └── BackupDrillRunResourceTest.php
├── stubs/
├── workbench/
│   └── app/
│       └── Providers/
│           └── PanelProvider.php      # registers plugin for testbench
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE.md
├── README.md
├── SECURITY.md
├── UPGRADING.md
├── composer.json
├── package.json
├── phpunit.xml.dist
├── phpstan.neon.dist
├── pint.json
└── rector.php
```

---

### Key File Specifications

#### `src/CheckpointFilamentPlugin.php` — Auto-registration pattern

```php
class CheckpointFilamentPlugin implements Plugin
{
    protected string $navigationGroup = 'System';
    protected int    $commandRunsSort = 70;
    protected int    $drillRunsSort   = 80;
    protected bool   $commandRunsHidden = false;
    protected bool   $drillRunsHidden   = false;
    protected bool   $notifyOnCompletion = true;

    public static function make(): static { return app(static::class); }
    public static function get(): static  { return filament(app(static::class)->getId()); }
    public function getId(): string        { return 'checkpoint'; }

    // Resources auto-register when plugin is added to panel:
    public function register(Panel $panel): void
    {
        $resources = [];
        if (! $this->commandRunsHidden) $resources[] = CommandRunResource::class;
        if (! $this->drillRunsHidden)   $resources[] = BackupDrillRunResource::class;
        $panel->resources($resources);
    }

    public function boot(Panel $panel): void {}

    // Resource classes read group/sort via CheckpointFilamentPlugin::get():
    // CommandRunResource::getNavigationGroup() → CheckpointFilamentPlugin::get()->getNavigationGroup()

    // Fluent API:
    public function navigationGroup(string $group): static;
    public function commandRunsNavigationSort(int $sort): static;
    public function drillRunsNavigationSort(int $sort): static;
    public function hideCommandRuns(): static;
    public function hideDrillRuns(): static;
    public function notifyOnCompletion(bool $notify = true): static;
    public function getNavigationGroup(): string;
    public function getCommandRunsSort(): int;
    public function getDrillRunsSort(): int;
    public function shouldNotifyOnCompletion(): bool;
}
```

Usage in panel provider:
```php
->plugins([
    CheckpointFilamentPlugin::make()
        ->navigationGroup('System')
        ->notifyOnCompletion(),
])
```

---

#### `config/checkpoint-filament.php`
```php
return [
    'navigation_group'              => 'System',
    'command_runs_navigation_sort'  => 70,
    'drill_runs_navigation_sort'    => 80,
    'notify_on_completion'          => true,
];
```

---

#### Authorization in `CommandRunsTable`

Each queue header action must be gated:
```php
HeaderAction::make('queueBackup')
    ->visible(fn () => auth()->user()?->can('create', CommandRun::class))
    ->action(fn () => ...)
```

Actions are **hidden** (not just disabled) when user lacks permission —
consistent with Filament's principle of not revealing unavailable features.

---

#### Multi-tenancy

`CommandRun` and `BackupDrillRun` are **global records** — they do NOT scope
to Filament tenants. Backup operations affect the entire database, not a tenant.

In the README: explicitly document that these resources must NOT be registered
on a tenant-aware panel without understanding the implications. Provide an example
of disabling tenant scoping if needed.

---

#### Tailwind CSS (v5 compliance)

Per Filament v5 docs: **do NOT compile Tailwind inside the plugin**.

Provide raw Blade views only. Users add to their theme CSS:
```css
@source '../../../../vendor/your-vendor/laravel-checkpoint-filament/resources/views/**/*';
```

Only register a compiled CSS file if absolutely required for non-Tailwind styles.

---

#### Workbench test app spec

`workbench/app/Providers/PanelProvider.php`:
```php
->plugins([CheckpointFilamentPlugin::make()])
->authGuard('web')
->tenant(null)  // no tenancy in tests
```

`workbench/app/Models/User.php`: basic authenticatable + `FilamentUser` interface
SQLite in-memory DB for all tests
`TestCase.php` extends `Orchestra\Testbench\TestCase`, boots both service providers

---

## Cross-Cutting: Security Model

### Shell Injection Prevention (highest priority)

`ShellCommandDriver` **must**:
1. Use `Symfony\Component\Process\Process` with an **array constructor** only
2. Never pass user-controlled values through a shell string
3. Validate all argument values through `CommandRunCatalog::validate()` before they reach the driver
4. Log the sanitized command line (`$process->getCommandLine()`) for audit — never the raw template

Example for PITR:
```php
// Config template: "pgbackrest --stanza={stanza} --target={target} --type=time restore"
// Parsed into: ['pgbackrest', '--stanza={stanza}', '--target={target}', '--type=time', 'restore']
// Substituted:  ['pgbackrest', '--stanza=main', '--target=2026-02-15T15:30:00Z', '--type=time', 'restore']
// Passed to:    new Process(['pgbackrest', '--stanza=main', '--target=2026-02-15T15:30:00Z', ...])
// OS exec():    execve('/usr/bin/pgbackrest', [...])  — NO shell, NO injection possible
```

### Authorization Layers

1. **Policy level**: `CommandRunPolicy::create()` — controls who can queue operations
2. **Filament action level**: `->visible(fn() => auth()->user()?->can('create', CommandRun::class))`
3. **No HTTP API** in v1 — queue operations are admin-only via Filament or artisan

---

## Cross-Cutting: Observability

### Logging

All log calls use `Log::channel(config('checkpoint.log_channel'))`.

| Event | Level | Context |
|---|---|---|
| Job dispatched | INFO | `{run_id, operation, argument, queued_by}` |
| Driver started | INFO | `{run_id, command_line}` |
| Command output | DEBUG | `{run_id, output}` |
| Job succeeded | INFO | `{run_id, exit_code, duration_seconds}` |
| Job failed | ERROR | `{run_id, exit_code, error_message, output}` |
| Orphan recovered | WARNING | `{run_id, stuck_duration_seconds}` |
| Health check timeout | ERROR | `{run_id, started_at, timeout_seconds}` |
| Pre-restore snapshot | INFO | `{run_id, snapshot_run_id}` |
| Pre-restore failed | ERROR | `{run_id, snapshot_output}` |

---

## Cross-Cutting: Data Integrity

### Outbox Pattern (afterCommit)

```
DB transaction: CREATE CommandRun
→ COMMIT
→ ProcessCommandRunJob::dispatch()->afterCommit()
```

If dispatch fails after commit: the `RecoverOrphansCommand` (scheduled every 10 min)
re-dispatches any `Pending` run older than `queue.orphan_threshold` (default: 10 min).

### No Concurrent Destructive Operations

`ProcessCommandRunJob::uniqueId()` returns `db-ops-exclusive:{operation}` for destructive
ops. Laravel's `ShouldBeUnique` prevents a second PITR restore from being processed while
one is already in-flight. The second job stays in queue until the first releases its lock.

---

## Cross-Cutting: API Surface Contract

### Public API (semver protected — breaking changes require major bump)
- `BackupDriver` interface
- `EnqueueCommandRunAction::execute()`
- `CommandRunCatalog` public methods
- All `Events\*` classes (constructor signatures)
- `CommandRun` model public methods and scopes
- `BackupDrillRun` model public methods and scopes
- `CommandRunStatus` enum cases
- `InteractsWithCheckpoint` trait methods
- All config keys in `config/checkpoint.php`
- All artisan command signatures

### @internal (may change in minor versions without notice)
- `ShellCommandDriver`
- `FakeDriver`
- `ProcessCommandRunJob`
- `ConfigValidator`
- All `Console\*` command `handle()` internals

---

## Migration Strategy (from `Modules/Operations`)

1. Remove `Operations` from `modules_statuses.json`
2. `composer require your-vendor/laravel-checkpoint your-vendor/laravel-checkpoint-filament`
3. **Do NOT re-run migrations** if tables exist. Instead, write a one-time migration to rename `operations_command_runs` → `db_ops_command_runs` and `operations_backup_drill_runs` → `db_ops_backup_drill_runs`, and alter `requested_by_id` (int FK) → `requested_by_type` + `requested_by_id` (morphs)
4. `php artisan vendor:publish --tag="checkpoint-config"`
5. Set all `DB_OPS_CMD_*` env vars to replace DDEV commands
6. Remove old Filament resource registrations from `AppServiceProvider`/panel provider
7. Delete `Modules/Operations/` directory
8. Run `artisan db-ops:doctor` to verify setup

---

## Phase Plan

| Phase | Focus | Key Outputs |
|---|---|---|
| 1 | Core scaffold | Composer setup, GitHub Actions, Dependabot, tooling |
| 2 | Domain models | Migrations (polymorphic), Models (MassPrunable), Enum, Factories |
| 3 | Service layer | Catalog (isDestructive/isExclusive), Events, Exceptions |
| 4 | Driver layer | BackupDriver contract, ShellCommandDriver (Symfony Process), FakeDriver |
| 5 | Jobs + commands | ProcessCommandRunJob (mutex, force tries), all 8 artisan commands |
| 6 | Provider + config | ServiceProvider, ConfigValidator, Facade, config file |
| 7 | Testing helpers | InteractsWithCheckpoint trait, ArchTest |
| 8 | Tests (core) | 10 feature test files, 1 unit test file |
| 9 | Documentation (core) | README, SECURITY.md, CONTRIBUTING.md, UPGRADING.md, CHANGELOG |
| 10 | Filament scaffold | Plugin laravel-checkpoint, Plugin class, ServiceProvider |
| 11 | Filament resources | Both resources with tables, infolists, pages, notifications |
| 12 | Filament tests | 2 feature test files, ArchTest |
| 13 | Documentation (plugin) | README, SECURITY.md, CONTRIBUTING.md |
| 14 | Release prep | Tagging v1.0.0, Packagist registration, release-please setup |

### Current Audit Snapshot

Audit date: **2026-03-11**

Status legend:
- `[x]` complete
- `[-]` partial or placeholder exists, but not to plan
- `[ ]` not started

Current totals for the **original 86 tasks**:
- `[x]` 7 complete
- `[-]` 5 partial
- `[ ]` 74 not started

Audit summary:
- The repository has the Spatie package skeleton in place, so the initial scaffold exists.
- Core implementation work has not started yet: no real models, enums, drivers, jobs, events, policies, commands, or planned tests are present.
- Several files exist only as placeholders and are marked `[-]` rather than `[x]`, including `composer.json`, `config/checkpoint.php`, the service provider, facade, architecture test, README, CHANGELOG, and CI/tooling files.
- The Filament plugin package has not been scaffolded in this repository yet.

---

## Task List (Original 86 — see Full Task List below for all 122)

> **86 original tasks** across both packages (Groups A–G improvements listed separately below).
> Status key: `[x]` complete, `[-]` partial, `[ ]` pending. Dependencies shown as prerequisite task IDs.

---

### Package 1: Core (`laravel-checkpoint`)

#### Phase 1 — Scaffold & Tooling

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [x] | C01 | Scaffold core package | Clone spatie/package-laravel-checkpoint-laravel, run configure.php, set vendor=your-vendor package=laravel-checkpoint namespace=YourVendor\Checkpoint | — |
| [x] | C02 | composer.json (core) | PHP ^8.4, illuminate/contracts ^11\|^12, spatie/laravel-package-tools ^1.16, symfony/process ^7.0. Dev: pest 4, larastan 3, pint, testbench 10, rector 2, infection/infection | — |
| [x] | C03 | GitHub Actions (core) | run-tests.yml: PHP 8.4+8.5 × Laravel 11+12 matrix + `composer audit` step. fix-php-code-style.yml with pint. | — |
| [x] | C04 | Tooling config (core) | phpstan.neon.dist level 8, pint.json preset laravel, rector.php | — |
| [x] | C51 | Dependabot config | .github/dependabot.yml for composer + github-actions, weekly, group minor/patch | C01 |
| [x] | C52 | Composer audit in CI | Add `composer audit` step to run-tests.yml; fails build on known vulnerabilities | C03 |
| [x] | C54 | Release automation | .github/workflows/release.yml using release-please-action. Commitlint workflow for PR titles. | C01 |

#### Phase 2 — Models & Migrations

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | C05 | Migration: command_runs | `db_ops_command_runs`: polymorphic `nullableMorphs('requested_by')`, all columns: operation, argument_text, status, timestamps, command_line, command_output, exit_code, attempts | C01 |
| [ ] | C06 | Migration: backup_drill_runs | `db_ops_backup_drill_runs`: run_uuid, marker_uuid, marker_email, marker_count, marker_result, rto/rpo fields, overall_result, executed_by, executed_at | C01 |
| [ ] | C07 | Model: CommandRun | MassPrunable, config-driven table, polymorphic requestedBy(), scopes: pending/running/succeeded/failed/terminal, helpers: markAsRunning/Succeeded/Failed | C05 |
| [ ] | C08 | Model: BackupDrillRun | Config-driven table, isPassing():bool, scopeLatest() | C06 |
| [ ] | C09 | Enum: CommandRunStatus | Cases: Pending/Running/Succeeded/Failed/Cancelled + isTerminal():bool | — |
| [ ] | C10 | Factory: CommandRunFactory | States: pending, running, succeeded, failed, cancelled with realistic timestamps | C07 |
| [ ] | C11 | Factory: BackupDrillRunFactory | States: passing, failing with realistic RTO/RPO values | C08 |

#### Phase 3 — Service Layer

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | C12 | Exceptions | InvalidOperationException, InvalidArgumentException, ConfigurationException — all extend RuntimeException | — |
| [ ] | C13 | CommandRunCatalog | All 7 built-in operations, extend(), isDestructive(), isExclusive(), custom_operations config merge, translatable hints | C12 |
| [ ] | C14 | Event: BackupQueued | readonly class, carries CommandRun $run | — |
| [ ] | C15 | Event: BackupStarted | readonly class, carries CommandRun $run | — |
| [ ] | C16 | Event: BackupCompleted | readonly class, carries CommandRun $run, int $exitCode, string $output | — |
| [ ] | C17 | Event: BackupFailed | readonly class, carries CommandRun $run, int $exitCode, string $output, Throwable $e | — |
| [ ] | C58 | Translation strings | lang/en/messages.php with all 30 strings: 7 operation labels, 5 status labels, 8 error messages, 10 CLI strings | C13 |

#### Phase 4 — Driver Layer

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | C18 | BackupDriver contract | Interface: execute(CommandRun): void. Docblock specifies full responsibility contract. | — |
| [ ] | C19 | ShellCommandDriver | **Symfony Process with array args only** (never shell strings). Placeholder substitution into argv array. Pre-restore snapshot for *_restore/pitr_restore ops. Configurable timeout. Structured logging. Fires all events. | C18 |
| [ ] | C41 | ShellCommandDriver: Symfony Process | Verify implementation uses Process(['cmd','--arg']) not shell strings. Write unit test proving no shell interpretation. | C19 |
| [ ] | C20 | FakeDriver | Records all calls, configurable per-operation fake outcomes (succeed/fail/throw), for testing | C18 |

#### Phase 5 — Jobs & Console Commands

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | C21 | EnqueueCommandRunAction | Validate via catalog → DB::transaction(create CommandRun) → dispatch()->afterCommit() → fire BackupQueued | C13, C14, C15, C16, C17 |
| [ ] | C22 | ProcessCommandRunJob | ShouldQueue + ShouldBeUnique. uniqueId() exclusive for destructive ops. tries() forced to 1 for destructive ops. failed() marks run + fires BackupFailed + logs ERROR. | C19, C21 |
| [ ] | C43 | Destructive op: force max_attempts=1 | tries() checks catalog.isDestructive(); logs WARNING if config attempts > 1 for destructive op | C22, C13 |
| [ ] | C47 | Queue mutex per operation type | uniqueId() returns 'db-ops-exclusive:{operation}' for destructive+backup ops; 'db-ops-run:{id}' for info/check ops | C22, C13 |
| [ ] | C23 | EnqueueLogicalBackupCommand | artisan db-ops:enqueue-backup, success/failure output, uses EnqueueCommandRunAction | C21 |
| [ ] | C24 | EnqueueCommand (generic) | artisan db-ops:enqueue {operation} {--argument=}, interactive select if omitted | C21 |
| [ ] | C25 | StatusCommand | artisan db-ops:status {--limit=10}, table output with colored status | C07 |
| [ ] | C25b | RecordDrillRunCommand | artisan db-ops:record-drill with all --options. Creates BackupDrillRun, fires BackupDrillCompleted. | C08, C28 |
| [ ] | C45 | HealthCheckCommand | artisan db-ops:health-check. Marks Running runs as Failed if started_at older than queue.timeout. Logs ERROR per recovery. Scheduled every 5 min. | C07, C28 |
| [ ] | C42 | RecoverOrphansCommand | artisan db-ops:recover-orphans. Re-dispatches Pending runs with no active job older than orphan_threshold (10 min). Logs WARNING per re-dispatch. Scheduled every 10 min. | C21, C22 |
| [ ] | C46 | PruneCommand | artisan db-ops:prune. Calls CommandRun::pruneAll() (MassPrunable). Reports pruned count. Scheduled weekly. | C07, C28 |
| [ ] | C49 | DoctorCommand + ConfigValidator | artisan db-ops:doctor prints health table. ConfigValidator checks driver/log/user_model/table_prefix at boot in non-production. Throws ConfigurationException with actionable messages. | C29, C28 |

#### Phase 6 — Provider, Config & Testing Helpers

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | C26 | BackupDrillRunPolicy | viewAny, view only. No create/edit/delete. | — |
| [ ] | C27 | CommandRunPolicy | viewAny, view, create (canQueue). | — |
| [-] | C28 | CheckpointServiceProvider | spatie/laravel-package-tools: config, migrations, translations, all commands, scheduling (backup+health+orphans+prune), policy registration, driver binding | C13, C22, C26, C27, C29 |
| [-] | C29 | config/checkpoint.php | All settings documented. Commands as env-var-driven strings (parsed to argv by driver). queue.timeout, orphan_threshold, prune settings, log_channel. | — |
| [-] | C30 | Facade: Checkpoint | Points to EnqueueCommandRunAction::execute() | — |
| [ ] | C31 | InteractsWithCheckpoint testing trait | fakeDriver(), assertBackupQueued(op, arg?), assertBackupNotQueued(op), assertNoBackupsQueued(), assertBackupFailed(op) | — |
| [ ] | C50 | Public API surface + @internal | Add @internal to ShellCommandDriver, FakeDriver, ProcessCommandRunJob, ConfigValidator. Document public API in README Extending section. | C28 |

#### Phase 7 — Architecture Tests

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [-] | C53 | Architecture tests (Pest arch) | tests/ArchTest.php: no App\ refs, Contracts are interfaces, Events are readonly, Jobs implement ShouldQueue, Drivers implement BackupDriver | C01 |

#### Phase 8 — Feature/Unit Tests

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | C32 | Test: CommandRunCatalogTest | validate, extend, isDestructive, isExclusive, custom_operations merge, exception cases | C13 |
| [ ] | C33 | Test: EnqueueCommandRunActionTest | creates CommandRun, dispatches job afterCommit, fires BackupQueued, argument validation, DB transaction | C21 |
| [ ] | C34 | Test: ProcessCommandRunJobTest | driver called, status transitions, events fired, tries()=1 for destructive, failed() callback | C22, C43, C47 |
| [ ] | C35 | Test: EnqueueLogicalBackupCommandTest | schedule timing, artisan exit codes | C23 |
| [ ] | C36 | Test: ShellCommandDriverTest (unit) | argv array construction, placeholder substitution, no shell strings, pre-restore snapshot trigger | C41 |
| [ ] | C37 | Test: BackupDrillRunTest | scopes, isPassing(), factory states | C08 |
| [ ] | C38 | Test: CommandRunModelTest | scopes, markAs* helpers, MassPrunable, polymorphic relation | C07 |
| [ ] | C44 | Test: Pre-restore snapshot | ShellCommandDriver aborts restore if snapshot fails; proceeds if snapshot passes | C44 |
| [ ] | C45t | Test: HealthCheckCommandTest | marks timed-out runs as Failed, fires BackupFailed, logs ERROR | C45 |
| [ ] | C42t | Test: RecoverOrphansCommandTest | re-dispatches Pending runs beyond threshold, logs WARNING | C42 |
| [ ] | C46t | Test: PruneCommandTest | prunes runs older than keep_days; retains failed runs per keep_failed_days | C46 |
| [ ] | C49t | Test: DoctorCommandTest | all checks rendered in table; ConfigurationException thrown on bad config in non-prod | C49 |

#### Phase 9 — Documentation (Core)

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [-] | C39 | README (core) | Install, publish config+migrations, env vars reference, usage, driver customization (MySQL example), extending catalog, public API section, testing section | — |
| [-] | C40 | CHANGELOG stub (core) | v1.0.0 with all initial features | — |
| [ ] | C55 | SECURITY.md (core) | Supported versions, private advisory link, 48h SLA | C01 |
| [ ] | C56 | CONTRIBUTING.md (core) | PR process, commit format, test requirements, how to add operations | C01 |
| [ ] | C57 | UPGRADING.md (core) | v1.0.0 baseline, migration from Modules/Operations | C01 |

---

### Package 2: Filament Plugin (`laravel-checkpoint-filament`)

#### Phase 10 — Scaffold

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | F01 | Scaffold filament plugin | Clone filamentphp/plugin-laravel-checkpoint, configure vendor/package/namespace | C40 |
| [ ] | F02 | composer.json (filament) | filament/filament ^5.0, core ^1.0. Dev: pest 4, pest-plugin-livewire ^4, testbench 10 | — |
| [ ] | F03 | GitHub Actions (filament) | Same matrix as core + Livewire pest plugin + composer audit | — |
| [ ] | F27 | Workbench test app spec | workbench/app/Providers/PanelProvider.php registers plugin. TestUser + SQLite in-memory. FilamentUser interface impl. | F01 |

#### Phase 11 — Plugin Class & Resources

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | F04 | CheckpointFilamentPlugin | register() calls $panel->resources() conditionally. Fluent API: navigationGroup, sort, hide*, notifyOnCompletion. get() static accessor. | F01 |
| [ ] | F05 | CheckpointFilamentServiceProvider | Registers plugin views, config, FilamentAsset | F01 |
| [ ] | F06 | CheckpointFilamentTheme | Theme CSS per plugin-laravel-checkpoint pattern | F01 |
| [ ] | F07 | config/checkpoint-filament.php | navigation_group, sort values, notify_on_completion | — |
| [ ] | F08 | CommandRunResource | Nav: icon heroicon-o-cog-6-tooth, group+sort via Plugin::get() | F04 |
| [ ] | F09 | CommandRunsTable | Columns (all), filters (operation/status/date range), header actions for all 7 ops with modal inputs, all actions gated by CommandRunPolicy::create | F08, F26 |
| [ ] | F10 | CommandRunInfolist | All fields, command_output as code block (font-mono), command_line entry | F08 |
| [ ] | F11 | ListCommandRuns page | 30s Livewire polling, header stat widgets (Pending/Running/Succeeded/Failed counts) | F09 |
| [ ] | F12 | ViewCommandRun page | Read-only infolist | F10 |
| [ ] | F13 | BackupDrillRunResource | Nav badge: latest overall_result, PASS=success/FAIL=danger, icon heroicon-o-lifebuoy, group+sort via Plugin::get() | F04 |
| [ ] | F14 | BackupDrillRunsTable | All columns, filter by overall_result | F13 |
| [ ] | F15 | BackupDrillRunInfolist | Sections: Summary, Marker, RTO, RPO | F13 |
| [ ] | F16 | ListBackupDrillRuns page | Navigation badge showing latest result | F14 |
| [ ] | F17 | ViewBackupDrillRun page | Read-only infolist | F15 |
| [ ] | F18 | Bulk cancel action | BulkAction: set status=Cancelled for selected Pending/Running. Gated by policy. | F09 |
| [ ] | F19 | CommandRunCompletedNotification | Filament DatabaseNotification on BackupCompleted+BackupFailed. Sent to requester if not system user. | F04 |
| [ ] | F20 | InteractsWithCheckpointFilament | Testing trait: actingAsFilamentUser(), assertFilamentNotificationSent() | — |
| [ ] | F25 | Multi-tenancy docs | README section: CommandRun/BackupDrillRun are global (not tenant-scoped). Example for opting out. | F23 |
| [ ] | F26 | Authorization for queue actions | All header actions use visible(fn() => auth()->user()->can('create', CommandRun::class)) | F09 |

#### Phase 12 — Tests (Filament)

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | F21 | Test: CommandRunResourceTest | list renders, view renders, all 7 queue actions, argument validation, bulk cancel, auth gates | F09, F10 |
| [ ] | F22 | Test: BackupDrillRunResourceTest | list renders, view renders, nav badge PASS=green/FAIL=red, filter | F14, F15 |

#### Phase 13 — Documentation (Filament)

| Status | ID | Title | Description | Depends On |
|---|---|---|---|---|
| [ ] | F23 | README (filament plugin) | Install, register in panel, Tailwind @source instruction, fluent options, notification setup, multi-tenancy note | — |
| [ ] | F24 | CHANGELOG stub (filament) | v1.0.0 | — |
| [ ] | F25b | SECURITY.md (filament) | Same policy as core, references core advisory channel | F01 |
| [ ] | F23b | CONTRIBUTING.md (filament) | Same as core, adds Filament-specific: no compiled Tailwind, Livewire test requirements | F01 |

---

### Task Counts

| Package | Tasks |
|---|---|
| Core (`laravel-checkpoint`) | 59 |
| Filament (`laravel-checkpoint-filament`) | 27 |
| Improvement Group A (Plug & Play UX) | 5 |
| Improvement Group B (PHP 8.4+ Standards) | 7 |
| Improvement Group C (Testing Completeness) | 7 |
| Improvement Group D (Extendability) | 5 |
| Improvement Group E (Notifications) | 3 |
| Improvement Group F (Filament UX) | 4 |
| Improvement Group G (Robustness) | 5 |
| **Total** | **122** |

---

## Filament v5 Auto-Registration: How It Works

Per Filament v5 docs (`docs/09-advanced/05-modular-architecture.md`), the pattern for
auto-registering resources from a plugin is via `register(Panel $panel)`:

```php
public function register(Panel $panel): void
{
    $resources = [];
    if (! $this->commandRunsHidden) $resources[] = CommandRunResource::class;
    if (! $this->drillRunsHidden)   $resources[] = BackupDrillRunResource::class;
    $panel->resources($resources);
}
```

No manual `->resources([...])` needed in the user's panel provider.

---

## MySQL / Database-Agnostic Driver Notes

The `BackupDriver` contract has **zero database engine assumptions**.

For MySQL, override command templates in `.env`:
```
DB_OPS_CMD_LOGICAL_BACKUP="mysqldump --single-transaction {db} | gzip > {output}"
DB_OPS_CMD_PITR_RESTORE="mysqlbackup --target-dir={backup_dir} copy-back"
```

PITR for MySQL requires MySQL Enterprise Backup or Percona XtraBackup.
The package validates and orchestrates; it does not prescribe the binary.

A future `MysqlShellDriver` or `RdsSnapshotDriver` can implement `BackupDriver`
as a separate community package.

---

## Improvement Groups (Added v2 of Plan)

The following 36 improvements were added to make both packages production-grade, truly
plug-and-play, and highly extensible. They are organized into seven groups.

---

### Group A — Plug & Play UX

These improvements make the packages genuinely zero-friction out of the box.

| ID | Title | Description |
|---|---|---|
| A01 | Install wizard artisan command | `artisan db-ops:install` interactive wizard. Publishes config + migrations, prompts for driver commands interactively, writes env vars to `.env`, runs `db-ops:doctor` at end. Idempotent: skips already-published files. |
| A02 | `db-ops:list-backups` command | Lists backup files found in `backup_dir` sorted by mtime desc. Shows: filename, size (human-readable), SHA256 (if stored), age. Useful for ops without Filament UI. |
| A03 | `db-ops:replay {runId}` command | Re-enqueues an existing CommandRun with identical operation + argument. Prompts for confirmation for destructive ops. Prints new run ID. Useful for retrying failed backups from CLI. |
| A04 | Typed Facade methods | `Checkpoint::enqueue(string $op, ?string $arg = null): CommandRun`, `Checkpoint::status(?int $limit = 10): Collection<CommandRun>`, `Checkpoint::latestDrill(): ?BackupDrillRun`. Proper PHPDoc return types. IDE-friendly. |
| A05 | Sensible defaults (zero-config for Postgres) | `db-ops:install` detects `DB_CONNECTION=pgsql` and pre-fills `pg_dump` + `pgbackrest` command templates. Detects `DB_CONNECTION=mysql` and pre-fills `mysqldump` templates. No mandatory config changes for standard setups. |

---

### Group B — PHP 8.4+ Coding Standards

Every file in both packages must meet these standards. CI enforces them.

| ID | Title | Description |
|---|---|---|
| B01 | `strict_types=1` everywhere | `declare(strict_types=1)` on every PHP file. Enforced via PHPStan level 9 (upgrade from 8) and an architecture test. Zero exceptions. |
| B02 | `readonly` event classes | All 5 events (`BackupQueued`, `BackupStarted`, `BackupCompleted`, `BackupFailed`, `BackupDrillCompleted`) declared as `readonly class`. Constructor-promoted readonly properties only. Architecture test verifies: `expect('YourVendor\\Checkpoint\\Events')->toBeReadonly()`. |
| B03 | `#[Override]` on all interface implementations | All methods implementing an interface or overriding a parent (BackupDriver implementations, Policy methods, ServiceProvider hooks, Job methods) carry `#[Override]`. CONTRIBUTING.md documents this requirement. |
| B04 | `CommandRunStatus` enum: `label/color/icon/badge` | Four new methods on the enum: `label(): string` (translatable), `color(): string` (Filament color: `gray`/`warning`/`success`/`danger`/`info`), `icon(): string` (Heroicon name), `badge(): array{label: string, color: string, icon: string}`. Eliminates all `match` statements scattered across Filament views. |
| B05 | `never` return type + exception factory methods | Methods that always throw use `never` return type. Static factory methods on exceptions: `InvalidOperationException::forOperation(string $op): never`, `InvalidArgumentException::forArgument(string $op, string $hint): never`. |
| B06 | Fully typed — PHPStan level 9 | No `mixed`, no untyped properties, no implicit nullable params (must be `?Type`), no untyped arrays (use `array<string, mixed>` or generics). All `Collection` usages annotated with `@var Collection<int, CommandRun>`. phpstan-baseline.neon starts empty. |
| B07 | `OperationResult` readonly value object | `readonly class OperationResult { public function __construct(public readonly int $exitCode, public readonly string $output, public readonly float $durationSeconds, public readonly ?string $checksumSha256 = null) {} public function succeeded(): bool {} }`. Returned by `ShellCommandDriver::execute()` internally before writing to CommandRun. Testable independently. |

---

### Group C — Testing Completeness

Both packages target 95% line coverage and 85% mutation score.

| ID | Title | Description |
|---|---|---|
| CT1 | `BackupDriverContractTest` abstract class | Abstract Pest describe block (or abstract PHPUnit class) that any `BackupDriver` implementation must pass. Verifies: `execute()` marks run Running then Succeeded/Failed, fires `BackupStarted` + `BackupCompleted`/`BackupFailed`, sets `started_at`/`finished_at`/`exit_code`. Third-party driver authors extend this to verify compliance. |
| CT2 | Pest datasets for argument validation matrix | Use `dataset()` for all 7 operations × valid/invalid argument combinations. One parameterized test replaces 20+ individual tests. Covers: missing required arg, wrong format, valid arg, extra whitespace. |
| CT3 | Mutation testing: `infection.json` config | Configure `infection/infection`: `minMsi=85`, `minCoveredMsi=90`, target `src/` only. Weekly CI step (not on every push). Baseline stored in `infection-baseline.json`. README documents `composer infection` to run locally. |
| CT4 | Coverage threshold in CI | `run-tests.yml`: add `--coverage --min=95` to Pest invocation. Generate Clover XML, upload to Codecov. Coverage badge in README. CI fails if below threshold. |
| CT5 | Missing test files | Add: `StatusCommandTest` (table output format, `--limit`), `EnqueueCommandTest` (all 7 ops, interactive prompt, invalid op), `RecordDrillRunCommandTest` (creates `BackupDrillRun`, fires event, validation), `ReplayCommandTest` (re-enqueues, confirms prompt for destructive). |
| CT6 | `FakeDriver` full assertion API | `FakeDriver`: `shouldSucceedFor(string $op)`, `shouldFailFor(string $op, int $exitCode = 1)`, `shouldThrowFor(string $op, Throwable $e)`. Assertions (available via trait): `assertExecutedOperation(string $op)`, `assertExecutedOperationTimes(string $op, int $n)`, `assertNothingExecuted()`. |
| CT7 | Architecture tests: strict no-App references | `tests/ArchTest.php`: verify no class in `src/` references `App\`, verify `Events/` are `readonly`, verify `Contracts/` are interfaces only, verify `Jobs/` implement `ShouldQueue`, verify `Drivers/` implement `BackupDriver`, verify `Exceptions/` extend `RuntimeException`. |

---

### Group D — Extendability

The packages must be extensible without forking.

| ID | Title | Description |
|---|---|---|
| D01 | `OperationMiddleware` interface + Pipeline | `interface OperationMiddleware { public function handle(CommandRun $run, Closure $next): void; }`. Registered in config: `checkpoint.middleware = []`. Executed via `app(Pipeline::class)->send($run)->through($middleware)->thenReturn()` in `ProcessCommandRunJob` before `driver->execute()`. Ships two built-in middlewares: `LoggingMiddleware` (logs start/finish at INFO, on by default), `RateLimitMiddleware` (optional). |
| D02 | `ArgumentValidator` interface | `interface ArgumentValidator { public function validate(string $operation, string $argument): bool; public function errorMessage(): string; }`. Registered per-operation in catalog definition via `argument_validator => MyValidator::class`. Falls back to regex pattern if not specified. Ships three built-in validators: `RegexValidator` (wraps existing), `PastTimestampValidator` (for PITR: rejects future timestamps), `BackupFileExistsValidator` (for restore-from-file: checks file in `backup_dir`). |
| D03 | Driver routing per operation | Config: `checkpoint.operation_drivers = ['pitr_restore' => 'custom-driver']`. `DriverRouter` service resolves the correct bound driver name for each operation. Falls back to `checkpoint.driver` if not mapped. Allows routing `pitr_restore` to a dedicated `PgBackrestDriver` while other ops use `ShellCommandDriver`. |
| D04 | Facade macro support | The underlying class uses `Macroable` trait. `Checkpoint::macro('ecsRestart', fn() => Checkpoint::enqueue('ecs_restart', null))`. `Checkpoint::extend('custom_op', $definition)` as shorthand for `CommandRunCatalog::extend()`. Full IDE support via `/** @method static */` stubs on Facade. |
| D05 | Custom Filament actions hook on plugin | `Plugin::make()->additionalCommandRunActions([MyCustomAction::make()])`. Plugin accepts array of additional `HeaderAction` instances merged into `CommandRunsTable`. Allows app-specific operations in Filament UI without forking. Documented with an example of a custom "Snapshot EBS" action. |

---

### Group E — Notifications

| ID | Title | Description |
|---|---|---|
| E01 | `BackupNotification` standard Laravel Notification | `class BackupNotification extends Notification`. Channels configurable: `mail`, `slack`, `discord`, `database`. Triggered by `BackupCompleted` + `BackupFailed` events via registered listener `SendBackupNotification`. Shipped disabled by default; enabled via `checkpoint.notifications.enabled = true`. Separate from Filament DB notification. |
| E02 | Configurable notifiable targets + channels | Config: `checkpoint.notifications.channels = ['database']`, `notifications.notifiable_ids = []` (specific user IDs to notify), `notifications.notifiable_model = null` (class resolving notifiable). Default: notify the requesting user only. `BackupNotification::toMail()`, `toSlack()`, and `toDiscord()` all implemented. |
| E03 | Webhook callback on completion | Config: `checkpoint.notifications.webhook_url = env('DB_OPS_WEBHOOK_URL')`. On `BackupCompleted`/`BackupFailed`: HTTP POST JSON `{run_id, operation, status, exit_code, duration_seconds, finished_at, checksum_sha256}` via `Http::post()`. Request signed with `HMAC-SHA256` using `DB_OPS_WEBHOOK_SECRET`. Retried once on 5xx. Logged at `INFO`. Documented for use with Zapier, n8n, PagerDuty, etc. |

---

### Group F — Filament UX

| ID | Title | Description |
|---|---|---|
| FA1 | `BackupStatusWidget` dashboard widget | Filament `Widget` class. Shows: last successful backup (time ago + operation), last drill result (PASS/FAIL badge + date), queue depth (Pending count), Running indicator (spinner). Registered via `Plugin::make()->withDashboardWidget()`. Uses Livewire polling (60s). Users add to their panel's `$widgets` array or via plugin auto-registration. |
| FA2 | Bulk retry action for failed runs | `BulkAction` on `CommandRunsTable`: "Retry Selected". For each selected `Failed`/`Cancelled` run: calls `EnqueueCommandRunAction` with same operation + argument. Shows notification with count of re-enqueued runs. Gated by `CommandRunPolicy::create`. |
| FA3 | Relative timestamps in all table columns | All timestamp columns (`requested_at`, `started_at`, `finished_at`, `executed_at`) use `->since()` or `->dateTimeTooltip()` in Filament tables. Shows "3 hours ago" with full timestamp on hover. Improves scannability at a glance. |
| FA4 | Collapsible command output in infolist | `command_output` in `CommandRunInfolist` rendered as `TextEntry` with `->fontFamily(FontFamily::Mono)->copyable()->collapsible()`. Outputs longer than 500 chars are truncated with a "show more" link. `command_line` shown in a separate monospaced entry above it. |

---

### Group G — Robustness

| ID | Title | Description |
|---|---|---|
| G01 | Idempotency key on `CommandRun` creation | Before creating a `CommandRun`, check for an existing run with same operation + argument + status `IN (Pending, Running)` created within the last N seconds by the same user. If found, return the existing run instead of inserting a duplicate. Configurable: `checkpoint.idempotency_window_seconds` (default: `30`, set `0` to disable). Prevents double-click and duplicate cron duplicates. |
| G02 | Backup file integrity verification | After `logical_backup` completes with `exit_code=0`: run `gzip -t {output_file}` to verify file integrity. If verification fails: mark run as `Failed` with message `"Backup file failed gzip integrity check"`. Stored in `command_output`. Configurable: `checkpoint.verify_after_backup` (default: `true`). |
| G03 | File size + SHA256 checksum on `CommandRun` | Add three columns to migration: `output_file_path` (nullable string), `output_file_bytes` (nullable unsignedBigInt), `output_file_sha256` (nullable string 64). `ShellCommandDriver` populates these after any backup operation. Displayed in `CommandRunInfolist`. Enables external audit trails. |
| G04 | Auto-create `BackupDrillRun` from event | Event listener `AutoCreateBackupDrillRunListener` listens to `BackupCompleted` where `$run->operation === 'backup_drill'`. Attempts to parse structured JSON from `command_output` (`{rto_seconds, rpo_seconds, marker_uuid, marker_email, marker_count, rto_target, rpo_target}`). Creates `BackupDrillRun` record. If output is not valid JSON: logs `WARNING` and skips (manual `db-ops:record-drill` call still works). |
| G05 | Rate limiting per operation type | Config: `checkpoint.rate_limits = ['logical_backup' => ['max' => 5, 'per_minutes' => 60]]`. In `EnqueueCommandRunAction`: check `RateLimiter::tooManyAttempts()` before creating `CommandRun`. If exceeded: throw `RateLimitExceededException extends RuntimeException` with `$retryAfterSeconds` property. Filament shows user-friendly flash error. Artisan commands print error and exit `1`. |

---

## Full Task List (All 122 Todos)

### Package 1: Core (`laravel-checkpoint`)

#### Phase 1 — Scaffold & Tooling

| ID | Title | Depends On |
|---|---|---|
| C01 | Scaffold core package | — |
| C02 | composer.json (core) | — |
| C03 | GitHub Actions (core) | C01 |
| C04 | Tooling config (core) | C01 |
| C51 | Dependabot config | C01 |
| C52 | Composer audit in CI | C03 |
| C54 | Release automation | C01 |

#### Phase 2 — Models & Migrations

| ID | Title | Depends On |
|---|---|---|
| C05 | Migration: command_runs | C01 |
| C06 | Migration: backup_drill_runs | C01 |
| C07 | Model: CommandRun | C05 |
| C08 | Model: BackupDrillRun | C06 |
| C09 | Enum: CommandRunStatus | — |
| C10 | Factory: CommandRunFactory | C07 |
| C11 | Factory: BackupDrillRunFactory | C08 |

#### Phase 3 — Service Layer

| ID | Title | Depends On |
|---|---|---|
| C12 | Exceptions | — |
| C13 | CommandRunCatalog | C12 |
| C14 | Event: BackupQueued | — |
| C15 | Event: BackupStarted | — |
| C16 | Event: BackupCompleted | — |
| C17 | Event: BackupFailed | — |
| C58 | Translation strings | C13 |

#### Phase 4 — Driver Layer

| ID | Title | Depends On |
|---|---|---|
| C18 | BackupDriver contract | — |
| C19 | ShellCommandDriver | C18 |
| C41 | ShellCommandDriver: Symfony Process validation | C19 |
| C20 | FakeDriver | C18 |

#### Phase 5 — Jobs & Console Commands

| ID | Title | Depends On |
|---|---|---|
| C21 | EnqueueCommandRunAction | C13, C14–C17 |
| C22 | ProcessCommandRunJob | C19, C21 |
| C43 | Destructive op: force tries=1 | C22, C13 |
| C47 | Queue mutex per operation type | C22, C13 |
| C23 | EnqueueLogicalBackupCommand | C21 |
| C24 | EnqueueCommand (generic) | C21 |
| C25 | StatusCommand | C07 |
| C25b | RecordDrillRunCommand | C08, C28 |
| C45 | HealthCheckCommand | C07, C28 |
| C42 | RecoverOrphansCommand | C21, C22 |
| C46 | PruneCommand | C07, C28 |
| C49 | DoctorCommand + ConfigValidator | C29, C28 |

#### Phase 6 — Provider, Config & Testing Helpers

| ID | Title | Depends On |
|---|---|---|
| C26 | BackupDrillRunPolicy | — |
| C27 | CommandRunPolicy | — |
| C28 | CheckpointServiceProvider | C13, C22, C26, C27, C29 |
| C29 | config/checkpoint.php | — |
| C30 | Facade: Checkpoint | — |
| C31 | InteractsWithCheckpoint testing trait | — |
| C50 | Public API surface + @internal | C28 |

#### Phase 7 — Architecture Tests

| ID | Title | Depends On |
|---|---|---|
| C53 | Architecture tests (Pest arch) | C01 |

#### Phase 8 — Feature/Unit Tests

| ID | Title | Depends On |
|---|---|---|
| C32 | Test: CommandRunCatalogTest | C13 |
| C33 | Test: EnqueueCommandRunActionTest | C21 |
| C34 | Test: ProcessCommandRunJobTest | C22, C43, C47 |
| C35 | Test: EnqueueLogicalBackupCommandTest | C23 |
| C36 | Test: ShellCommandDriverTest (unit) | C41 |
| C37 | Test: BackupDrillRunTest | C08 |
| C38 | Test: CommandRunModelTest | C07 |
| C44 | Test: Pre-restore snapshot | C19 |
| C45t | Test: HealthCheckCommandTest | C45 |
| C42t | Test: RecoverOrphansCommandTest | C42 |
| C46t | Test: PruneCommandTest | C46 |
| C49t | Test: DoctorCommandTest | C49 |

#### Phase 9 — Documentation (Core)

| ID | Title | Depends On |
|---|---|---|
| C39 | README (core) | — |
| C40 | CHANGELOG stub (core) | — |
| C55 | SECURITY.md (core) | C01 |
| C56 | CONTRIBUTING.md (core) | C01 |
| C57 | UPGRADING.md (core) | C01 |

---

### Package 2: Filament Plugin (`laravel-checkpoint-filament`)

#### Phase 10 — Scaffold

| ID | Title | Depends On |
|---|---|---|
| F01 | Scaffold filament plugin | C40 |
| F02 | composer.json (filament) | — |
| F03 | GitHub Actions (filament) | — |
| F27 | Workbench test app spec | F01 |

#### Phase 11 — Plugin Class & Resources

| ID | Title | Depends On |
|---|---|---|
| F04 | CheckpointFilamentPlugin | F01 |
| F05 | CheckpointFilamentServiceProvider | F01 |
| F06 | CheckpointFilamentTheme | F01 |
| F07 | config/checkpoint-filament.php | — |
| F08 | CommandRunResource | F04 |
| F09 | CommandRunsTable | F08, F26 |
| F10 | CommandRunInfolist | F08 |
| F11 | ListCommandRuns page | F09 |
| F12 | ViewCommandRun page | F10 |
| F13 | BackupDrillRunResource | F04 |
| F14 | BackupDrillRunsTable | F13 |
| F15 | BackupDrillRunInfolist | F13 |
| F16 | ListBackupDrillRuns page | F14 |
| F17 | ViewBackupDrillRun page | F15 |
| F18 | Bulk cancel action | F09 |
| F19 | CommandRunCompletedNotification | F04 |
| F20 | InteractsWithCheckpointFilament | — |
| F25 | Multi-tenancy docs | F23 |
| F26 | Authorization for queue actions | F09 |

#### Phase 12 — Tests (Filament)

| ID | Title | Depends On |
|---|---|---|
| F21 | Test: CommandRunResourceTest | F09, F10 |
| F22 | Test: BackupDrillRunResourceTest | F14, F15 |

#### Phase 13 — Documentation (Filament)

| ID | Title | Depends On |
|---|---|---|
| F23 | README (filament plugin) | — |
| F24 | CHANGELOG stub (filament) | — |
| F25b | SECURITY.md (filament) | F01 |
| F23b | CONTRIBUTING.md (filament) | F01 |

---

### Improvement Group A — Plug & Play UX

| ID | Title | Depends On |
|---|---|---|
| A01 | Install wizard: `db-ops:install` | C01, C29, C49 |
| A02 | `db-ops:list-backups` command | C25, C29 |
| A03 | `db-ops:replay {runId}` command | C21, C25 |
| A04 | Typed Facade methods | C21, C30 |
| A05 | Sensible defaults (zero-config detect) | C29, A01 |

### Improvement Group B — PHP 8.4+ Coding Standards

| ID | Title | Depends On |
|---|---|---|
| B01 | `strict_types=1` + PHPStan level 9 | C01 |
| B02 | `readonly` event classes | C14–C17 |
| B03 | `#[Override]` on all interface impls | C18, C19 |
| B04 | `CommandRunStatus::label/color/icon/badge` | C09 |
| B05 | `never` return type + exception factories | C12 |
| B06 | Fully typed (level 9, no mixed) | C01 |
| B07 | `OperationResult` readonly value object | C19, C07 |

### Improvement Group C — Testing Completeness

| ID | Title | Depends On |
|---|---|---|
| CT1 | `BackupDriverContractTest` abstract | C18, C20 |
| CT2 | Pest datasets: argument validation matrix | C13, C32 |
| CT3 | Mutation testing: `infection.json` | C01 |
| CT4 | Coverage threshold in CI | C03 |
| CT5 | Missing test files (4 commands) | C23, C24, C25b, A03 |
| CT6 | `FakeDriver` full assertion API | C20, C31 |
| CT7 | Architecture tests: no-App references | C53 |

### Improvement Group D — Extendability

| ID | Title | Depends On |
|---|---|---|
| D01 | `OperationMiddleware` interface + Pipeline | C22, C19, C48 |
| D02 | `ArgumentValidator` interface | C13, D01 |
| D03 | Driver routing per operation | C18, C29, C28 |
| D04 | Facade macro support | C30, C13 |
| D05 | Custom Filament actions hook on plugin | F09, F04 |

### Improvement Group E — Notifications

| ID | Title | Depends On |
|---|---|---|
| E01 | `BackupNotification` standard Laravel Notification | C16, C17 |
| E02 | Configurable notifiable targets + channels | E01, C29 |
| E03 | Webhook callback on completion | C16, C17, C29 |

### Improvement Group F — Filament UX

| ID | Title | Depends On |
|---|---|---|
| FA1 | `BackupStatusWidget` dashboard widget | F04, C07, C08 |
| FA2 | Bulk retry action for failed runs | F09, C21 |
| FA3 | Relative timestamps in tables | F09, F14 |
| FA4 | Collapsible command output in infolist | F10 |

### Improvement Group G — Robustness

| ID | Title | Depends On |
|---|---|---|
| G01 | Idempotency key on `CommandRun` creation | C21, C07 |
| G02 | Backup file integrity verification (gzip -t) | C19 |
| G03 | File size + SHA256 on `CommandRun` | C07, C05 |
| G04 | Auto-create `BackupDrillRun` from event | C16, C08, C25b |
| G05 | Rate limiting per operation type | C21, C12 |
