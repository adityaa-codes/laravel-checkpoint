<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;

it('keeps surface metadata authoritative over payload keys', function (): void {
    $envelope = app(CommandJsonContract::class)->envelope('status', [
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
    $contract = app(CommandJsonContract::class);

    expect($contract->envelope('status', []))->toMatchArray([
        'version' => 1,
        'surface' => 'status',
    ])->and($contract->envelope('doctor', []))->toMatchArray([
        'version' => 1,
        'surface' => 'doctor',
    ])->and($contract->envelope('report', []))->toMatchArray([
        'version' => 1,
        'surface' => 'report',
    ]);
});
