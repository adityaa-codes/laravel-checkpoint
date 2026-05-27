<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models;

use AdityaaCodes\LaravelCheckpoint\Database\Factories\CommandRunFactory;
use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\Concerns\HasCommandOutput;
use AdityaaCodes\LaravelCheckpoint\Models\Concerns\HasRestoreAudit;
use AdityaaCodes\LaravelCheckpoint\Models\Concerns\ManagesHeartbeat;
use AdityaaCodes\LaravelCheckpoint\Models\Concerns\ManagesMetadata;
use AdityaaCodes\LaravelCheckpoint\Models\Concerns\ManagesState;
use AdityaaCodes\LaravelCheckpoint\Models\Concerns\PrunesCommandRuns;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $restore_post_verification_result
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
 * @property Carbon|null $heartbeat_at
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
    use HasCommandOutput;

    /** @use HasFactory<CommandRunFactory> */
    use HasFactory;

    use HasRestoreAudit;
    use ManagesHeartbeat;
    use ManagesMetadata;
    use ManagesState;
    use PrunesCommandRuns;

    public static string $tablePrefix = 'db_ops_';

    /** @var array<int, string> */
    protected $fillable = [
        'operation',
        'argument_text',
        'backup_type',
        'backup_label',
        'stanza',
        'driver_name',
        'repository',
        'verification_state',
        'restore_target',
        'restore_confirmation_satisfied_via',
        'restore_verified_signal_run_id',
        'restore_post_verification_result',
        'artifact_path',
        'backup_size_bytes',
        'duration_seconds',
        'throughput_bytes_per_second',
        'metadata',
        'status',
        'command_line',
        'command_output',
        'exit_code',
        'attempts',
        'created_at',
        'updated_at',
        'started_at',
        'heartbeat_at',
        'finished_at',
        'verified_at',
        'last_known_good_at',
        'orphan_recovery_claimed_at',
        'requested_by_type',
        'requested_by_id',
    ];

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
            'restore_post_verification_result' => 'string',
            'status' => CommandRunStatus::class,
            'started_at' => 'datetime',
            'heartbeat_at' => 'datetime',
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
        return sprintf('%scommand_runs', static::$tablePrefix);
    }

    /** @return MorphTo<Model, $this> */
    public function requestedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<VerificationRun, $this> */
    public function verificationRuns(): HasMany
    {
        return $this->hasMany(VerificationRun::class);
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
}
