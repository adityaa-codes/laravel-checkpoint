<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use AdityaaCodes\LaravelCheckpoint\Database\Factories\VerificationRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @api
 *
 * @property int $id
 * @property int $command_run_id
 * @property string $verification_type
 * @property string $status
 * @property Carbon|null $verified_at
 * @property array<string,mixed>|null $metadata
 * @property string|null $error_detail
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @mixin Builder<self>
 */
class VerificationRun extends Model
{
    /** @use HasFactory<VerificationRunFactory> */
    use HasFactory;

    /** @var array<int, string> */
    protected $guarded = [];

    protected static function newFactory(): VerificationRunFactory
    {
        return VerificationRunFactory::new();
    }

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'command_run_id' => 'integer',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    #[\Override]
    public function getTable(): string
    {
        return sprintf(
            '%sverification_runs',
            config('checkpoint.table_prefix', 'db_ops_'),
        );
    }

    /** @return BelongsTo<CommandRun, $this> */
    public function commandRun(): BelongsTo
    {
        return $this->belongsTo(CommandRun::class);
    }
}
