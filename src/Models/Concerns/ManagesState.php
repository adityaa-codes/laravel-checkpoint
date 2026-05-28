<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models\Concerns;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use Illuminate\Support\Carbon;

trait ManagesState
{
    public function markAsRunning(): self
    {
        $this->claimPendingExecution();

        return $this;
    }

    public function claimPendingExecution(?Carbon $startedAt = null, bool $refresh = true): bool
    {
        $startedAt ??= now();

        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('status', CommandRunStatus::Pending)
            ->update([
                'status' => CommandRunStatus::Running,
                'started_at' => $startedAt,
                'heartbeat_at' => $startedAt,
                'updated_at' => $startedAt,
                'orphan_recovery_claimed_at' => null,
            ]);

        if ($refresh) {
            $this->refresh();
        }

        return $updated === 1;
    }

    public function markAsSucceeded(int $exitCode, string $output): self
    {
        $finishedAt = now();

        static::query()
            ->whereKey($this->getKey())
            ->where('status', CommandRunStatus::Running)
            ->update([
                'status' => CommandRunStatus::Succeeded,
                'exit_code' => $exitCode,
                'command_output' => $output,
                'finished_at' => $finishedAt,
                'orphan_recovery_claimed_at' => null,
                'updated_at' => $finishedAt,
                ...$this->timingMetrics($finishedAt),
            ]);

        $this->refresh();

        return $this;
    }

    public function markAsFailed(int $exitCode = -1, string $output = ''): self
    {
        $finishedAt = now();

        static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', [CommandRunStatus::Pending, CommandRunStatus::Running])
            ->update([
                'status' => CommandRunStatus::Failed,
                'exit_code' => $exitCode,
                'command_output' => $output,
                'finished_at' => $finishedAt,
                'orphan_recovery_claimed_at' => null,
                'updated_at' => $finishedAt,
                ...$this->timingMetrics($finishedAt),
            ]);

        $this->refresh();

        return $this;
    }

    /**
     * @return array{duration_seconds:int|null,throughput_bytes_per_second:int|null}
     */
    private function timingMetrics(Carbon $finishedAt): array
    {
        $durationSeconds = null;

        if ($this->started_at instanceof Carbon) {
            $durationSeconds = (int) max(1, $this->started_at->diffInSeconds($finishedAt));
        }

        $throughput = null;

        if ($durationSeconds !== null && is_int($this->backup_size_bytes) && $this->backup_size_bytes > 0) {
            $throughput = (int) floor($this->backup_size_bytes / $durationSeconds);
        }

        return [
            'duration_seconds' => $durationSeconds,
            'throughput_bytes_per_second' => $throughput,
        ];
    }
}
