<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Enums;

enum PostgresWalMethod: string
{
    case Stream = 'stream';
    case Fetch = 'fetch';
}
