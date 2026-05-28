<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models\Concerns;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

trait ManagesHeartbeat
{
    public function recordHeartbeat(?Carbon $heartbeatAt = null, bool $refresh = false): bool
    {
        $heartbeatAt ??= now();

        $updated = static::withoutTimestamps(fn (): int => static::query()
            ->whereKey($this->getKey())
            ->where('status', CommandRunStatus::Running)
            ->update([
                'heartbeat_at' => $heartbeatAt,
                'updated_at' => $heartbeatAt,
            ]));

        if ($refresh) {
            $this->refresh();
        }

        return $updated === 1;
    }

    public function recordHeartbeatIfDue(?Carbon $heartbeatAt = null, ?int $intervalSeconds = null, bool $refresh = false): bool
    {
        $heartbeatAt ??= now();
        $intervalSeconds = max(1, $intervalSeconds ?? (int) config('checkpoint.queue.heartbeat_interval_seconds', 30));
        $cutoff = $heartbeatAt->copy()->subSeconds($intervalSeconds);

        $updated = static::withoutTimestamps(fn (): int => static::query()
            ->whereKey($this->getKey())
            ->where('status', CommandRunStatus::Running)
            ->where(function (Builder $query) use ($cutoff): void {
                $query
                    ->whereNull('heartbeat_at')
                    ->orWhere('heartbeat_at', '<=', $cutoff);
            })
            ->update([
                'heartbeat_at' => $heartbeatAt,
                'updated_at' => $heartbeatAt,
            ]));

        if ($refresh) {
            $this->refresh();
        }

        return $updated === 1;
    }

    public function claimForOrphanRecovery(Carbon $threshold, Carbon $claimExpiresBefore, ?Carbon $claimedAt = null, bool $refresh = true): bool
    {
        $claimedAt ??= now();

        $updated = static::withoutTimestamps(fn (): int => static::query()
            ->whereKey($this->getKey())
            ->where('status', CommandRunStatus::Pending)
            ->where('updated_at', '<', $threshold)
            ->where(function (Builder $query) use ($claimExpiresBefore): void {
                $query
                    ->whereNull('orphan_recovery_claimed_at')
                    ->orWhere('orphan_recovery_claimed_at', '<', $claimExpiresBefore);
            })
            ->update([
                'orphan_recovery_claimed_at' => $claimedAt,
            ]));

        if ($refresh) {
            $this->refresh();
        }

        return $updated === 1;
    }

    public function releaseOrphanRecoveryClaim(Carbon $claimedAt, bool $refresh = true): bool
    {
        $updated = static::withoutTimestamps(fn (): int => static::query()
            ->whereKey($this->getKey())
            ->where('status', CommandRunStatus::Pending)
            ->where('orphan_recovery_claimed_at', $claimedAt)
            ->update([
                'orphan_recovery_claimed_at' => null,
            ]));

        if ($refresh) {
            $this->refresh();
        }

        return $updated === 1;
    }
}
