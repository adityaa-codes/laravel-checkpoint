<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Enums;

enum ReplicationEngine: string
{
    case Pgsql = 'pgsql';
    case Mysql = 'mysql';

    public static function fromInput(string $engine): ?self
    {
        $normalized = strtolower(trim($engine));

        return match ($normalized) {
            'pgsql', 'postgres', 'postgresql' => self::Pgsql,
            'mysql' => self::Mysql,
            default => null,
        };
    }
}
