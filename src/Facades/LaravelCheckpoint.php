<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @api
 *
 * @method static \AdityaaCodes\LaravelCheckpoint\Models\CommandRun execute(string $operation, ?string $argument = null, ?\Illuminate\Database\Eloquent\Model $requestedBy = null)
 *
 * @see \AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint
 */
final class LaravelCheckpoint extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint::class;
    }
}
