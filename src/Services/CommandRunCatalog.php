<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\CheckpointArgumentException;
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
            throw new CheckpointArgumentException(
                sprintf('Operation %s requires an argument.', $operation),
            );
        }

        if (! $required && $normalizedArgument === '') {
            $normalizedArgument = null;
        }

        $validator = $definition['argument_validator'] ?? null;

        if (is_callable($validator) && ! $validator($normalizedArgument)) {
            $hint = (string) ($definition['argument_hint'] ?? 'valid input');

            throw new CheckpointArgumentException(
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
            'physical_backup' => [
                'label' => 'messages.operations.physical_backup',
                'argument_required' => false,
                'argument_hint' => null,
                'argument_validator' => null,
                'destructive' => false,
                'exclusive' => true,
            ],
            'physical_restore' => [
                'label' => 'messages.operations.physical_restore',
                'argument_required' => false,
                'argument_hint' => 'backup set label',
                'argument_validator' => static fn (?string $value): bool => $value === null || $value !== '',
                'destructive' => true,
                'exclusive' => true,
            ],
            'replication_sync' => [
                'label' => 'messages.operations.replication_sync',
                'argument_required' => true,
                'argument_hint' => 'json payload with source, destination, optional dry_run/apply/force_overwrite booleans, and optional critical_tables array',
                'argument_validator' => $this->validReplicationArgument(...),
                'destructive' => true,
                'exclusive' => true,
            ],
        ];
    }

    private function validReplicationArgument(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            $payload = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! is_array($payload)) {
            return false;
        }

        if (! is_string($payload['source'] ?? null) || trim($payload['source']) === '') {
            return false;
        }

        if (! is_string($payload['destination'] ?? null) || trim($payload['destination']) === '') {
            return false;
        }

        foreach (['dry_run', 'apply', 'force', 'force_overwrite', 'overwrite_destination'] as $flag) {
            if (array_key_exists($flag, $payload) && ! is_bool($payload[$flag])) {
                return false;
            }
        }

        if (array_key_exists('critical_tables', $payload)) {
            if (! is_array($payload['critical_tables'])) {
                return false;
            }

            foreach ($payload['critical_tables'] as $table) {
                if (! is_string($table) || trim($table) === '') {
                    return false;
                }
            }
        }

        return true;
    }
}
