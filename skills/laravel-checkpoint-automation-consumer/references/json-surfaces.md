# Laravel Checkpoint JSON Automation Surfaces

Read this file only when integrating machine-readable output.

## Preferred commands

- `db-ops:report`: combined recent runs, summary, and health.
- `db-ops:doctor --format=json`: health checks only.
- `db-ops:status --format=json`: recent runs or summary, depending on flags.

## Consumer expectations

- Each command emits a versioned envelope.
- `doctor` and `report` expose explicit health semantics instead of plain text.
- `report` includes both requested and effective recent-run limits.
- `status` can represent recent runs or summary views and should not be assumed to match `report` one-for-one.

## Common downstream use cases

- CI gates
- Internal dashboards
- Alert routing
- Scheduled audit snapshots
- Operator runbooks

## Files to inspect when contracts change

- `src/Console/StatusCommand.php`
- `src/Console/DoctorCommand.php`
- `src/Console/ReportCommand.php`
- `src/Services/CommandJsonContract.php`
- `src/Services/OperationalReportBuilder.php`
- `tests/Feature/CommandJsonFixtureTest.php`
