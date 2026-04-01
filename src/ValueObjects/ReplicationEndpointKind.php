<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\ValueObjects;

/** @internal */
enum ReplicationEndpointKind: string
{
    case ConfigProfile = 'config_profile';
    case Dsn = 'dsn';
    case KeyValue = 'key_value';
    case Prompt = 'prompt';
}
