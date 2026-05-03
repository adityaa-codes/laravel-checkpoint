<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;
use AdityaaCodes\LaravelCheckpoint\Exceptions\CheckpointArgumentException;
use AdityaaCodes\LaravelCheckpoint\Services\ReplicationEndpointInputParser;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpointKind;

it('parses profile references', function (): void {
    $parser = new ReplicationEndpointInputParser;
    $endpoint = $parser->parse('profile:pg-source');

    expect($endpoint->kind)->toBe(ReplicationEndpointKind::ConfigProfile)
        ->and($endpoint->identifier)->toBe('pg-source')
        ->and($endpoint->engine)->toBeNull();
});

it('parses pgsql and mysql dsn endpoints', function (): void {
    $parser = new ReplicationEndpointInputParser;

    $pg = $parser->parse('pgsql://user:secret@db.internal/source');
    $my = $parser->parse('mysql://user:secret@db.internal/source');

    expect($pg->kind)->toBe(ReplicationEndpointKind::Dsn)
        ->and($pg->engine)->toBe(ReplicationEngine::Pgsql)
        ->and($my->engine)->toBe(ReplicationEngine::Mysql);
});

it('parses key value endpoints with engine', function (): void {
    $parser = new ReplicationEndpointInputParser;
    $endpoint = $parser->parse('engine=pgsql,host=example.internal,db=checkpoint');

    expect($endpoint->kind)->toBe(ReplicationEndpointKind::KeyValue)
        ->and($endpoint->engine)->toBe(ReplicationEngine::Pgsql)
        ->and($endpoint->attributes)->toHaveKey('host', 'example.internal');
});

it('returns prompt endpoint for missing input', function (): void {
    $parser = new ReplicationEndpointInputParser;
    $endpoint = $parser->parse('   ');

    expect($endpoint->kind)->toBe(ReplicationEndpointKind::Prompt);
});

it('rejects invalid endpoint formats with clear errors', function (): void {
    $parser = new ReplicationEndpointInputParser;

    expect(fn (): mixed => $parser->parse('not-a-valid-endpoint'))
        ->toThrow(CheckpointArgumentException::class, 'Replication endpoint must be one of: profile:<id>, <engine>:// DSN, or key=value pairs.');

    expect(fn (): mixed => $parser->parse('profile:bad profile'))
        ->toThrow(CheckpointArgumentException::class, 'Invalid profile reference.');

    expect(fn (): mixed => $parser->parse('sqlserver://user:pass@host/db'))
        ->toThrow(CheckpointArgumentException::class, 'Unsupported DSN engine.');

    expect(fn (): mixed => $parser->parse('engine=sqlserver,host=example'))
        ->toThrow(CheckpointArgumentException::class, 'Unsupported engine in key=value endpoint.');
});
