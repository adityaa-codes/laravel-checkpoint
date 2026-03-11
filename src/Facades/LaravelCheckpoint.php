<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \AdityaaCodes\LaravelCheckpoint\Models\CommandRun execute(string $operation, ?string $argument = null, ?\Illuminate\Database\Eloquent\Model $requestedBy = null)
 *
 * @see \AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint
 */
class LaravelCheckpoint extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint::class;
    }
}
