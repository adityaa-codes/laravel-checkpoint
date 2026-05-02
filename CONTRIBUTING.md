# Contributing

## Workflow

- work in small, atomic commits
- use Conventional Commits for commit messages
- keep package-facing API changes explicit
- do not depend on classes marked `@internal`

## Local Development

Common commands:

```bash
composer install
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint
```

## Pull Requests

- describe the behavior change clearly
- include tests for new runtime behavior
- update docs when public configuration, commands, or extension points change
- keep unrelated refactors out of feature PRs

## Test Requirements

Before opening a PR, run the narrowest relevant checks and then the broader package checks when appropriate:

```bash
vendor/bin/pest
vendor/bin/phpstan analyse
vendor/bin/pint
```

## Adding Operations

When adding a new checkpoint operation:

1. add it to `CommandRunCatalog`
2. document its argument rules and exclusivity/destructive behavior
3. add or update driver command templates
4. add tests covering validation and execution behavior
5. update README configuration and usage examples if the operation is user-facing
