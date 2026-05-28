# Service Provider

## Fluent Registration Pattern

The service provider registers all package components through a fluent configuration method:

```php
class PackageNameServiceProvider extends ServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('package-name')
            ->hasConfigFile()
            ->hasViews()
            ->hasViewComponent('prefix', Alert::class)
            ->hasViewComposer('*', MyViewComposer::class)
            ->sharesDataWithAllViews('downloads', 3)
            ->hasTranslations()
            ->hasAssets()
            ->publishesServiceProvider('MyProviderName')
            ->hasRoute('web')
            ->hasMigration('create_package_tables')
            ->hasCommand(YourCoolPackageCommand::class)
            ->hasInstallCommand(function (InstallCommand $command) {
                $command
                    ->startWith(function (InstallCommand $command) {
                        $command->info('Installing Your Package...');
                    })
                    ->publishConfigFile()
                    ->publishAssets()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->copyAndRegisterServiceProviderInApp()
                    ->askToStarRepoOnGitHub('your-vendor/your-package')
                    ->endWith(function (InstallCommand $command) {
                        $command->info('Installation complete!');
                    });
            });
    }
}
```

The package name is mandatory — it determines file paths for publishing and config key resolution.

## Available Registration Methods

| Method | What It Does | Default Path |
|--------|-------------|--------------|
| `name('x')` | Sets package short name (required) | N/A |
| `hasConfigFile()` | Publishes and merges config | `config/x.php` |
| `hasConfigFile(['a', 'b'])` | Multiple config files | `config/a.php`, `config/b.php` |
| `hasViews()` | Registers view namespace `x::` | `resources/views/` |
| `hasViewComponent('p', Alert::class)` | Registers Blade component | `p-alert` tag |
| `hasViewComposer('*', Composer::class)` | Registers view composer | Called on matching views |
| `sharesDataWithAllViews('key', $value)` | Shares data across all views | N/A |
| `hasTranslations()` | Loads and publishes translations | `resources/lang/` |
| `hasAssets()` | Publishes assets to `public/vendor/x/` | `resources/dist/` |
| `publishesServiceProvider('Name')` | Publishes a provider for manual registration | N/A |
| `hasRoute('web')` | Registers route file | `routes/web.php` |
| `hasMigration('name')` | Publishes a named migration | `database/migrations/` |
| `hasCommand(Class::class)` | Registers an Artisan command | N/A |
| `hasInstallCommand(fn)` | Creates `package-name:install` command | N/A |

## Lifecycle Hooks

### `packageRegistered()`

Called during `register()`. Use for container bindings — no other providers are guaranteed to be registered yet:

```php
public function packageRegistered(): void
{
    $this->app->singleton(MyService::class);
    $this->app->scoped(Config::class, function () {
        return Config::fromArray(config('package-name'));
    });
}
```

### `packageBooted()`

Called during `boot()`. All providers are registered. Use for event subscriptions and runtime wiring:

```php
public function packageBooted(): void
{
    Event::listen(SomeEvent::class, SomeListener::class);
}
```

## Container Binding Patterns

| Pattern | Use Case |
|---------|----------|
| `singleton()` | Stateless services, loggers, managers |
| `scoped()` | Config objects rebuilt per request cycle |
| `bind()` | Factories, strategies resolved from config |
| `instance()` | Pre-constructed values, concrete implementations for testing |

For scoped singletons that need to be reloaded after `config()->set()` in tests, provide a `rebind()` method:

```php
public function register(): void
{
    $this->app->scoped(Config::class, fn () => Config::fromArray(config('package-name')));
}

// In tests, after config()->set(...):
Config::rebind(); // re-resolves from container with new config
```

## Install Command

The install command provides one-command setup for consumers:

```php
->hasInstallCommand(function (InstallCommand $command) {
    $command
        ->startWith(fn (InstallCommand $c) => $c->info('Starting...'))
        ->publishConfigFile()
        ->publishAssets()
        ->publishMigrations()
        ->askToRunMigrations()
        ->copyAndRegisterServiceProviderInApp()
        ->askToStarRepoOnGitHub('your-vendor/your-package')
        ->endWith(fn (InstallCommand $c) => $c->info('Done.'));
});
```

Consumers run: `php artisan package-name:install`

## Auto-Discovery

Laravel discovers the service provider from `composer.json`:

```json
"extra": {
    "laravel": {
        "providers": ["Vendor\\Package\\PackageServiceProvider"],
        "aliases": {"Alias": "Vendor\\Package\\Facades\\Alias"}
    }
}
```

No manual registration needed in `config/app.php` for Laravel 11+.
