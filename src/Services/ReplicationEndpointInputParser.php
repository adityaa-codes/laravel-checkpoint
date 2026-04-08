<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Contracts\ReplicationEndpointParser;
use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;
use AdityaaCodes\LaravelCheckpoint\Exceptions\InvalidArgumentException;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpoint;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpointKind;

/** @internal */
final class ReplicationEndpointInputParser implements ReplicationEndpointParser
{
    public function parse(string $input): ReplicationEndpoint
    {
        $normalized = trim($input);

        if ($normalized === '') {
            return new ReplicationEndpoint(
                kind: ReplicationEndpointKind::Prompt,
                rawInput: $input,
            );
        }

        if (str_starts_with($normalized, 'profile:')) {
            return $this->parseProfileReference($input, $normalized);
        }

        if ($this->looksLikeDsn($normalized)) {
            return $this->parseDsn($input, $normalized);
        }

        if (str_contains($normalized, '=')) {
            return $this->parseKeyValue($input, $normalized);
        }

        throw new InvalidArgumentException(
            'Replication endpoint must be one of: profile:<id>, <engine>:// DSN, or key=value pairs.',
        );
    }

    private function parseProfileReference(string $rawInput, string $normalized): ReplicationEndpoint
    {
        $identifier = trim(substr($normalized, strlen('profile:')));

        if ($identifier === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $identifier)) {
            throw new InvalidArgumentException(
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
            throw new InvalidArgumentException('Invalid DSN input for replication endpoint.');
        }

        $scheme = is_string($components['scheme'] ?? null)
            ? $components['scheme']
            : '';

        $engine = ReplicationEngine::fromInput($scheme);

        if (!$engine instanceof \AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine) {
            throw new InvalidArgumentException(
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
        $pairs = array_values(array_filter(array_map(trim(...), explode(',', $normalized)), static fn (string $pair): bool => $pair !== ''));
        $attributes = [];

        foreach ($pairs as $pair) {
            if (! str_contains($pair, '=')) {
                throw new InvalidArgumentException(
                    'Invalid key=value endpoint input. Use comma-separated key=value pairs.',
                );
            }

            [$key, $value] = array_map(trim(...), explode('=', $pair, 2));

            if ($key === '' || $value === '') {
                throw new InvalidArgumentException(
                    'Invalid key=value endpoint input. Keys and values must be non-empty.',
                );
            }

            $attributes[$key] = $value;
        }

        $engineInput = $attributes['engine'] ?? null;
        $engine = is_string($engineInput) ? ReplicationEngine::fromInput($engineInput) : null;

        if ($engineInput !== null && !$engine instanceof \AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine) {
            throw new InvalidArgumentException(
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
        return preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:\/\//', $input) === 1;
    }
}
