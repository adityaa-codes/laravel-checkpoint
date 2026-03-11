<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use AdityaaCodes\LaravelCheckpoint\Database\Factories\CommandRunFactory;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $requested_by_type
 * @property int|string|null $requested_by_id
 * @property string $operation
 * @property string|null $argument_text
 * @property CommandRunStatus $status
 * @property string|null $command_line
 * @property string|null $command_output
 * @property int|null $exit_code
 * @property int $attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
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
            'exit_code' => 'integer',
            'status' => CommandRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CommandRunStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', CommandRunStatus::Running);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSucceeded(Builder $query): Builder
    {
        return $query->where('status', CommandRunStatus::Succeeded);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', CommandRunStatus::Failed);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            CommandRunStatus::Succeeded,
            CommandRunStatus::Failed,
            CommandRunStatus::Cancelled,
        ]);
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
        $this->forceFill([
            'status' => CommandRunStatus::Succeeded,
            'exit_code' => $exitCode,
            'command_output' => $output,
            'finished_at' => now(),
        ])->save();

        return $this;
    }

    public function markAsFailed(int $exitCode = -1, string $output = ''): self
    {
        $this->forceFill([
            'status' => CommandRunStatus::Failed,
            'exit_code' => $exitCode,
            'command_output' => $output,
            'finished_at' => now(),
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
}
