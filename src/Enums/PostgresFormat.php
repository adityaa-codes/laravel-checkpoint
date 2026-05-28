<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Enums;

enum PostgresFormat: string
{
    case Directory = 'directory';
    case Custom = 'custom';
}
