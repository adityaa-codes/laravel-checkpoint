---
sidebar_position: 1
---

# Contributing

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
