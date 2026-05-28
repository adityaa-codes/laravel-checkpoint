# CI/CD Setup

## Directory Layout

```
.github/
├── workflows/
│   ├── run-tests.yml
│   ├── phpstan.yml
│   ├── pint.yml
│   ├── update-changelog.yml
├── dependabot.yml
└── dependabot-auto-merge.yml
```

## run-tests.yml

Matrix across PHP × Laravel versions:

```yaml
name: Run Tests

on:
  push:
    paths:
      - '**.php'
      - 'composer.json'
      - 'phpunit.xml.dist'

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: [8.3, 8.4, 8.5]
        laravel: [^12.0, ^13.0]
        dependency-version: [prefer-stable]
        include:
          - laravel: ^12.0
            testbench: ^10.0
          - laravel: ^13.0
            testbench: ^11.0

    name: PHP ${{ matrix.php }} - Laravel ${{ matrix.laravel }}

    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none

      - name: Install dependencies
        run: |
          composer require "laravel/framework:${{ matrix.laravel }}" --no-interaction --no-update
          composer update --prefer-stable --no-interaction

      - name: Execute tests
        run: vendor/bin/pest
```

### Key Rules

- `fail-fast: false` — one failure shouldn't abort other jobs.
- Remove static analysis tools before test runs (tests don't need them).
- Use the PHP setup action with `coverage: none` for speed.
- Install Laravel via `composer require` before `composer update`.

## phpstan.yml

```yaml
name: PHPStan

on:
  push:
    paths:
      - '**.php'
      - 'phpstan.neon.dist'
      - 'phpstan-baseline.neon'

jobs:
  phpstan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction

      - name: Run PHPStan
        run: vendor/bin/phpstan analyse --error-format=github
```

Use `--error-format=github` so errors appear as annotations on PRs.

## pint.yml (Auto-Fix)

```yaml
name: Fix Code Style

on:
  push:
    paths:
      - '**.php'

jobs:
  pint:
    runs-on: ubuntu-latest
    permissions:
      contents: write

    steps:
      - uses: actions/checkout@v4
        with:
          ref: ${{ github.head_ref }}

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.4
          coverage: none

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction

      - name: Run Pint
        run: vendor/bin/pint

      - name: Commit changes
        uses: stefanzweifel/git-auto-commit-action@v5
        with:
          commit_message: Fix styling
```

This auto-fixes style issues on push. Pint runs and commits changes automatically.

## dependabot.yml

```yaml
version: 2
updates:
  - package-ecosystem: "github-actions"
    directory: "/"
    schedule:
      interval: "monthly"

  - package-ecosystem: "composer"
    directory: "/"
    schedule:
      interval: "monthly"
```

Monthly updates keep dependencies fresh without excessive PR noise.

## dependabot-auto-merge.yml

```yaml
name: Dependabot Auto-Merge

on: pull_request

permissions:
  contents: write
  pull-requests: write

jobs:
  auto-merge:
    runs-on: ubuntu-latest
    if: github.actor == 'dependabot[bot]'

    steps:
      - name: Dependabot metadata
        id: metadata
        uses: dependabot/fetch-metadata@v2

      - name: Auto-merge semver-minor and patch
        if: steps.metadata.outputs.update-type == 'version-update:semver-minor' || steps.metadata.outputs.update-type == 'version-update:semver-patch'
        run: gh pr merge --auto --squash "$PR_URL"
        env:
          PR_URL: ${{ github.event.pull_request.html_url }}
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

Auto-merges Dependabot PRs for minor and patch updates. Major updates require manual review.

## update-changelog.yml

```yaml
name: Update Changelog

on:
  release:
    types: [released]

permissions:
  contents: write

jobs:
  update:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          ref: main

      - name: Update Changelog
        uses: stefanzweifel/changelog-updater-action@v1
        with:
          latest-version: ${{ github.event.release.name }}
          release-notes: ${{ github.event.release.body }}

      - name: Commit updated CHANGELOG
        uses: stefanzweifel/git-auto-commit-action@v5
        with:
          commit_message: Update CHANGELOG
```

Runs on every GitHub release. Updates `CHANGELOG.md` with the release version and notes, then commits.
