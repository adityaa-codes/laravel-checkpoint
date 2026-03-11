<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidOperationException;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;

it('validates built-in operations and their exclusivity rules', function (): void {
    $catalog = new CommandRunCatalog;

    expect($catalog->validate('logical_backup', '   '))
        ->toBeNull()
        ->and($catalog->validate('logical_restore_file', ' nightly.sql '))
        ->toBe('nightly.sql')
        ->and($catalog->isDestructive('logical_restore_file'))->toBeTrue()
        ->and($catalog->isExclusive('logical_backup'))->toBeTrue()
        ->and($catalog->isExclusive('pgbackrest_info'))->toBeFalse();
});

it('throws for unsupported operations and missing required arguments', function (): void {
    $catalog = new CommandRunCatalog;

    expect(fn (): array => $catalog->operation('not-real'))
        ->toThrow(InvalidOperationException::class, 'Unsupported operation: not-real');

    expect(fn (): ?string => $catalog->validate('logical_restore_file', null))
        ->toThrow(InvalidArgumentException::class, 'Operation logical_restore_file requires an argument.');
});

it('supports runtime extension with custom validators', function (): void {
    $catalog = new CommandRunCatalog;

    $catalog->extend('custom_snapshot', [
        'argument_required' => true,
        'argument_hint' => 'snapshot label',
        'argument_validator' => static fn (?string $value): bool => $value === 'nightly',
        'destructive' => false,
        'exclusive' => true,
    ]);

    expect($catalog->validate('custom_snapshot', 'nightly'))->toBe('nightly')
        ->and($catalog->isExclusive('custom_snapshot'))->toBeTrue()
        ->and($catalog->isDestructive('custom_snapshot'))->toBeFalse();

    expect(fn (): ?string => $catalog->validate('custom_snapshot', 'weekly'))
        ->toThrow(InvalidArgumentException::class, 'Invalid argument for custom_snapshot. Expected: snapshot label');
});

it('merges configured custom operations at construction time', function (): void {
    config()->set('checkpoint.custom_operations', [
        'tenant_backup' => [
            'label' => 'Tenant Backup',
            'argument_required' => true,
            'argument_hint' => 'tenant id',
            'argument_validator' => static fn (?string $value): bool => $value !== null && ctype_digit($value),
            'destructive' => false,
            'exclusive' => true,
        ],
    ]);

    $catalog = new CommandRunCatalog;

    expect($catalog->operation('tenant_backup')['label'])->toBe('Tenant Backup')
        ->and($catalog->validate('tenant_backup', '42'))->toBe('42')
        ->and($catalog->isExclusive('tenant_backup'))->toBeTrue();

    expect(fn (): ?string => $catalog->validate('tenant_backup', 'abc'))
        ->toThrow(InvalidArgumentException::class, 'Invalid argument for tenant_backup. Expected: tenant id');
});
