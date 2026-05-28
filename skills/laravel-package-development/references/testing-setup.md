# Testing Setup

## Base TestCase

Extend `Orchestra\Testbench\TestCase` and configure the package environment:

```php
<?php

namespace Vendor\Package\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;
use Vendor\Package\PackageServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Vendor\\Package\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app): array
    {
        return [
            PackageServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
    }
}
```

### Key Points

- `getPackageProviders()` returns the package service provider(s) so routes, config, and views are registered.
- `getEnvironmentSetUp()` configures the test environment — use SQLite in-memory for speed.
- `Factory::guessFactoryNamesUsing()` maps model classes to factory classes within the package namespace.

## Pest Configuration

Create `tests/Pest.php` to bind the base TestCase:

```php
<?php

uses(\Vendor\Package\Tests\TestCase::class)->in(__DIR__);

expect()->extend('toContainItem', function (mixed $expected) {
    return $this->toContain($expected);
});
```

- `uses(TestCase::class)->in(__DIR__)` makes all tests in the directory use the package TestCase.
- Custom expectations can be added via `expect()->extend()`.

## Fake Patterns

### Event Faking

```php
use Illuminate\Support\Facades\Event;

Event::fake();

// ... run code that dispatches events ...

Event::assertDispatched(BackupWasSuccessful::class, function ($event) {
    return $event->diskName === 'local';
});
```

### Storage Faking

```php
use Illuminate\Support\Facades\Storage;

Storage::fake('local');

// ... run code that writes to disk ...

Storage::disk('local')->assertExists('backups/2024-01-01.zip');
```

### HTTP Faking

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'api.example.com/*' => Http::response(['status' => 'ok']),
]);

// ... run code that makes HTTP calls ...

Http::assertSent(function ($request) {
    return $request->url() === 'https://api.example.com/endpoint';
});
```

### Sleep Faking

```php
use Illuminate\Support\Sleep;

Sleep::fake();

// ... run code with retries, delays, or sleep() ...

Sleep::assertSleptTimes(3);
```

Always use `Event::fake()` before the code under test — not after factory setup.

## Scoped Singleton Rebinding

When a config value is scoped as a singleton in the service provider, changing `config()` in tests won't automatically re-resolve it. Provide a rebind method:

```php
// In the config DTO or a test helper:
public static function rebind(): void
{
    app()->forgetInstance(Config::class);
}
```

Use it in tests after `config()->set(...)`:

```php
config()->set('package-name.timeout', 60);
Config::rebind();
```

## Console Tests

```php
$this->artisan('package:command', ['--option' => 'value'])
    ->assertExitCode(0)
    ->expectsOutput('Task completed');
```

- Use `assertExitCode()` to check success or failure.
- Use `expectsOutput()` to verify console messages.
- Set `$this->artisan(...)->doesntExpectOutput('error message')` for negative assertions.

## Test Stubs

Place test fixture files in `tests/stubs/`:

```
tests/stubs/
├── sample.json
├── archive.zip
└── empty-directory/
```

Access them in tests with relative paths from the test directory:

```php
$stubPath = __DIR__.'/stubs/sample.json';
$contents = file_get_contents($stubPath);
```

## Custom TestCase Helpers

Add reusable helpers to the base `TestCase`:

```php
protected function setNow(string $date): void
{
    Carbon::setTestNow(Carbon::parse($date));
}

protected function assertFileExistsInZip(string $zipPath, string $filename): void
{
    $zip = new \ZipArchive();
    $zip->open($zipPath);
    $this->assertNotFalse($zip->locateName($filename), "File {$filename} not found in zip.");
    $zip->close();
}
```

Keep helpers focused on test infrastructure, not business assertions.

## Test Organization

- Mirror the `src/` directory structure under `tests/`.
- One test file per source class: `tests/Config/BackupConfigTest.php` tests `src/Config/BackupConfig.php`.
- Use `it()` for descriptive test names: `it('validates positive integers for tries')`.
- Follow Arrange-Act-Assert: set up state, run the action, assert the outcome.
