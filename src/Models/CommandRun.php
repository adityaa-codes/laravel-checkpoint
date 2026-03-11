<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use AdityaaCodes\LaravelCheckpoint\Database\Factories\CommandRunFactory;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
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
 * @property int|null $repository
 * @property string|null $verification_state
 * @property string|null $restore_target
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder<self>
 */
class CommandRun extends Model
{
    /** @use HasFactory<CommandRunFactory> */
    use HasFactory;

    use MassPrunable;

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
            'status' => CommandRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
        $this->forceFill($attributes)->save();

        return $this;
    }

    public function markAsRunning(): self
    {
        $this->forceFill([
            'status' => CommandRunStatus::Running,
            'started_at' => now(),
        ])->save();

        return $this;
    }

    public function markAsSucceeded(int $exitCode, string $output): self
    {
        $finishedAt = now();

        $this->forceFill([
            'status' => CommandRunStatus::Succeeded,
            'exit_code' => $exitCode,
            'command_output' => $output,
            'finished_at' => $finishedAt,
            ...$this->timingMetrics($finishedAt),
        ])->save();

        return $this;
    }

    public function markAsFailed(int $exitCode = -1, string $output = ''): self
    {
        $finishedAt = now();

        $this->forceFill([
            'status' => CommandRunStatus::Failed,
            'exit_code' => $exitCode,
            'command_output' => $output,
            'finished_at' => $finishedAt,
            ...$this->timingMetrics($finishedAt),
        ])->save();

        return $this;
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
