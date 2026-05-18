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

it('adds compact block to agent contract payloads', function (): void {
    $envelope = resolve(CommandJsonContract::class)->envelope('status', [
        'result' => 'partial',
        'code' => 'status.summary.degraded',
        'summary' => 'Pending: 0, Running: 1, Failed (24h): 1.',
        'data' => [
            'last_failed_run' => [
                'failure_reason' => 'Command exited with code 1.',
                'next_action' => 'Run php artisan checkpoint:doctor --full --limit=10 --format=json',
            ],
        ],
        'suggestions' => ['Run checkpoint:doctor --format=json'],
    ]);

    expect($envelope)->toHaveKey('compact')
        ->and($envelope['compact'])->toMatchArray([
            'verdict' => 'WARN',
            'severity' => 'P1',
            'top_issue' => 'Command exited with code 1.',
            'next_action' => 'Run php artisan checkpoint:doctor --full --limit=10 --format=json',
            'exit_code' => 2,
        ]);
});
