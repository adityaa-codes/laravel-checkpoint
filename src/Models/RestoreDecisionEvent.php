<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @api
 *
 * @property int $id
 * @property int|null $command_run_id
 * @property string $operation
 * @property string $decision
 * @property string $reason
 * @property array<string,mixed>|null $payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RestoreDecisionEvent extends Model
{
    /** @var array<int, string> */
    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'command_run_id' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return sprintf(
            '%srestore_decision_events',
            config('checkpoint.table_prefix', 'db_ops_'),
        );
    }
}
