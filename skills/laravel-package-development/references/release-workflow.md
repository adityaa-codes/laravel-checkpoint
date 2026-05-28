# Release Workflow

Always invoke the `conventional-commit` skill for every step in this workflow that involves commits, changelogs, or versioning.

## Conventional Commits

All commits follow the conventional commits format:

```
feat: description of new feature
fix: description of bug fix
docs: documentation changes
chore: maintenance tasks, dependency updates, CI changes
refactor: code changes that neither fix a bug nor add a feature
test: adding or updating tests
```

The commit type determines the semantic version bump:
- `feat:` → minor version bump
- `fix:` → patch version bump
- Breaking change → major version bump (indicated by `!` after type or `BREAKING CHANGE:` in footer)

Example breaking change commit:

```
feat!: drop support for PHP 8.2

BREAKING CHANGE: Minimum PHP version is now 8.3
```

## Semantic Versioning

Given `MAJOR.MINOR.PATCH`:

- **MAJOR**: breaking changes (removed methods, changed signatures, dropped PHP/Laravel version support)
- **MINOR**: new features, backward-compatible additions
- **PATCH**: bug fixes, documentation updates, dependency bumps

Determine the next version by analyzing commits since the last tag.

## Changelog

Maintain `CHANGELOG.md` with entries grouped by version. Each version includes:
- Version number and release date
- Section per commit type (Added, Fixed, Changed, Removed)
- Link to the GitHub release comparison

### Template

```markdown
# Changelog

## 1.1.0 - 2025-01-15

### Added
- Support for custom notification channels

### Fixed
- Config merge order for nested arrays

## 1.0.0 - 2024-12-01

### Added
- Initial release
```

The `update-changelog.yml` GitHub Action automates this on every release.

## Git Tags

Create an annotated tag for each release:

```bash
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

Always push tags explicitly — `git push` does not push tags by default.

## Packagist

Once the package is on GitHub:

1. Submit the package at [packagist.org](https://packagist.org/packages/submit) using the GitHub repository URL.
2. Packagist auto-detects versions from git tags.
3. Set up a GitHub webhook so Packagist updates automatically on new tags:
   - Go to the package page on Packagist
   - Copy the API token
   - Add it to GitHub repository settings as a webhook
4. Verify the package shows up correctly at `https://packagist.org/packages/vendor/package-name`

## Release Checklist

Before releasing:

1. [ ] All CI workflows green (tests, PHPStan, Pint)
2. [ ] `CHANGELOG.md` updated with this version's changes
3. [ ] Version bump applied in any version constants or config files
4. [ ] `git tag -a vX.Y.Z` created locally
5. [ ] `git push origin vX.Y.Z` pushed
6. [ ] GitHub release created with release notes
7. [ ] `update-changelog.yml` workflow completed
8. [ ] Packagist reflects the new version

After releasing:

1. [ ] Verify `composer require vendor/package-name` resolves the new version
2. [ ] Check the package page on Packagist shows the correct version
3. [ ] Confirm the GitHub release is visible with correct notes
