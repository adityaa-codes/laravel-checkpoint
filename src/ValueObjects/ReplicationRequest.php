<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

use AdityaaCodes\LaravelCheckpoint\Enums\ReplicationEngine;

/** @internal */
final readonly class ReplicationRequest
{
    public function __construct(
        public ReplicationEndpoint $source,
        public ReplicationEndpoint $destination,
        public ReplicationEngine $engine,
        public bool $queueOnly,
        public bool $dryRunRequested,
    ) {}
}
