<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Drivers;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\DriverContext;

/** @internal */
final class MysqlDriverLogContext
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function build(DriverContext $context, CommandRun $run, array $extra = [], ?int $restoreDecisionEventCount = null): array
    {
        return collect([
            'run_id' => $run->getKey(),
            'operation' => $context->operation,
            'driver' => 'mysql',
            'backup_type' => $run->backup_type,
            'restore_target' => $run->restore_target,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'duration_seconds' => $run->duration_seconds,
            'restore_decision_event_count' => $restoreDecisionEventCount,
            ...$extra,
        ])->filter(static fn (mixed $value): bool => $value !== null)->all();
    }

    /**
     * @param  array<string, mixed>  $plannedMetadata
     * @param  array<string, mixed>  $restoreAudit
     * @return array<string, mixed>
     */
    public static function mergeRestoreAuditMetadata(array $plannedMetadata, array $restoreAudit): array
    {
        if ($restoreAudit === []) {
            return $plannedMetadata;
        }

        $metadata = is_array($plannedMetadata['metadata'] ?? null) ? $plannedMetadata['metadata'] : [];

        return [
            ...$plannedMetadata,
            'metadata' => [
                ...$metadata,
                ...$restoreAudit,
            ],
        ];
    }
}
