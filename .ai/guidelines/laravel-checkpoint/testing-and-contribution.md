# Testing Standards

## Framework
- Pest only — `it()` and `expect()`, no PHPUnit-style methods
- Orchestra Testbench with SQLite `:memory:`
- `uses(TestCase::class)` declared in `tests/Pest.php`
- Arch tests in `tests/ArchTest.php`: enforce `strict_types`, `final`, no `dd`/`dump`, contracts are interfaces, drivers implement `BackupDriver`, Jobs implement `ShouldQueue`

## Test helpers
Defined in `tests/Pest.php`:

```php
checkpoint_artisan('checkpoint:doctor --format=json')
checkpoint_fixture_path('doctor.json')
checkpoint_assert_matches_fixture($payload, $fixture)
```

## Coverage requirements
- Every command: happy path + failure path + edge case (empty state, missing binary, invalid input)
- Every driver: execute success + execute failure + metadata correctness
- Every service: unit tests for logic + integration tests for side effects
- Every new config key: `ConfigValidator` validation test
- Fixtures: deterministic (time frozen), committed alongside tests in `tests/Fixtures/`

## What NOT to test
- Framework internals (Laravel's queue dispatcher, scheduler)
- Third-party binary output (pg_dump, mysqldump)
- UI rendering (use FakeDriver for command execution)

---

# Contribution Workflow

1. Pick an atomic task from `IMPLEMENTATION_PLAN_V2.md`.
2. Create a feature branch: `feat/short-description` or `fix/short-description`.
3. Write the test first (Pest `it()` block).
4. Implement following architecture rules.
5. Run `vendor/bin/pint`, `vendor/bin/phpstan analyse`, `vendor/bin/pest`.
6. Commit using Conventional Commits: `feat(scope): description` / `fix(scope): description`.
7. Scopes: `core`, `cli`, `driver`, `config`, `test`, `docs`.

## Quality gates

### CI
PHP 8.3–8.5 × Laravel 12–13 × prefer-stable/prefer-lowest (12 jobs).
`vendor/bin/pest --ci` + `vendor/bin/phpstan analyse` + `vendor/bin/pint --test`.

### Pre-commit
`vendor/bin/pint` (auto-fix) + `vendor/bin/pest --stop-on-failure`.

### Pre-release
Full test matrix green + PHPStan level max + no `@` suppression + no swallowed exceptions + all `stringOption()` consolidated to trait + no methods >50 lines.
