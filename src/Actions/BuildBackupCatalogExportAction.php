<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final readonly class BuildBackupCatalogExportAction
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array{filters:array{driver:?string,repository:int|null|string,stanza:?string,window_hours:?int},rows:list<array<string,mixed>>}
     */
    public function execute(
        ?string $driverFilter,
        int|string|null $repositoryFilter,
        ?string $stanzaFilter,
        ?int $windowHours,
        int $limit,
    ): array {
        $query = CommandRun::query()
            ->select([
                'id',
                'operation',
                'driver_name',
                'repository',
                'stanza',
                'backup_type',
                'backup_label',
                'artifact_path',
                'backup_size_bytes',
                'status',
                'verification_state',
                'created_at',
                'started_at',
                'finished_at',
                'verified_at',
                'last_known_good_at',
                'metadata',
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('backup_label')
                    ->orWhereNotNull('backup_type')
                    ->orWhereNotNull('artifact_path')
                    ->orWhereNotNull('backup_size_bytes');
            });

        if ($driverFilter !== null) {
            if ($driverFilter === 'none') {
                $query->whereNull('driver_name');
            } else {
                $query->where('driver_name', $driverFilter);
            }
        }

        if ($repositoryFilter !== null) {
            if ($repositoryFilter === 'none') {
                $query->whereNull('repository');
            } else {
                $query->where('repository', (int) $repositoryFilter);
            }
        }

        if ($stanzaFilter !== null) {
            if ($stanzaFilter === 'none') {
                $query->whereNull('stanza');
            } else {
                $query->where('stanza', $stanzaFilter);
            }
        }

        if ($windowHours !== null) {
            $query->where('created_at', '>=', now()->subHours($windowHours));
        }

        $runs = $query->latest()
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();

        /** @var list<int> $runIds */
        $runIds = $runs->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $latestVerifications = $this->latestVerifications($runIds);

        /** @var list<array<string, mixed>> $rows */
        $rows = $runs
            ->map(fn (CommandRun $run): array => $this->rowPayload($run, $latestVerifications[(int) $run->getKey()] ?? null))
            ->values()
            ->all();

        return [
            'filters' => [
                'driver' => $driverFilter,
                'repository' => $repositoryFilter,
                'stanza' => $stanzaFilter,
                'window_hours' => $windowHours,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<int>  $commandRunIds
     * @return array<int,VerificationRun>
     */
    private function latestVerifications(array $commandRunIds): array
    {
        if ($commandRunIds === []) {
            return [];
        }

        /** @var Collection<int, VerificationRun> $runs */
        $runs = VerificationRun::query()
            ->select([
                'id',
                'command_run_id',
                'verification_type',
                'status',
                'verified_at',
                'error_detail',
            ])
            ->whereIn('command_run_id', $commandRunIds)
            ->latest('verified_at')
            ->orderByDesc('id')
            ->get();

        /** @var array<int, VerificationRun> $latestByCommandRun */
        $latestByCommandRun = $runs
            ->unique(static fn (VerificationRun $run): int => (int) $run->command_run_id)
            ->keyBy(static fn (VerificationRun $run): int => (int) $run->command_run_id)
            ->all();

        return $latestByCommandRun;
    }

    /**
     * @return array<string,mixed>
     */
    private function rowPayload(CommandRun $run, ?VerificationRun $latestVerification): array
    {
        $driver = $run->resolvedDriverName((string) $this->config->get('checkpoint.driver'));

        return [
            'command_run_id' => (int) $run->getKey(),
            'operation' => $run->operation,
            'driver' => $driver,
            'repository' => $run->repository,
            'stanza' => $run->stanza,
            'type' => $run->backup_type,
            'label' => $run->backup_label,
            'path' => $run->artifact_path,
            'size_bytes' => $run->backup_size_bytes,
            'status' => (string) $run->status->value,
            'verification_state' => $run->verification_state,
            'created_at' => $this->formatTimestamp($run->created_at),
            'started_at' => $this->formatTimestamp($run->started_at),
            'finished_at' => $this->formatTimestamp($run->finished_at),
            'verified_at' => $this->formatTimestamp($run->verified_at),
            'last_known_good_at' => $this->formatTimestamp($run->last_known_good_at),
            'latest_verification' => $this->latestVerificationPayload($latestVerification),
            'metadata' => $this->normalizedArray($run->metadata),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestVerificationPayload(?VerificationRun $run): ?array
    {
        if (! $run instanceof VerificationRun) {
            return null;
        }

        return [
            'id' => (int) $run->getKey(),
            'verification_type' => $run->verification_type,
            'status' => $run->status,
            'verified_at' => $this->formatTimestamp($run->verified_at),
            'error_detail' => $run->error_detail,
        ];
    }

    private function formatTimestamp(?Carbon $timestamp): ?string
    {
        return $timestamp?->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function normalizedArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        /** @var array<string,mixed> $normalized */
        $normalized = $this->normalizeValue($value);

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->normalizeValue(...), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }
}
