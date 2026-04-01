<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Contracts;

use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpoint;

/** @internal */
interface ReplicationEndpointParser
{
    public function parse(string $input): ReplicationEndpoint;
}
