# Laravel Checkpoint

[![Latest Version on Packagist](https://img.shields.io/packagist/v/adityaa-codes/laravel-checkpoint.svg?style=flat-square)](https://packagist.org/packages/adityaa-codes/laravel-checkpoint)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/adityaa-codes/laravel-checkpoint/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/adityaa-codes/laravel-checkpoint/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/adityaa-codes/laravel-checkpoint/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/adityaa-codes/laravel-checkpoint/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/adityaa-codes/laravel-checkpoint.svg?style=flat-square)](https://packagist.org/packages/adityaa-codes/laravel-checkpoint)

Core package for database checkpoint, backup, and recovery operations in Laravel.

This package was bootstrapped from [spatie/package-skeleton-laravel](https://github.com/spatie/package-skeleton-laravel) and follows Spatie's `laravel-package-tools` conventions for package structure, discovery, testing, and CI workflows.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/laravel-checkpoint.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/laravel-checkpoint)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require adityaa-codes/laravel-checkpoint
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-checkpoint-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-checkpoint-config"
```

This is the contents of the published config file:

```php
return [
];
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-checkpoint-views"
```

## Usage

```php
use AdityaaCodes\LaravelCheckpoint\Facades\LaravelCheckpoint;

$run = LaravelCheckpoint::execute('logical_backup');
```

## Extending

Public API surface:

- `AdityaaCodes\LaravelCheckpoint\Contracts\BackupDriver`
- `AdityaaCodes\LaravelCheckpoint\Models\CommandRun`
- `AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun`
- `AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus`
- `AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog`
- `AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction`
- `AdityaaCodes\LaravelCheckpoint\Testing\InteractsWithCheckpoint`
- All event classes under `AdityaaCodes\LaravelCheckpoint\Events`

Internal implementation details such as drivers, queue jobs, and config validation are marked `@internal` and should not be treated as extension points.

## Package Development

Spatie's recommended local package workflow is to develop the package against a nearby Laravel app using a Composer `path` repository during integration and manual testing.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Aditya Gupta](https://github.com/adityaa-codes)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
