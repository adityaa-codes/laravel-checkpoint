<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Enums;

enum PostgresPhysicalCompression: string
{
    case Gzip = 'gzip';
    case Lz4 = 'lz4';
    case Zstd = 'zstd';
    case None = 'none';
}
