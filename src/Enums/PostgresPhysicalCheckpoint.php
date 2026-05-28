<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Enums;

enum PostgresPhysicalCheckpoint: string
{
    case Fast = 'fast';
    case Spread = 'spread';
}
