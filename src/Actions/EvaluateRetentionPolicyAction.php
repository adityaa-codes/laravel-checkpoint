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
        if (! $this->retentionEnabled()) {
            return [
                'totals' => [
                    'eligible' => 0,
                    'command_runs' => 0,
                    'externalized_output' => 0,
                ],
                'by_policy' => [],
                'sample' => [],
            ];
        }

        $now = now();
        $runs = CommandRun::query()
            ->select(['id', 'operation', 'status', 'created_at', 'metadata'])
            ->whereNotNull('created_at')
            ->where(function (\Illuminate\Contracts\Database\Query\Builder $query) use ($now): void {
                $this->applyEligibleRetentionPredicate($query, $now);
            })->oldest()
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
            $cutoff = $now->copy()->subDays($decision['keep_days']);

            if ($run->created_at->greaterThan($cutoff)) {
                continue;
            }

            $ageDays = max(0, $run->created_at->diffInDays($now));

            $sample[] = [
                'id' => (int) $run->getKey(),
                'operation' => (string) $run->operation,
                'status' => $run->status->value,
                'created_at' => $run->created_at->format('Y-m-d H:i:s'),
                'age_days' => (int) $ageDays,
                'policy' => $decision['policy'],
                'keep_days' => $decision['keep_days'],
                'reason' => $decision['reason'],
            ];

            if ($this->hasExternalizedOutput($run)) {
                $externalizedOutput++;
            }

            if (! array_key_exists($decision['policy'], $byPolicy)) {
                $byPolicy[$decision['policy']] = [
                    'label' => $decision['reason'],
                    'keep_days' => $decision['keep_days'],
                    'count' => 0,
                ];
            }

            $byPolicy[$decision['policy']]['count']++;

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
        return (bool) $this->config->get('checkpoint.retention.enabled', true);
    }

    public function keepDaysForRun(CommandRun $run): int
    {
        $decision = $this->decisionForRun($run);

        return $decision['keep_days'];
    }

    /**
     * @param  Builder<CommandRun>  $query
     */
    public function applyEligibleRetentionPredicate(Builder $query, Carbon $now): void
    {
        $query->where(function (Builder $where) use ($now): void {
            $where->where(function (Builder $failed) use ($now): void {
                $failed
                    ->where('status', CommandRunStatus::Failed)
                    ->where('created_at', '<=', $now->copy()->subDays($this->failedDays()));
            });

            $candidateNonFailedDays = $this->candidateNonFailedDays();
            $defaultCutoff = $now->copy()->subDays($candidateNonFailedDays);

            $where->orWhere(function (Builder $others) use ($defaultCutoff): void {
                $others
                    ->whereIn('status', [
                        CommandRunStatus::Succeeded,
                        CommandRunStatus::Cancelled,
                    ])
                    ->where('created_at', '<=', $defaultCutoff);
            });
        });
    }

    public function candidateNonFailedDays(): int
    {
        $days = [$this->defaultDays(), ...array_values($this->tierDays())];

        return max(1, min($days));
    }

    /**
     * @return array{policy:string,keep_days:int,reason:string}
     */
    private function decisionForRun(CommandRun $run): array
    {
        if ($run->status === CommandRunStatus::Failed) {
            return [
                'policy' => 'failed',
                'keep_days' => $this->failedDays(),
                'reason' => 'failed runs retention policy',
            ];
        }

        $tier = $this->storageTier($run);
        $tiers = $this->tierDays();

        if ($tier !== null && array_key_exists($tier, $tiers)) {
            return [
                'policy' => 'tier:'.$tier,
                'keep_days' => $tiers[$tier],
                'reason' => sprintf('tiered retention policy for %s storage', $tier),
            ];
        }

        return [
            'policy' => 'default',
            'keep_days' => $this->defaultDays(),
            'reason' => 'default retention policy',
        ];
    }

    private function failedDays(): int
    {
        return max(1, (int) $this->config->get('checkpoint.retention.failed_days', 365));
    }

    private function defaultDays(): int
    {
        return max(1, (int) $this->config->get('checkpoint.retention.default_days', 90));
    }

    /**
     * @return array<string,int>
     */
    private function tierDays(): array
    {
        $configured = $this->config->get('checkpoint.retention.tiers', []);

        if (! is_array($configured)) {
            return [];
        }

        $tiers = [];

        foreach ($configured as $tier => $days) {
            if (! is_string($tier)) {
                continue;
            }

            $normalizedTier = strtolower(trim($tier));
            if ($normalizedTier === '') {
                continue;
            }
            if (! preg_match('/^[a-z][a-z0-9_-]*$/', $normalizedTier)) {
                continue;
            }
            if (! is_int($days)) {
                continue;
            }
            if ($days < 1) {
                continue;
            }

            $tiers[$normalizedTier] = $days;
        }

        return $tiers;
    }

    private function storageTier(CommandRun $run): ?string
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $storage = is_array($metadata['storage'] ?? null) ? $metadata['storage'] : [];
        $class = $storage['class'] ?? null;

        if (! is_string($class)) {
            return null;
        }

        $normalized = strtolower(trim($class));

        return $normalized === '' ? null : $normalized;
    }

    private function hasExternalizedOutput(CommandRun $run): bool
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $outputStorage = is_array($metadata['output_storage'] ?? null) ? $metadata['output_storage'] : [];

        return ($outputStorage['externalized'] ?? false) === true;
    }
}
