<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Support\Carbon;

final readonly class BreakdownAggregator
{
    /**
     * @return array<string,mixed>
     */
    public function breakdown(): array
    {
        $failedRunsWindowHours = 24;
        $failedRunsWindowStart = now()->subHours($failedRunsWindowHours);
        $groups = [];
        $totalRuns = 0;
        $totalFailedRuns24h = 0;

        $runs = CommandRun::query()
            ->select([
                'driver_name',
                'repository',
                'stanza',
                'status',
                'started_at',
                'finished_at',
                'created_at',
            ])
            ->get();

        foreach ($runs as $run) {
            $driver = is_string($run->driver_name) && $run->driver_name !== '' ? $run->driver_name : 'unknown';
            $repository = $run->repository;
            $stanza = is_string($run->stanza) && $run->stanza !== '' ? $run->stanza : null;
            $key = sprintf(
                'driver:%s|repo:%s%s',
                $driver,
                $repository === null ? 'none' : (string) $repository,
                $stanza !== null ? '|stanza:'.$stanza : '',
            );

            if (! array_key_exists($key, $groups)) {
                $groups[$key] = [
                    'driver' => $driver,
                    'repository' => $repository,
                    'stanza' => $stanza,
                    'runs' => [
                        'total' => 0,
                        'succeeded' => 0,
                        'failed' => 0,
                        'cancelled' => 0,
                        'running' => 0,
                        'pending' => 0,
                        'failed_24h' => 0,
                    ],
                    'failure_rate_percent' => 0.0,
                    'health_status' => 'pass',
                    'latest_activity_at' => null,
                    'latest_failure_at' => null,
                ];
            }

            $groups[$key]['runs']['total']++;
            $totalRuns++;

            $status = (string) $run->status->value;

            if ($status === CommandRunStatus::Succeeded->value) {
                $groups[$key]['runs']['succeeded']++;
            } elseif ($status === CommandRunStatus::Failed->value) {
                $groups[$key]['runs']['failed']++;

                if ($run->created_at instanceof Carbon && $run->created_at->greaterThanOrEqualTo($failedRunsWindowStart)) {
                    $groups[$key]['runs']['failed_24h']++;
                    $totalFailedRuns24h++;
                }
            } elseif ($status === CommandRunStatus::Cancelled->value) {
                $groups[$key]['runs']['cancelled']++;
            } elseif ($status === CommandRunStatus::Running->value) {
                $groups[$key]['runs']['running']++;
            } elseif ($status === CommandRunStatus::Pending->value) {
                $groups[$key]['runs']['pending']++;
            }

            $latestActivity = $run->finished_at ?? $run->started_at ?? $run->created_at;

            if (
                $latestActivity instanceof Carbon
                && (
                    ! $groups[$key]['latest_activity_at'] instanceof Carbon
                    || $latestActivity->greaterThan($groups[$key]['latest_activity_at'])
                )
            ) {
                $groups[$key]['latest_activity_at'] = $latestActivity;
            }

            if ($status === CommandRunStatus::Failed->value) {
                $latestFailure = $run->finished_at ?? $run->started_at ?? $run->created_at;

                if (
                    $latestFailure instanceof Carbon
                    && (
                        ! $groups[$key]['latest_failure_at'] instanceof Carbon
                        || $latestFailure->greaterThan($groups[$key]['latest_failure_at'])
                    )
                ) {
                    $groups[$key]['latest_failure_at'] = $latestFailure;
                }
            }
        }

        foreach ($groups as &$group) {
            $total = $group['runs']['total'];
            $failed = $group['runs']['failed'];
            $failed24h = $group['runs']['failed_24h'];
            $running = $group['runs']['running'];
            $pending = $group['runs']['pending'];

            $group['failure_rate_percent'] = round(($failed / $total) * 100, 1);
            $group['health_status'] = match (true) {
                $failed24h > 0 => 'fail',
                $failed > 0 || $running > 0 || $pending > 0 => 'warn',
                default => 'pass',
            };
            $group['latest_activity_at'] = $group['latest_activity_at'] instanceof Carbon
                ? $group['latest_activity_at']->format('Y-m-d H:i:s')
                : null;
            $group['latest_failure_at'] = $group['latest_failure_at'] instanceof Carbon
                ? $group['latest_failure_at']->format('Y-m-d H:i:s')
                : null;
        }
        unset($group);

        ksort($groups);

        return [
            'window' => [
                'failed_runs_hours' => $failedRunsWindowHours,
            ],
            'totals' => [
                'groups' => count($groups),
                'runs' => $totalRuns,
                'failed_runs_24h' => $totalFailedRuns24h,
            ],
            'by_target' => $groups,
        ];
    }
}
