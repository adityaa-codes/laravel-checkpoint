# Contributing

## Workflow

- work in small, atomic commits
- use Conventional Commits for commit messages
- keep package-facing API changes explicit
- do not depend on classes marked `@internal`

## Local Development

This repository uses DDEV for PHP and Composer commands.

Common commands:

```bash
ddev start
ddev exec composer install
ddev exec vendor/bin/pest
ddev exec vendor/bin/phpstan analyse
ddev exec vendor/bin/pint
```

## Pull Requests

- describe the behavior change clearly
- include tests for new runtime behavior
- update docs when public configuration, commands, or extension points change
- keep unrelated refactors out of feature PRs

## Test Requirements

Before opening a PR, run the narrowest relevant checks and then the broader package checks when appropriate:

```bash
ddev exec vendor/bin/pest
ddev exec vendor/bin/phpstan analyse
ddev exec vendor/bin/pint
```

## Adding Operations

When adding a new checkpoint operation:

1. add it to `CommandRunCatalog`
2. document its argument rules and exclusivity/destructive behavior
3. add or update driver command templates
4. add tests covering validation and execution behavior
5. update README configuration and usage examples if the operation is user-facing
