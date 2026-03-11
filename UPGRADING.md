# Upgrading

## v1.0.0

`v1.0.0` is the first standalone release of `laravel-checkpoint`.

There is no prior package version to upgrade from inside this repository.

## Migrating From An Embedded Operations Module

If you previously used the checkpoint and recovery logic inside an application module such as `Modules/Operations`, the migration path is:

1. install `adityaa-codes/laravel-checkpoint`
2. publish and run this package's migrations
3. move command templates and queue settings into `config/checkpoint.php`
4. replace direct module service calls with:
   - `AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction`
   - or `AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint`
5. update any admin workflows to use the package command and model APIs
6. stop depending on internal classes that are not part of the documented public API

## Notes

- shell command templates are configured through package config instead of module-local classes
- queue safety behavior such as destructive-operation retry limits and orphan recovery is handled by the package
- package consumers should treat drivers, jobs, and config validation as internal unless explicitly documented otherwise
