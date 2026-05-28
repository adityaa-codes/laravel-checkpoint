<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** @internal */
final readonly class RestoreVerificationSignalLocator
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     required: bool,
     *     run_id: int|null,
     *     operation: string|null,
     *     backup_label: string|null,
     *     artifact_path: string|null,
     *     last_known_good_at: string|null
     * }
     */
    public function locate(CommandRun $run, string $restoreTarget, array $context): array
    {
        if (! $this->config->get('checkpoint.restore.require_verified_backup', false)) {
            return [
                'required' => false,
                'run_id' => null,
                'operation' => null,
                'backup_label' => null,
                'artifact_path' => null,
                'last_known_good_at' => null,
            ];
        }

        $query = CommandRun::query()
            ->succeeded()
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->latest('id');

        /** @var CommandRun|null $verifiedRun */
        $verifiedRun = match ($run->operation) {
            'logical_restore_file', 'logical_restore_latest' => $this->matchingLogical($query, $restoreTarget, $context),
            'pitr_restore' => $this->matchingPitr($query, $context),
            default => $query->first(),
        };

        if (! $verifiedRun instanceof CommandRun) {
            throw new ConfigurationException(
                "Restore operation [{$run->operation}] requires a verified backup signal before execution.",
            );
        }

        return [
            'required' => true,
            'run_id' => (int) $verifiedRun->getKey(),
            'operation' => $verifiedRun->operation,
            'backup_label' => $verifiedRun->backup_label,
            'artifact_path' => $verifiedRun->artifact_path,
            'last_known_good_at' => $verifiedRun->last_known_good_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param  Builder<CommandRun>  $query
     * @param  array<string, mixed>  $context
     */
    private function matchingLogical(Builder $query, string $restoreTarget, array $context): ?CommandRun
    {
        $query
            ->where('operation', 'logical_backup')
            ->where('artifact_path', $restoreTarget);

        $expectedSnapshot = $context['restore_target_snapshot'] ?? null;

        if ($expectedSnapshot === null) {
            return $query->first();
        }

        /** @var Collection<int, CommandRun> $candidates */
        $candidates = $query->limit(10)->get();

        return $candidates->first(
            fn (CommandRun $candidate): bool => $this->artifactSnapshotMatches($candidate, $expectedSnapshot)
                && $this->provenanceMatches($candidate, $context),
        );
    }

    /**
     * @param  Builder<CommandRun>  $query
     * @param  array<string, mixed>  $context
     */
    private function matchingPitr(Builder $query, array $context): ?CommandRun
    {
        $baseTarget = $this->pitrBaseTarget($context);
        $binlogFiles = $this->pitrBinlogFiles($context);

        if ($baseTarget === null || $baseTarget === '') {
            throw new ConfigurationException(
                'pitr_restore requires a baseline logical backup artifact when checkpoint.restore.require_verified_backup is enabled.',
            );
        }

        if ($binlogFiles === []) {
            throw new ConfigurationException(
                'pitr_restore requires a non-empty binlog chain when checkpoint.restore.require_verified_backup is enabled.',
            );
        }

        $query
            ->where('operation', 'logical_backup')
            ->where('artifact_path', $baseTarget);

        /** @var Collection<int, CommandRun> $candidates */
        $candidates = $query->limit(10)->get();

        return $candidates->first(
            fn (CommandRun $candidate): bool => $this->provenanceMatches($candidate, $context),
        );
    }

    /**
     * @param  array<string, mixed>  $expectedSnapshot
     */
    private function artifactSnapshotMatches(CommandRun $candidate, array $expectedSnapshot): bool
    {
        $metadata = $candidate->metadata ?? [];
        $artifactSnapshot = $metadata['artifact_snapshot'] ?? null;

        if ($artifactSnapshot === null) {
            return false;
        }

        foreach (['path', 'file_type', 'device', 'inode', 'mtime', 'size', 'content_signature'] as $key) {
            if (($artifactSnapshot[$key] ?? null) !== ($expectedSnapshot[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function provenanceMatches(CommandRun $candidate, array $context): bool
    {
        $contextMetadata = $context['metadata'] ?? [];
        $expectedDriver = str($contextMetadata['driver'] ?? '')->trim()->value();
        $expectedDatabase = str($contextMetadata['database'] ?? '')->trim()->value();

        if ($expectedDriver !== '' && $candidate->resolvedDriverName() !== $expectedDriver) {
            return false;
        }

        if ($expectedDatabase !== '') {
            $candidateMetadata = $candidate->metadata ?? [];
            $candidateDatabase = str($candidateMetadata['database'] ?? '')->trim()->value();

            if ($candidateDatabase !== $expectedDatabase) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    public function pitrBinlogFiles(array $context): array
    {
        $contextMetadata = $context['metadata'] ?? [];

        return collect($contextMetadata['binlog_files'] ?? [])
            ->map(fn (mixed $item): string => str($item)->trim()->value())
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function pitrBaseTarget(array $context): ?string
    {
        $baseTarget = str($context['pitr_base_target'] ?? '')->trim()->value();

        return $baseTarget !== '' ? $baseTarget : null;
    }
}
