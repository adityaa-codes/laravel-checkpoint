# Package Structure

## Directory Layout

```
your-package/
├── src/                    # PHP source (PSR-4 autoloaded)
├── config/                 # Single config file published to app
├── database/               # Migrations (optional)
├── resources/              # Views, lang, assets (optional)
│   └── dist/               # Publishable frontend assets
├── tests/                  # Pest test suite
│   ├── Pest.php            # Binds base TestCase
│   ├── TestCase.php        # Extends Orchestra Testbench
│   └── stubs/              # Test fixture files
├── docs/                   # User-facing documentation
├── .editorconfig
├── .gitattributes
├── .gitignore
├── composer.json
├── phpunit.xml.dist
├── pint.json
├── phpstan.neon.dist
├── phpstan-baseline.neon
├── CHANGELOG.md
├── LICENSE.md
└── README.md
```

## composer.json

```json
{
    "name": "vendor/package-name",
    "description": "Package description",
    "keywords": ["laravel", "vendor-name"],
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "laravel/framework": "^12.0|^13.0"
    },
    "require-dev": {
        "laravel/pint": "^1.14",
        "pestphp/pest": "^3.7",
        "orchestra/testbench": "^10.0|^11.0",
        "phpstan/phpstan": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "Vendor\\PackageName\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Vendor\\PackageName\\Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Vendor\\PackageName\\PackageNameServiceProvider"
            ],
            "aliases": {
                "PackageName": "Vendor\\PackageName\\Facades\\PackageName"
            }
        }
    },
    "scripts": {
        "test": "vendor/bin/pest",
        "analyse": "vendor/bin/phpstan analyse",
        "lint": "vendor/bin/pint --test"
    }
}
```

### Key composer.json Rules

- `extra.laravel.providers` enables auto-discovery in Laravel applications.
- `extra.laravel.aliases` (optional) registers a facade alias.
- `autoload.psr-4` maps the `src/` directory to the package namespace.
- `autoload-dev.psr-4` maps `tests/` to a separate test namespace.
- Version constraints: use `^` ranges. PHP `^8.3`, Laravel `^12.0|^13.0`.
- `scripts.test`, `scripts.analyse`, `scripts.lint` provide standard quality commands.

## .editorconfig

```ini
root = true

[*]
charset = utf-8
end_of_line = lf
indent_size = 4
indent_style = space
insert_final_newline = true
trim_trailing_whitespace = true

[*.md]
trim_trailing_whitespace = false
```

## .gitattributes

```text
* text=auto

/.github export-ignore
/tests export-ignore
/docs export-ignore
.editorconfig export-ignore
.gitattributes export-ignore
.gitignore export-ignore
CHANGELOG.md export-ignore
```

`export-ignore` prevents test files, docs, and CI config from being included when the package is installed via Composer.

## .gitignore

```
vendor
composer.lock
.phpunit.result.cache
build
.idea
.DS_Store
```

Do not commit `composer.lock` for packages — it is ignored so consumers get dependency resolution scoped to their application.

## Config Files

- Publish a single config file from `config/` to the application.
- The config file name matches the package name: `config/package-name.php`.
- All keys are snake_case.
- The published config merges with package defaults via `mergeConfigFrom()`.
- Access config values from within the package using `config('package-name.key')`.

### Config File Template

```php
<?php

return [
    'key' => env('PACKAGE_KEY', 'default'),

    'nested' => [
        'option' => true,
    ],
];
```

## phpunit.xml.dist

Use PHPUnit 10.5+ schema with Pest:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
    bootstrap="vendor/autoload.php"
    colors="true"
>
    <testsuites>
        <testsuite name="default">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="DB_CONNECTION" value="testing"/>
    </php>
</phpunit>
```

## phpstan.neon.dist

```yaml
includes:
    - phpstan-baseline.neon

parameters:
    level: 6
    paths:
        - src
        - config
    checkOctaneCompatibility: true
    checkModelProperties: true
```

Start at level 6 and raise progressively. Maintain a baseline file for known issues.

## pint.json

```json
{
    "preset": "laravel"
}
```
