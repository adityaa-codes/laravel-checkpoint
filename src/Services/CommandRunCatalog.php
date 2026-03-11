<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidOperationException;

final class CommandRunCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $operations;

    public function __construct()
    {
        $this->operations = array_replace_recursive(
            $this->defaultOperations(),
            config('checkpoint.custom_operations', []),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->operations;
    }

    /**
     * @return array<string, mixed>
     */
    public function operation(string $operation): array
    {
        if (! array_key_exists($operation, $this->operations)) {
            throw new InvalidOperationException(
                sprintf('Unsupported operation: %s', $operation),
            );
        }

        return $this->operations[$operation];
    }

    public function validate(string $operation, ?string $argument): ?string
    {
        $definition = $this->operation($operation);
        $normalizedArgument = $argument !== null ? trim($argument) : null;
        $required = (bool) ($definition['argument_required'] ?? false);

        if ($required && ($normalizedArgument === null || $normalizedArgument === '')) {
            throw new InvalidArgumentException(
                sprintf('Operation %s requires an argument.', $operation),
            );
        }

        if (! $required && $normalizedArgument === '') {
            $normalizedArgument = null;
        }

        $validator = $definition['argument_validator'] ?? null;

        if (is_callable($validator) && ! $validator($normalizedArgument)) {
            $hint = (string) ($definition['argument_hint'] ?? 'valid input');

            throw new InvalidArgumentException(
                sprintf(
                    'Invalid argument for %s. Expected: %s',
                    $operation,
                    $hint,
                ),
            );
        }

        return $normalizedArgument;
    }

    public function isDestructive(string $operation): bool
    {
        return (bool) ($this->operation($operation)['destructive'] ?? false);
    }

    public function isExclusive(string $operation): bool
    {
        return (bool) ($this->operation($operation)['exclusive'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    public function extend(string $operation, array $definition): void
    {
        $this->operations[$operation] = array_replace(
            [
                'label' => $operation,
                'argument_required' => false,
                'argument_hint' => null,
                'argument_validator' => null,
                'destructive' => false,
                'exclusive' => false,
            ],
            $definition,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function defaultOperations(): array
    {
        return [
            'logical_backup' => [
                'label' => 'messages.operations.logical_backup',
                'argument_required' => false,
                'argument_hint' => null,
                'argument_validator' => null,
                'destructive' => false,
                'exclusive' => true,
            ],
            'logical_restore_latest' => [
                'label' => 'messages.operations.logical_restore_latest',
                'argument_required' => false,
                'argument_hint' => null,
                'argument_validator' => null,
                'destructive' => true,
                'exclusive' => true,
            ],
            'logical_restore_file' => [
                'label' => 'messages.operations.logical_restore_file',
                'argument_required' => true,
                'argument_hint' => 'backup filename',
                'argument_validator' => static fn (?string $value): bool => $value !== null && $value !== '',
                'destructive' => true,
                'exclusive' => true,
            ],
            'pitr_restore' => [
                'label' => 'messages.operations.pitr_restore',
                'argument_required' => true,
                'argument_hint' => 'restore target timestamp',
                'argument_validator' => static fn (?string $value): bool => $value !== null && $value !== '',
                'destructive' => true,
                'exclusive' => true,
            ],
            'backup_drill' => [
                'label' => 'messages.operations.backup_drill',
                'argument_required' => false,
                'argument_hint' => null,
                'argument_validator' => null,
                'destructive' => true,
                'exclusive' => true,
            ],
            'pgbackrest_check' => [
                'label' => 'messages.operations.pgbackrest_check',
                'argument_required' => false,
                'argument_hint' => null,
                'argument_validator' => null,
                'destructive' => false,
                'exclusive' => false,
            ],
            'pgbackrest_info' => [
                'label' => 'messages.operations.pgbackrest_info',
                'argument_required' => false,
                'argument_hint' => null,
                'argument_validator' => null,
                'destructive' => false,
                'exclusive' => false,
            ],
        ];
    }
}
