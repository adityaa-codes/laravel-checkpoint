# CLI Command Standards

## Naming
All commands use `checkpoint:*` prefix. No `do:`, `check:`, `admin:` namespaces.

Public surface: `install`, `doctor`, `status`, `report`, `backup`, `drill`, `replicate`, `migrate-from-spatie`, `health-check`, `pitr-readiness`, `prune`, `recover-orphans`, `retention-policy`, `catalog-export`, `record-drill`, `test`.

## Structure
Commands extend `Illuminate\Console\Command` and use `UsesLaravelPrompts` trait. All commands are `final class` with constructor DI.

Reference: any command in `src/Console/`.

```php
protected $signature = 'checkpoint:example {--format=table}';
protected $description = 'One-line description.';

public function handle(): int
{
    // validate → delegate → format output
    return self::SUCCESS; // or self::FAILURE
}
```

## Output modes
- `table` — human-readable, uses `$this->promptTable()` from `UsesLaravelPrompts`
- `json` — full JSON envelope via `CommandJsonContract`
- `agent` — AI-agent-friendly compact JSON
- `compact-json` — null-stripped JSON

## Shared utilities
All shared methods live in `UsesLaravelPrompts` trait. Never copy-paste across commands.

- `stringOption(string $name): ?string`
- `policyProfileOverride(): ?string`
- `machineGateDecision(array $gateDecision): array`
- `shouldCollapsePassingChecks(): bool`
- `orderedChecksForDisplay(array $checks): array`
- `overallSloStatus(array $checks, int $failedCount, int $warnCount): array`
- `translatedOr(string $key, string $default): string`

## Return codes
- `self::SUCCESS` (0) — normal exit
- `self::FAILURE` (1) — error exit
- Gate exit codes: pass=0, warn=2, safety_fail=10, evidence_fail=11, policy_error=12
