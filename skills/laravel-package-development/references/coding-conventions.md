# Coding Conventions

## Config as Typed DTOs

Don't spread `config()` calls throughout services. Create typed config objects:

```php
class Config
{
    public function __construct(
        public readonly BackupConfig $backup,
        public readonly NotificationsConfig $notifications,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            backup: BackupConfig::fromArray($data['backup'] ?? []),
            notifications: NotificationsConfig::fromArray($data['notifications'] ?? []),
        );
    }
}
```

- Each config DTO has a `fromArray(array $data): self` static constructor.
- Validate values at construction — throw named exceptions for invalid values.
- Support backward compatibility by checking both old and new key names in `fromArray()`.
- Merge published config with package defaults before passing to DTO.
- Bind the root config DTO as a scoped singleton in the service provider.

## Constructor Dependency Injection

No facades in services, tasks, listeners, or domain classes. Everything arrives via constructor:

```php
class BackupJob
{
    public function __construct(
        private readonly Config $config,
        private readonly BackupLogger $logger,
    ) {}
}
```

Facades and `app()` helpers are only permitted in:
- Service providers (`packageRegistered()`, `packageBooted()`)
- Artisan commands (for output, confirmations)
- Config files (for `env()`)

## Named Exception Constructors

Never throw `new Exception('message')`. Always use static named constructors:

```php
class InvalidConfig extends Exception
{
    public static function integerMustBePositive(string $key): self
    {
        return new self("Config key `{$key}` must be a positive integer.");
    }

    public static function missingRequiredKey(string $key): self
    {
        return new self("Required config key `{$key}` is missing.");
    }
}
```

Named constructors tell the caller exactly what went wrong and provide consistent, searchable error messages.

## Events with Primitives

Events should carry primitive values, not domain objects:

```php
class BackupWasSuccessful
{
    public function __construct(
        public readonly string $diskName,
        public readonly string $backupName,
    ) {}
}
```

This keeps events serializable and decoupled from domain object state. Use `readonly` properties and constructor promotion.

## Command Patterns

```php
class BackupCommand extends Command implements Isolatable
{
    public $signature = 'backup:run
        {--config=backup : Config file key}
        {--tries=1 : Number of retry attempts}';

    public function handle(Config $config): int
    {
        $this->info('Starting backup...');

        try {
            $job = BackupJobFactory::create($config);
            $job->run();
        } catch (BackupFailed $e) {
            report($e);
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
```

- Use `self::SUCCESS` and `self::FAILURE` exit codes, never raw integers.
- Implement `Isolatable` to prevent concurrent runs.
- Inject domain services via `handle()` method — Laravel resolves them.
- Options use kebab-case: `--only-db`, `--disable-notifications`.
- Provide feedback at every step: before processing, during iteration, after completion.

## Enum Conventions

```php
enum Encryption: string
{
    case None = 'none';
    case Default = 'default';
    case Aes128 = 'aes128';
    case Aes192 = 'aes192';
    case Aes256 = 'aes256';

    public function shouldEncrypt(): bool
    {
        return $this !== self::None;
    }

    public function algorithm(): ?int
    {
        return match ($this) {
            self::None => null,
            self::Default => null,
            self::Aes128 => \SODIUM_CRYPTO_PW_SCRYPTSALSA208SHA256_SALTBYTES,
        };
    }
}
```

- Backed enums with domain-relevant methods.
- Use TitleCase for case names.
- Use `match()` for exhaustive type-based branching.

## Fluent Builder Pattern

For objects with many optional settings:

```php
$job = (new BackupJob($config))
    ->setFileSelection($fileSelection)
    ->setDbDumpers($dbDumpers)
    ->setBackupDestinations($destinations);
```

Each setter returns `$this`. This keeps construction readable when too many parameters exist for a constructor.

## Global Helpers

If a package-wide helper is needed, provide it as a function in a `Helpers/functions.php` file:

```php
<?php

use Vendor\Package\Support\Logger;

function packageLogger(): Logger
{
    return app(Logger::class);
}
```

Bind the helper class as a singleton in the service provider. Functions avoid facade coupling.

## PHPDoc Usage

- Do not add PHPDoc when native PHP type declarations already express the type.
- Use PHPDoc for generics, array shapes, and collections.
- When referencing a class in PHPDoc, import it and use the short name.

## File Layout Within src/

- Group related classes into domain directories: `Config/`, `Commands/`, `Events/`, `Exceptions/`, `Notifications/`, `Support/`.
- Keep the service provider at the root of `src/`.
- Separate interfaces, DTOs, builders, factories, and strategies into their own files.
- One class per file. File name matches class name.
