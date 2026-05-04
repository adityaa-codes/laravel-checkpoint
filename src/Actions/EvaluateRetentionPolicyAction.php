<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final readonly class EvaluateRetentionPolicyAction
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array{
     *   totals: array{eligible:int,command_runs:int,externalized_output:int},
     *   by_policy: array<string,array{label:string,keep_days:int,count:int}>,
     *   sample: list<array{id:int,operation:string,status:string,created_at:string,age_days:int,policy:string,keep_days:int,reason:string}>
     * }
     */
    public function execute(int $limit = 100): array
    {
        $now = now();
        $runs = CommandRun::query()
            ->select(['id', 'operation', 'status', 'created_at', 'metadata'])
            ->whereNotNull('created_at')
            ->where(function (Builder $query) use ($now): void {
                $this->applyEligibleRetentionPredicate($query, $now);
            })->latest()
            ->orderBy('id')
            ->limit(max(1, $limit * 3))
            ->get();

        $sample = [];
        $externalizedOutput = 0;
        $byPolicy = [];

        foreach ($runs as $run) {
            if (! $run->created_at instanceof Carbon) {
                continue;
            }

            $decision = $this->decisionForRun($run);
            $ageDays = max(0, (int) $run->created_at->diffInDays($now));

            if ($ageDays <= $decision['keep_days']) {
                continue;
            }

            $sample[] = [
                'id' => (int) $run->getKey(),
                'operation' => (string) $run->operation,
                'status' => $run->status->value,
                'created_at' => $run->created_at->format('Y-m-d H:i:s'),
                'age_days' => $ageDays,
                'policy' => $decision['policy'],
                'keep_days' => $decision['keep_days'],
                'reason' => $decision['reason'],
            ];

            if ($this->hasExternalizedOutput($run)) {
                $externalizedOutput++;
            }

            $key = $decision['policy'];
            $byPolicy[$key] ??= ['label' => $decision['reason'], 'keep_days' => $decision['keep_days'], 'count' => 0];
            $byPolicy[$key]['count']++;

            if (count($sample) >= $limit) {
                break;
            }
        }

        return [
            'totals' => [
                'eligible' => count($sample),
                'command_runs' => count($sample),
                'externalized_output' => $externalizedOutput,
            ],
            'by_policy' => $byPolicy,
            'sample' => $sample,
        ];
    }

    public function retentionEnabled(): bool
    {
        return (int) $this->config->get('checkpoint.retention_days', 30) > 0;
    }

    public function keepDaysForRun(CommandRun $run): int
    {
        return $this->decisionForRun($run)['keep_days'];
    }

    /**
     * @param  Builder<CommandRun>  $query
     */
    public function applyEligibleRetentionPredicate(Builder $query, Carbon $now): void
    {
        $failedDays = 365;
        $succeededDays = max(1, (int) $this->config->get('checkpoint.retention_days', 30));

        $query->where(function (Builder $where) use ($now, $failedDays, $succeededDays): void {
            $where->where(function (Builder $failed) use ($now, $failedDays): void {
                $failed
                    ->where('status', CommandRunStatus::Failed)
                    ->where('created_at', '<=', $now->copy()->subDays($failedDays));
            });

            $where->orWhere(function (Builder $others) use ($now, $succeededDays): void {
                $others
                    ->whereIn('status', [CommandRunStatus::Succeeded, CommandRunStatus::Cancelled])
                    ->where('created_at', '<=', $now->copy()->subDays($succeededDays));
            });
        });
    }

    /**
     * @return array{policy:string,keep_days:int,reason:string}
     */
    private function decisionForRun(CommandRun $run): array
    {
        if ($run->status === CommandRunStatus::Failed) {
            return [
                'policy' => 'failed',
                'keep_days' => 365,
                'reason' => 'Failed runs: 365 day retention',
            ];
        }

        $days = max(1, (int) $this->config->get('checkpoint.retention_days', 30));

        return [
            'policy' => 'retention',
            'keep_days' => $days,
            'reason' => sprintf('%d day retention window', $days),
        ];
    }

    private function hasExternalizedOutput(CommandRun $run): bool
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $outputStorage = is_array($metadata['output_storage'] ?? null) ? $metadata['output_storage'] : [];

        return ($outputStorage['externalized'] ?? false) === true;
    }
}
