<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;
use Illuminate\Support\Arr;

/** @internal */
final readonly class ReplicationEndpoint
{
    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public ReplicationEndpointKind $kind,
        public string $rawInput,
        public ?ReplicationEngine $engine = null,
        public ?string $identifier = null,
        public array $attributes = [],
    ) {}

    public function toRedactedString(): string
    {
        return match ($this->kind) {
            ReplicationEndpointKind::ConfigProfile => sprintf('profile:%s', $this->identifier ?? ''),
            ReplicationEndpointKind::Dsn => sprintf('%s://[REDACTED]', $this->engine?->value ?? 'dsn'),
            ReplicationEndpointKind::KeyValue => sprintf(
                'kv:%s',
                Arr::join(collect($this->attributes)->keys()->all(), ','),
            ),
            ReplicationEndpointKind::Prompt => 'prompt:[PENDING_INPUT]',
        };
    }
}
