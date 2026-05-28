<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Enums;

use Illuminate\Support\Str;

enum ReplicationEngine: string
{
    case Pgsql = 'pgsql';
    case Mysql = 'mysql';

    public static function fromInput(string $engine): ?self
    {
        $normalized = Str::lower(Str::trim($engine));

        return match ($normalized) {
            'pgsql', 'postgres', 'postgresql' => self::Pgsql,
            'mysql' => self::Mysql,
            default => null,
        };
    }
}
