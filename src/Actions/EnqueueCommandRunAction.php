<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EnqueueCommandRunAction
{
    public function __construct(
        private readonly CommandRunCatalog $catalog,
    ) {}

    public function execute(string $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
    {
        $normalizedArgument = $this->catalog->validate($operation, $argument);

        $run = DB::transaction(function () use ($operation, $normalizedArgument, $requestedBy): CommandRun {
            return CommandRun::query()->create([
                'operation' => $operation,
                'argument_text' => $normalizedArgument,
                'status' => CommandRunStatus::Pending,
                'attempts' => 0,
                'requested_by_type' => $requestedBy?->getMorphClass(),
                'requested_by_id' => $requestedBy?->getKey(),
            ]);
        });

        ProcessCommandRunJob::dispatch($run)
            ->onQueue(config('checkpoint.queue.name', 'db-ops'))
            ->afterCommit();

        event(new BackupQueued($run));

        return $run;
    }
}
