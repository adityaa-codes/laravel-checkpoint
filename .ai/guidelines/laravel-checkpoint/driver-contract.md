# Driver Contract

## Interface
All drivers implement `BackupDriver` (see `src/Contracts/BackupDriver.php`):

```php
public function execute(CommandRun $run): void;
```

Config-driven resolution: `config('checkpoint.drivers.{name}.class')`.

## Driver implementation requirements
1. Claim the run atomically (`claimPendingExecution`)
2. Compute planned metadata before execution
3. Run `RestoreSafetyGuard->evaluate()` for destructive operations
4. Build Symfony Process argv array (never shell strings)
5. Capture stdout/stderr via `CommandOutputStore`
6. Mark run as succeeded/failed with exit code
7. Dispatch appropriate events (`BackupCompleted`, `BackupFailed`)
8. Persist structured metadata for audit trail

## Adding a custom driver
1. Implement `BackupDriver`
2. Register class in `config/checkpoint.php` under `drivers.{name}.class`
3. Add binary validation to `ConfigValidator`
4. Add health checks to active driver binary checks

## Known anti-patterns

| Anti-pattern | Fix |
|---|---|
| Injected config repository with scattered `->get()` calls | Pass typed value objects/DTOs |
| Copy-paste utility methods across commands | Put in `UsesLaravelPrompts` trait |
| Swallowed exceptions (`catch (Throwable) {}`) | `report($e)` or `logger()->error()` |
| `config()->set(...)` inside validators | Validation reports; caller fixes |
| Global helpers in services (`app()->environment()`) | Pass environment via constructor |
| `(new Model)->method()` | DI or `app()->make()` |
| Error suppression (`@file_put_contents`) | Check return value + log on failure |
| Duplicate binary lists across files | Single source of truth in config |
| Duplicate DSN regex in Parser + Redactor | Extract `DsnPattern` utility |
