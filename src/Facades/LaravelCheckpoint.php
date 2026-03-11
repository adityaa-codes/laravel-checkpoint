<?php

namespace AdityaaCodes\LaravelCheckpoint\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint
 */
class LaravelCheckpoint extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AdityaaCodes\LaravelCheckpoint\LaravelCheckpoint::class;
    }
}
