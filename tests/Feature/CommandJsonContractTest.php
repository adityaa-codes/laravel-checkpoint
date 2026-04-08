<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;

it('keeps surface metadata authoritative over payload keys', function (): void {
    $envelope = resolve(CommandJsonContract::class)->envelope('status', [
        'version' => 99,
        'surface' => 'overridden',
        'mode' => 'summary',
    ]);

    expect($envelope)->toMatchArray([
        'version' => 1,
        'surface' => 'status',
        'mode' => 'summary',
    ]);
});

it('tracks versions independently per command surface', function (): void {
    $contract = resolve(CommandJsonContract::class);

    expect($contract->envelope('status', []))->toMatchArray([
        'version' => 1,
        'surface' => 'status',
    ])->and($contract->envelope('catalog_export', []))->toMatchArray([
        'version' => 1,
        'surface' => 'catalog_export',
    ])->and($contract->envelope('pitr_readiness', []))->toMatchArray([
        'version' => 1,
        'surface' => 'pitr_readiness',
    ])->and($contract->envelope('retention_policy', []))->toMatchArray([
        'version' => 1,
        'surface' => 'retention_policy',
    ])->and($contract->envelope('doctor', []))->toMatchArray([
        'version' => 3,
        'surface' => 'doctor',
    ])->and($contract->envelope('report', []))->toMatchArray([
        'version' => 2,
        'surface' => 'report',
    ]);
});
