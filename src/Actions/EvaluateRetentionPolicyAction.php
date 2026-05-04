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
            $cutoff = $now->copy()->subDays($decision['keep_days']);

            if ($run->created_at->greaterThan($cutoff)) {
                continue;
            }

            $ageDays = max(0, (int) $run->created_at->diffInDays($now));

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
        $config = $this->config->get('checkpoint.cleanup', []);

        return is_array($config) && $config !== [];
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
        $failedCutoff = $now->copy()->subDays(365);

        $query->where(function (Builder $where) use ($now, $failedCutoff): void {
            $where->where(function (Builder $failed) use ($failedCutoff): void {
                $failed
                    ->where('status', CommandRunStatus::Failed)
                    ->where('created_at', '<=', $failedCutoff);
            });

            $gfsDays = $this->gfsTotalDays();
            $defaultCutoff = $now->copy()->subDays($gfsDays);

            $where->orWhere(function (Builder $others) use ($defaultCutoff): void {
                $others
                    ->whereIn('status', [CommandRunStatus::Succeeded, CommandRunStatus::Cancelled])
                    ->where('created_at', '<=', $defaultCutoff);
            });
        });
    }

    public function gfsTotalDays(): int
    {
        $config = $this->config->get('checkpoint.cleanup', []);

        if (! is_array($config)) {
            return 90;
        }

        $keepAll = max(1, (int) ($config['keep_all_backups_for_days'] ?? 7));
        $keepDaily = max(0, (int) ($config['keep_daily_backups_for_days'] ?? 16));
        $keepWeekly = max(0, (int) ($config['keep_weekly_backups_for_weeks'] ?? 8));
        $keepMonthly = max(0, (int) ($config['keep_monthly_backups_for_months'] ?? 4));
        $keepYearly = max(0, (int) ($config['keep_yearly_backups_for_years'] ?? 2));

        return $keepAll + $keepDaily + ($keepWeekly * 7) + ($keepMonthly * 30) + ($keepYearly * 365);
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
                'reason' => 'Failed runs: 365 days retention',
            ];
        }

        $config = $this->config->get('checkpoint.cleanup', []);
        $keepAll = max(1, (int) ($config['keep_all_backups_for_days'] ?? 7));
        $keepDaily = max(0, (int) ($config['keep_daily_backups_for_days'] ?? 16));
        $keepWeekly = max(0, (int) ($config['keep_weekly_backups_for_weeks'] ?? 8));
        $keepMonthly = max(0, (int) ($config['keep_monthly_backups_for_months'] ?? 4));
        $keepYearly = max(0, (int) ($config['keep_yearly_backups_for_years'] ?? 2));

        $totalDays = $this->gfsTotalDays();
        $age = max(0, (int) $run->created_at?->diffInDays(now()));

        if ($age <= $keepAll) {
            return [
                'policy' => 'keep_all',
                'keep_days' => $keepAll,
                'reason' => sprintf('Keep all backups (%d days)', $keepAll),
            ];
        }

        if ($age <= $keepAll + $keepDaily) {
            return [
                'policy' => 'keep_daily',
                'keep_days' => $keepAll + $keepDaily,
                'reason' => sprintf('Keep daily backups (%d days)', $keepAll + $keepDaily),
            ];
        }

        if ($age <= $keepAll + $keepDaily + ($keepWeekly * 7)) {
            return [
                'policy' => 'keep_weekly',
                'keep_days' => $keepAll + $keepDaily + ($keepWeekly * 7),
                'reason' => sprintf('Keep weekly backups (%d days)', $keepAll + $keepDaily + ($keepWeekly * 7)),
            ];
        }

        if ($age <= $totalDays) {
            return [
                'policy' => 'keep_monthly',
                'keep_days' => $totalDays,
                'reason' => sprintf('Keep monthly/yearly backups (%d days)', $totalDays),
            ];
        }

        return [
            'policy' => 'expired',
            'keep_days' => 0,
            'reason' => sprintf('Expired — beyond %d day retention window', $totalDays),
        ];
    }

    private function hasExternalizedOutput(CommandRun $run): bool
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $outputStorage = is_array($metadata['output_storage'] ?? null) ? $metadata['output_storage'] : [];

        return ($outputStorage['externalized'] ?? false) === true;
    }
}
