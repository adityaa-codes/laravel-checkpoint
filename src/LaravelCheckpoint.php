<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint;

use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Database\Eloquent\Model;

class LaravelCheckpoint
{
    public function __construct(
        private readonly EnqueueCommandRunAction $enqueueCommandRun,
    ) {}

    public function execute(string $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
    {
        return $this->enqueueCommandRun->execute($operation, $argument, $requestedBy);
    }
}
