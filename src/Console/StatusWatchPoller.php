<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

final class StatusWatchPoller
{
    /**
     * @return array{runningCount:int,elapsed:int}|null Returns null when done, array when polling continues
     */
    public function poll(int $intervalSeconds, int $timeout, int $startTime, int $iteration, int $backoff): ?array
    {
        if (time() - $startTime > $timeout) {
            return [
                'timedOut' => true,
                'elapsed' => time() - $startTime,
                'remainingJobs' => (int) CommandRun::query()->whereIn('status', ['pending', 'running'])->count(),
            ];
        }

        $runningCount = (int) CommandRun::query()
            ->whereIn('status', ['pending', 'running'])
            ->count();

        if ($runningCount === 0) {
            return [
                'timedOut' => false,
                'completed' => true,
                'elapsed' => time() - $startTime,
            ];
        }

        $elapsed = time() - $startTime;

        $nextBackoff = min($backoff * 2, 30);
        sleep($backoff);

        return [
            'polling' => true,
            'runningCount' => $runningCount,
            'elapsed' => $elapsed,
            'nextBackoff' => $nextBackoff,
            'nextIteration' => $iteration + 1,
            'isFirstIteration' => $iteration === 0,
            'isMilestoneIteration' => $iteration % 5 === 0,
        ];
    }

    public function initialBackoff(int $intervalSeconds): int
    {
        return min($intervalSeconds, 1);
    }
}
