---
name: laravel-package-development
description: "Develops Laravel packages end-to-end: scaffolding, service providers, config, migrations, routes, commands, testing with Orchestra Testbench, CI/CD, and releases. Use when creating a new Laravel package, adding package infrastructure (service providers, config, views, migrations, routes, commands, assets), setting up package testing, configuring CI/CD for a package, or preparing a package release. Do not use for application-level code, controllers, Eloquent queries, N+1 fixes, caching, security reviews, or general PHP code patterns — use laravel-best-practices for those. Do not use for application-level configuration or consuming existing packages."
---

# Laravel Package Development

1. Confirm the request is package development work and not application-level consumption.
2. Invoke the `laravel-best-practices` skill before writing any PHP/Laravel package code. General patterns for database performance, security, caching, testing, queues, routing, Eloquent, validation, error handling, scheduling, architecture, migrations, collections, Blade, and style all apply to package code.
3. Invoke the `conventional-commit` skill for every commit, changelog update, and release. All commits and releases follow conventional commits format.
4. Read `references/package-structure.md` for directory layout, composer.json conventions, editorconfig, gitattributes, and config file patterns.
5. Read `references/service-provider.md` for fluent package registration, lifecycle hooks, container bindings, install commands, and auto-discovery.
6. Read `references/coding-conventions.md` for config DTOs, named exception constructors, constructor DI everywhere, event patterns, command patterns, enums, and fluent builders.
7. Read `references/testing-setup.md` for Orchestra Testbench, Pest configuration, fake patterns, scoped singleton rebinding, and test stubs.
8. Read `references/ci-cd-setup.md` for GitHub Actions matrix, Pint auto-fix, PHPStan baseline, Dependabot, auto-changelog on release.
9. Read `references/release-workflow.md` for semantic versioning, git tags, Packagist, and the release checklist. Always invoke `conventional-commit` for this step.
10. Validate the package: run `composer test`, `vendor/bin/pint`, and `vendor/bin/phpstan analyse`.

## Error Handling

- If the task is application-level consumption of an existing package, stop and redirect to the appropriate consumer skill.
- If the task demands patterns that conflict with established package conventions, surface the conflict and pick the more tested convention.
- If CI configuration or release workflows cannot be verified, report the blocker rather than committing unverified configuration.
- If tests cannot run due to missing dependencies, report the missing prerequisites and stop.

## Validation

1. Run `python3 /home/adityaa3/.codex/skills/.system/skill-creator/scripts/quick_validate.py .`
2. Manually confirm guidance matches current package development conventions and referenced skills.
