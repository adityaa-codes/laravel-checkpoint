<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommandRun extends Model
{
    use MassPrunable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'exit_code' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return sprintf(
            '%scommand_runs',
            config('checkpoint.table_prefix', 'db_ops_'),
        );
    }

    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCEEDED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SUCCEEDED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function markAsRunning(): self
    {
        $this->forceFill([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        return $this;
    }

    public function markAsSucceeded(int $exitCode, string $output): self
    {
        $this->forceFill([
            'status' => self::STATUS_SUCCEEDED,
            'exit_code' => $exitCode,
            'command_output' => $output,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function markAsFailed(int $exitCode = -1, string $output = ''): self
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'exit_code' => $exitCode,
            'command_output' => $output,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function prunable(): Builder
    {
        $keepDays = (int) config('checkpoint.schedule.prune_keep_days', 90);
        $keepFailedDays = (int) config('checkpoint.schedule.prune_keep_failed_days', 365);

        return static::query()
            ->where(function (Builder $query) use ($keepDays): void {
                $query
                    ->where('status', '!=', self::STATUS_FAILED)
                    ->where('created_at', '<=', now()->subDays($keepDays));
            })
            ->orWhere(function (Builder $query) use ($keepFailedDays): void {
                $query
                    ->where('status', self::STATUS_FAILED)
                    ->where('created_at', '<=', now()->subDays($keepFailedDays));
            });
    }
}
