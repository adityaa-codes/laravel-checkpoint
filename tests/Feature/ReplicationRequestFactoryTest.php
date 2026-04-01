<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationEndpointInputParser;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationRequestFactory;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpointKind;

it('builds queue-only same-engine requests from profile references', function (): void {
    $factory = new ReplicationRequestFactory(
        new ReplicationEndpointInputParser,
        config(),
    );

    $request = $factory->fromInput('profile:pg-source', 'profile:pg-destination', dryRunRequested: true);

    expect($request->queueOnly)->toBeTrue()
        ->and($request->dryRunRequested)->toBeTrue()
        ->and($request->engine)->toBe(ReplicationEngine::Pgsql)
        ->and($request->source->kind)->toBe(ReplicationEndpointKind::ConfigProfile)
        ->and($request->destination->kind)->toBe(ReplicationEndpointKind::ConfigProfile);
});

it('rejects mixed engine replication requests', function (): void {
    $factory = new ReplicationRequestFactory(
        new ReplicationEndpointInputParser,
        config(),
    );

    expect(fn (): mixed => $factory->fromInput(
        'pgsql://src:pw@db-a/source',
        'mysql://dst:pw@db-b/destination',
        dryRunRequested: true,
    ))->toThrow(InvalidArgumentException::class, 'Replication v1 supports same-engine only.');
});

it('requires explicit engine resolution for source and destination', function (): void {
    $factory = new ReplicationRequestFactory(
        new ReplicationEndpointInputParser,
        config(),
    );

    expect(fn (): mixed => $factory->fromInput(
        'host=source.internal,db=checkpoint',
        'host=destination.internal,db=checkpoint',
        dryRunRequested: true,
    ))->toThrow(InvalidArgumentException::class, 'Replication requires explicit source and destination engines.');
});

it('rejects disabled replication safety defaults', function (): void {
    config()->set('checkpoint.replication.block_in_ci', false);

    $factory = new ReplicationRequestFactory(
        new ReplicationEndpointInputParser,
        config(),
    );

    expect(fn (): mixed => $factory->fromInput(
        'profile:pg-source',
        'profile:pg-destination',
        dryRunRequested: true,
    ))->toThrow(InvalidArgumentException::class, 'Replication safety requires CI blocking by default.');
});
