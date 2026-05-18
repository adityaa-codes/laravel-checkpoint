<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\HealthCheckConfig;

final readonly class ComposeQueueHealthChecksAction
{
    use MakesHealthCheckRows;

    public function __construct(
        private HealthCheckConfig $config,
    ) {}

    /**
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(): array
    {
        $rows = [
            $this->checkRow(
                'queue.worker_visibility',
                'Queue: '.$this->config->queueName,
                'warn',
                'Cannot verify queue without running worker',
                ['queue_name' => $this->config->queueName],
            ),
        ];

        $orphanedRunsCount = $this->orphanedRunsCount();
        $rows[] = $this->checkRow(
            'queue.orphaned_runs',
            'Orphaned runs',
            $orphanedRunsCount > 0 ? 'warn' : 'pass',
            sprintf('%d pending runs beyond threshold', $orphanedRunsCount),
            [
                'orphaned_run_count' => $orphanedRunsCount,
                'threshold_minutes' => $this->config->obs['orphanThreshold'],
            ],
        );

        return $rows;
    }

    private function orphanedRunsCount(): int
    {
        return CommandRun::query()
            ->pending()
            ->where('updated_at', '<', now()->subMinutes($this->config->obs['orphanThreshold']))
            ->count();
    }
}
