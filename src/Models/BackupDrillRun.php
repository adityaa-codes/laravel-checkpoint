<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BackupDrillRun extends Model
{
    protected $guarded = [];

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

    public function scopeLatest(Builder $query): Builder
    {
        return $query->latest('executed_at');
    }
}
