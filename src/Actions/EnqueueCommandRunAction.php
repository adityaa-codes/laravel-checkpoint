<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupQueued;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\CommandRunCatalog;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;

class EnqueueCommandRunAction
{
    public function __construct(
        private readonly CommandRunCatalog $catalog,
        private readonly DatabaseManager $database,
        private readonly Dispatcher $dispatcher,
        private readonly EventDispatcher $events,
        private readonly Repository $config,
    ) {}

    public function execute(string $operation, ?string $argument = null, ?Model $requestedBy = null): CommandRun
    {
        $normalizedArgument = $this->catalog->validate($operation, $argument);

        $run = $this->database->transaction(fn (): CommandRun => CommandRun::query()->create([
            'operation' => $operation,
            'argument_text' => $normalizedArgument,
            'status' => CommandRunStatus::Pending,
            'attempts' => 0,
            'requested_by_type' => $requestedBy?->getMorphClass(),
            'requested_by_id' => $requestedBy?->getKey(),
        ]));

        $job = new ProcessCommandRunJob($run)
            ->onQueue((string) $this->config->get('checkpoint.queue.name', 'db-ops'))
            ->afterCommit();

        $this->dispatcher->dispatch($job);
        $this->events->dispatch(new BackupQueued($run));

        return $run;
    }
}
