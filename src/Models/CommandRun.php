<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use AdityaaCodes\LaravelCheckpoint\Database\Factories\CommandRunFactory;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Services\CommandOutputStore;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @api
 *
 * @property int $id
 * @property string|null $requested_by_type
 * @property int|string|null $requested_by_id
 * @property string $operation
 * @property string|null $argument_text
 * @property string|null $backup_type
 * @property string|null $backup_label
 * @property string|null $stanza
 * @property string|null $driver_name
 * @property int|null $repository
 * @property string|null $verification_state
 * @property string|null $restore_target
 * @property string|null $restore_confirmation_satisfied_via
 * @property int|null $restore_verified_signal_run_id
 * @property string|null $artifact_path
 * @property int|null $backup_size_bytes
 * @property int|null $duration_seconds
 * @property int|null $throughput_bytes_per_second
 * @property array<string, mixed>|null $metadata
 * @property CommandRunStatus $status
 * @property string|null $command_line
 * @property string|null $command_output
 * @property int|null $exit_code
 * @property int $attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $last_known_good_at
 * @property Carbon|null $orphan_recovery_claimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder<self>
 */
class CommandRun extends Model
{
    /** @use HasFactory<CommandRunFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $guarded = [];

    protected static function newFactory(): CommandRunFactory
    {
        return CommandRunFactory::new();
    }

    /** @return array<string, class-string|string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'backup_size_bytes' => 'integer',
            'duration_seconds' => 'integer',
            'exit_code' => 'integer',
            'metadata' => 'array',
            'repository' => 'integer',
            'restore_verified_signal_run_id' => 'integer',
            'status' => CommandRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'orphan_recovery_claimed_at' => 'datetime',
            'throughput_bytes_per_second' => 'integer',
            'verified_at' => 'datetime',
            'last_known_good_at' => 'datetime',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return sprintf(
            '%scommand_runs',
            config('checkpoint.table_prefix', 'db_ops_'),
        );
    }

    /** @return MorphTo<Model, $this> */
    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', CommandRunStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function running(Builder $query): void
    {
        $query->where('status', CommandRunStatus::Running);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function succeeded(Builder $query): void
    {
        $query->where('status', CommandRunStatus::Succeeded);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function failed(Builder $query): void
    {
        $query->where('status', CommandRunStatus::Failed);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function terminal(Builder $query): void
    {
        $query->whereIn('status', [
            CommandRunStatus::Succeeded,
            CommandRunStatus::Failed,
            CommandRunStatus::Cancelled,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function verified(Builder $query): void
    {
        $query->where('verification_state', 'verified');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function lastKnownGood(Builder $query): void
    {
        $query
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordMetadata(array $attributes): self
    {
        $this->forceFill([
            ...$attributes,
            ...$this->denormalizedMetadataColumns($attributes),
        ])->save();

        return $this;
    }

    public function resolvedDriverName(?string $fallback = null): ?string
    {
        if (is_string($this->driver_name) && $this->driver_name !== '') {
            return $this->driver_name;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $metadataDriver = $metadata['driver'] ?? null;

        if (is_string($metadataDriver) && $metadataDriver !== '') {
            return $metadataDriver;
        }

        return $fallback;
    }

    /**
     * @return array{confirmation_satisfied_via:?string,verified_signal_run_id:?int}
     */
    public function restoreAuditSummary(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
        $confirmation = $this->restore_confirmation_satisfied_via;
        $verifiedSignalRunId = $this->restore_verified_signal_run_id;

        if ($confirmation === null && is_string($restoreAudit['confirmation_satisfied_via'] ?? null)) {
            $confirmation = $restoreAudit['confirmation_satisfied_via'];
        }

        if ($verifiedSignalRunId === null && is_numeric($restoreAudit['verified_signal_run_id'] ?? null)) {
            $verifiedSignalRunId = (int) $restoreAudit['verified_signal_run_id'];
        }

        return [
            'confirmation_satisfied_via' => $confirmation,
            'verified_signal_run_id' => $verifiedSignalRunId,
        ];
    }

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

    public function claimForOrphanRecovery(Carbon $threshold, Carbon $claimExpiresBefore, ?Carbon $claimedAt = null, bool $refresh = true): bool
    {
        $claimedAt ??= now();

        $updated = static::withoutTimestamps(function () use ($claimExpiresBefore, $claimedAt, $threshold): int {
            return static::query()
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
                ]);
        });

        if ($refresh) {
            $this->refresh();
        }

        return $updated === 1;
    }

    public function releaseOrphanRecoveryClaim(Carbon $claimedAt, bool $refresh = true): bool
    {
        $updated = static::withoutTimestamps(function () use ($claimedAt): int {
            return static::query()
                ->whereKey($this->getKey())
                ->where('status', CommandRunStatus::Pending)
                ->where('orphan_recovery_claimed_at', $claimedAt)
                ->update([
                    'orphan_recovery_claimed_at' => null,
                ]);
        });

        if ($refresh) {
            $this->refresh();
        }

        return $updated === 1;
    }

    /** @return Builder<self> */
    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $keepDays = (int) config('checkpoint.schedule.prune_keep_days', 90);
        $keepFailedDays = (int) config('checkpoint.schedule.prune_keep_failed_days', 365);

        return static::query()
            ->where(function (Builder $query) use ($keepDays): void {
                $query
                    ->where('status', '!=', CommandRunStatus::Failed)
                    ->where('created_at', '<=', now()->subDays($keepDays));
            })
            ->orWhere(function (Builder $query) use ($keepFailedDays): void {
                $query
                    ->where('status', CommandRunStatus::Failed)
                    ->where('created_at', '<=', now()->subDays($keepFailedDays));
            });
    }

    public function pruneAll(): int
    {
        $deleted = 0;

        $this->prunable()
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(250, function ($runs) use (&$deleted): void {
                $ids = [];

                foreach ($runs as $run) {
                    if (! $run instanceof self) {
                        continue;
                    }

                    $this->outputStore()->cleanup($run);
                    $ids[] = $run->getKey();
                }

                if ($ids !== []) {
                    $deleted += static::query()->whereKey($ids)->delete();
                }
            });

        return $deleted;
    }

    public function resolvedCommandOutput(): ?string
    {
        return $this->outputStore()->resolve($this);
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

    private function outputStore(): CommandOutputStore
    {
        return resolve(CommandOutputStore::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function denormalizedMetadataColumns(array $attributes): array
    {
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null;

        if ($metadata === null) {
            return [];
        }

        $columns = [
            'driver_name' => null,
            'restore_confirmation_satisfied_via' => null,
            'restore_verified_signal_run_id' => null,
        ];

        if (is_string($metadata['driver'] ?? null) && $metadata['driver'] !== '') {
            $columns['driver_name'] = $metadata['driver'];
        }

        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : null;

        if (is_array($restoreAudit)) {
            if (is_string($restoreAudit['confirmation_satisfied_via'] ?? null) && $restoreAudit['confirmation_satisfied_via'] !== '') {
                $columns['restore_confirmation_satisfied_via'] = $restoreAudit['confirmation_satisfied_via'];
            }

            if (is_numeric($restoreAudit['verified_signal_run_id'] ?? null)) {
                $columns['restore_verified_signal_run_id'] = (int) $restoreAudit['verified_signal_run_id'];
            }
        }

        return $columns;
    }
}
