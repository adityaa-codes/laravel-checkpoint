---
sidebar_position: 1
---

# Contributing

## Package workflow

Keep package changes small, atomic, and test-backed.

Before opening a PR, run the narrowest relevant check and then the broader package quality commands when needed:

```bash
ddev exec vendor/bin/pest
ddev exec vendor/bin/phpstan analyse
ddev exec vendor/bin/pint
ddev composer quality
```

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
