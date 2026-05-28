---
sidebar_position: 1
---

# Contributing

## Positioning

When writing docs, code, or public communication about Checkpoint:

- Frame Checkpoint as a database reliability layer, not a backup tool.
- Use the 3-layer model: backup → recovery → verification.

## Package workflow

Keep changes small, atomic, and test-backed.

Before opening a PR:

```bash
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint
composer quality
```

CI runs across Laravel 12/13 and PHP 8.3/8.4/8.5 with both `prefer-stable` and `prefer-lowest` dependency resolution.

## Docs workflow

The documentation site lives in `website/`.

```bash
cd website
npm install
npm run start
npm run build
```

When package behaviour changes, update the docs pages that describe:

- config keys
- commands
- driver behaviour
- safety posture
- operational workflows

## Documentation file list

Files to update when behaviour changes:

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
