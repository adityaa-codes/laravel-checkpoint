<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Actions\BuildReplicationCommandPayloadAction;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;

it('builds dry-run payloads with configured critical table fallback', function (): void {
    config()->set('checkpoint.replication.critical_tables', ['accounts', 'invoices', 'accounts']);

    $payload = resolve(BuildReplicationCommandPayloadAction::class)->execute(
        source: 'profile:pg-source',
        destination: 'profile:pg-destination',
        apply: false,
        forceOverwrite: false,
    );

    expect($payload)->toBe([
        'source' => 'profile:pg-source',
        'destination' => 'profile:pg-destination',
        'dry_run' => true,
        'force_overwrite' => false,
        'critical_tables' => ['accounts', 'invoices'],
    ]);
});

it('normalizes explicit critical table options and apply mode flags', function (): void {
    $payload = resolve(BuildReplicationCommandPayloadAction::class)->execute(
        source: ' profile:pg-source ',
        destination: ' profile:pg-destination ',
        apply: true,
        forceOverwrite: true,
        criticalTables: [' users ', 'orders', 'users'],
    );

    expect($payload)->toBe([
        'source' => 'profile:pg-source',
        'destination' => 'profile:pg-destination',
        'dry_run' => false,
        'force_overwrite' => true,
        'critical_tables' => ['users', 'orders'],
    ]);
});

it('requires non-empty source and destination endpoints', function (): void {
    expect(fn (): array => resolve(BuildReplicationCommandPayloadAction::class)->execute(
        source: '   ',
        destination: 'profile:pg-destination',
        apply: false,
        forceOverwrite: false,
    ))->toThrow(InvalidArgumentException::class, 'Replication source endpoint is required.');

    expect(fn (): array => resolve(BuildReplicationCommandPayloadAction::class)->execute(
        source: 'profile:pg-source',
        destination: '   ',
        apply: false,
        forceOverwrite: false,
    ))->toThrow(InvalidArgumentException::class, 'Replication destination endpoint is required.');
});

it('rejects invalid critical table configuration types', function (): void {
    config()->set('checkpoint.replication.critical_tables', 'users');

    expect(fn (): array => resolve(BuildReplicationCommandPayloadAction::class)->execute(
        source: 'profile:pg-source',
        destination: 'profile:pg-destination',
        apply: false,
        forceOverwrite: false,
    ))->toThrow(InvalidArgumentException::class, 'checkpoint.replication.critical_tables must be an array of non-empty strings.');
});
