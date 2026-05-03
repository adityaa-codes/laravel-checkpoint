---
sidebar_position: 1
---

# Contributing

## Positioning guidelines

When writing docs, code, or public communication about Checkpoint:

- **Frame Checkpoint as complementary to spatie/laravel-backup**, never as an alternative or replacement. Checkpoint complements laravel-backup by adding the recovery and verification layers.
- Use "Database Reliability Layer" framing, not "backup tool."
- Emphasize the 3-layer model: backup (commodity) → recovery (rare) → verification (moat).

## Package workflow

Keep package changes small, atomic, and test-backed.

Before opening a PR, run the narrowest relevant check and then the broader package quality commands when needed:

```bash
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint
composer quality
```

CI compatibility policy:

- run tests across Laravel 12/13 and PHP 8.3/8.4/8.5
- validate both `prefer-stable` and `prefer-lowest` dependency resolution paths

## Docs workflow

The documentation site lives in `website/`.

Local docs commands:

```bash
cd website
npm install
npm run start
npm run build
```

When package behavior changes, update the docs pages that describe:

- config keys
- commands
- driver behavior
- safety posture
- operational workflows

## Documentation file list

All files that need updates when behavior changes:

- `website/docs/start-here.md`
- `website/docs/getting-started/installation.md`
- `website/docs/getting-started/quickstart.md`
- `website/docs/cli/command-reference.md`
- `website/docs/configuration/basic-configuration.md`
- `website/docs/configuration/queue-timeouts.md`
- `website/docs/common-tasks/take-your-first-backup.md`
- `website/docs/common-tasks/check-health-and-status.md`
- `website/docs/common-tasks/run-a-drill.md`
- `website/docs/common-tasks/restore-a-backup.md`
- `website/docs/drivers/choose-a-driver.md`
- `website/docs/safety/restore-guardrails.md`
- `website/docs/troubleshooting/common-failures.md`
- `website/docs/contributing/index.md`
