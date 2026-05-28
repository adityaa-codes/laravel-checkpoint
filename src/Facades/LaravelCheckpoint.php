<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Facades;

use AdityaaCodes\LaravelCheckpoint\Enums\CheckpointOperation;
use Illuminate\Support\Facades\Facade;

/**
 * @api
 *
 * @method static \AdityaaCodes\LaravelCheckpoint\Models\CommandRun execute(CheckpointOperation $operation, ?string $argument = null, ?\Illuminate\Database\Eloquent\Model $requestedBy = null)
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
