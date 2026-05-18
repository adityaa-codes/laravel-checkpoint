<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;
use AdityaaCodes\LaravelCheckpoint\Exceptions\CheckpointArgumentException;
use AdityaaCodes\LaravelCheckpoint\Support\DsnPattern;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpoint;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpointKind;

/** @internal */
final readonly class ReplicationEndpointInputParser implements ReplicationEndpointParser
{
    public function parse(string $input): ReplicationEndpoint
    {
        $normalized = str($input)->trim()->value();

        if ($normalized === '') {
            return new ReplicationEndpoint(
                kind: ReplicationEndpointKind::Prompt,
                rawInput: $input,
            );
        }

        if (str($normalized)->startsWith('profile:')) {
            return $this->parseProfileReference($input, $normalized);
        }

        if ($this->looksLikeDsn($normalized)) {
            return $this->parseDsn($input, $normalized);
        }

        if (str($normalized)->contains('=')) {
            return $this->parseKeyValue($input, $normalized);
        }

        throw new CheckpointArgumentException(
            'Replication endpoint must be one of: profile:<id>, <engine>:// DSN, or key=value pairs.',
        );
    }

    private function parseProfileReference(string $rawInput, string $normalized): ReplicationEndpoint
    {
        $identifier = str($normalized)->after('profile:')->trim()->value();

        if ($identifier === '' || ! str($identifier)->isMatch('/^[A-Za-z0-9._-]+$/')) {
            throw new CheckpointArgumentException(
                'Invalid profile reference. Use profile:<identifier> with letters, numbers, dot, underscore, or hyphen.',
            );
        }

        return new ReplicationEndpoint(
            kind: ReplicationEndpointKind::ConfigProfile,
            rawInput: $rawInput,
            identifier: $identifier,
        );
    }

    private function parseDsn(string $rawInput, string $normalized): ReplicationEndpoint
    {
        $components = parse_url($normalized);

        if (! is_array($components)) {
            throw new CheckpointArgumentException('Invalid DSN input for replication endpoint.');
        }

        $scheme = is_string($components['scheme'] ?? null)
            ? $components['scheme']
            : '';

        $engine = ReplicationEngine::fromInput($scheme);

        if (! $engine instanceof ReplicationEngine) {
            throw new CheckpointArgumentException(
                'Unsupported DSN engine. Replication v1 supports only pgsql:// and mysql://.',
            );
        }

        return new ReplicationEndpoint(
            kind: ReplicationEndpointKind::Dsn,
            rawInput: $rawInput,
            engine: $engine,
        );
    }

    private function parseKeyValue(string $rawInput, string $normalized): ReplicationEndpoint
    {
        $pairs = str($normalized)
            ->explode(',')
            ->map(fn (string $pair): string => str($pair)->trim()->value())
            ->filter(fn (string $pair): bool => $pair !== '')
            ->values()
            ->all();

        $attributes = [];

        foreach ($pairs as $pair) {
            if (! str($pair)->contains('=')) {
                throw new CheckpointArgumentException(
                    'Invalid key=value endpoint input. Use comma-separated key=value pairs.',
                );
            }

            $parts = str($pair)->explode('=', 2)
                ->map(fn (string $part): string => str($part)->trim()->value())
                ->all();

            [$key, $value] = $parts;

            if ($key === '' || $value === '') {
                throw new CheckpointArgumentException(
                    'Invalid key=value endpoint input. Keys and values must be non-empty.',
                );
            }

            $attributes[$key] = $value;
        }

        $engineInput = $attributes['engine'] ?? null;
        $engine = is_string($engineInput) ? ReplicationEngine::fromInput($engineInput) : null;

        if ($engineInput !== null && ! $engine instanceof ReplicationEngine) {
            throw new CheckpointArgumentException(
                'Unsupported engine in key=value endpoint. Supported engines are pgsql and mysql.',
            );
        }

        return new ReplicationEndpoint(
            kind: ReplicationEndpointKind::KeyValue,
            rawInput: $rawInput,
            engine: $engine,
            attributes: $attributes,
        );
    }

    private function looksLikeDsn(string $input): bool
    {
        return str($input)->isMatch(DsnPattern::REGEX);
    }
}
