<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Contracts;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverResult;

/** @api */
interface BackupDriver
{
    public function execute(DriverContext $context, CommandRun $run): DriverResult;
}
