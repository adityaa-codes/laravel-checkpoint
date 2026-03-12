<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use AdityaaCodes\LaravelCheckpoint\Database\Factories\BackupDrillRunFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @api
 *
 * @property int $id
 * @property string $run_uuid
 * @property string|null $marker_uuid
 * @property string|null $marker_email
 * @property int|null $marker_count
 * @property string|null $marker_result
 * @property int|null $rto_target_seconds
 * @property int|null $rto_actual_seconds
 * @property string|null $rto_result
 * @property int|null $rpo_target_seconds
 * @property int|null $rpo_actual_seconds
 * @property string|null $rpo_result
 * @property string $overall_result
 * @property string|null $executed_by
 * @property Carbon $executed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder<self>
 */
class BackupDrillRun extends Model
{
    /** @use HasFactory<BackupDrillRunFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $guarded = [];

    protected static function newFactory(): BackupDrillRunFactory
    {
        return BackupDrillRunFactory::new();
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'marker_count' => 'integer',
            'rto_target_seconds' => 'integer',
            'rto_actual_seconds' => 'integer',
            'rpo_target_seconds' => 'integer',
            'rpo_actual_seconds' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return sprintf(
            '%sbackup_drill_runs',
            config('checkpoint.table_prefix', 'db_ops_'),
        );
    }

    public function isPassing(): bool
    {
        return strtolower((string) $this->overall_result) === 'pass';
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function recent(Builder $query): void
    {
        $query->latest('executed_at');
    }

    /** @return Builder<self> */
    /** @return Builder<static> */
    public function prunable(): Builder
    {
        $keepDays = (int) config('checkpoint.schedule.prune_keep_backup_drill_days', 365);

        return static::query()
            ->where('executed_at', '<=', now()->subDays($keepDays));
    }

    public function pruneAll(): int
    {
        return $this->prunable()->delete();
    }
}
