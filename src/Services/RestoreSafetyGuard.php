<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\CarbonImmutable;

/** @internal */
final readonly class RestoreSafetyGuard
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function ensureSafe(CommandRun $run, array $context = []): array
    {
        if (! $this->isRestoreOperation($run->operation)) {
            return [];
        }

        $environment = $this->ensureEnvironmentAllowed();
        $database = $this->ensureDatabaseAllowed();
        $confirmation = $this->ensureConfirmationSatisfied();
        $restoreTarget = $this->ensureRestoreTargetValid($run, $context);
        $verificationSignal = $this->ensureVerificationSignal($run, $restoreTarget, $context);

        return [
            'restore_audit' => [
                'environment' => $environment,
                'database' => $database !== '' ? $database : null,
                'target' => $restoreTarget !== '' ? $restoreTarget : null,
                'confirmation_required' => $confirmation['required'],
                'confirmation_satisfied_via' => $confirmation['satisfied_via'],
                'verified_backup_required' => $verificationSignal['required'],
                'verified_signal_run_id' => $verificationSignal['run_id'],
                'verified_signal_operation' => $verificationSignal['operation'],
                'verified_signal_backup_label' => $verificationSignal['backup_label'],
                'verified_signal_artifact_path' => $verificationSignal['artifact_path'],
                'verified_signal_last_known_good_at' => $verificationSignal['last_known_good_at'],
            ],
        ];
    }

    private function isRestoreOperation(string $operation): bool
    {
        return in_array($operation, [
            'logical_restore_latest',
            'logical_restore_file',
            'pitr_restore',
            'pgbackrest_restore',
        ], true);
    }

    private function ensureEnvironmentAllowed(): string
    {
        $currentEnvironment = (string) $this->config->get('app.env', 'production');
        $allowedEnvironments = $this->stringList('checkpoint.restore.allowed_environments');

        if ($allowedEnvironments !== [] && ! in_array($currentEnvironment, $allowedEnvironments, true)) {
            throw new ConfigurationException(
                sprintf('Restore operations are blocked in environment [%s].', $currentEnvironment),
            );
        }

        return $currentEnvironment;
    }

    private function ensureDatabaseAllowed(): string
    {
        $allowedDatabases = $this->stringList('checkpoint.restore.allowed_databases');
        $database = (string) $this->config->get(
            'database.connections.'.$this->config->get('database.default').'.database',
            '',
        );

        if ($allowedDatabases === []) {
            return $database;
        }

        if ($database === '' || ! in_array($database, $allowedDatabases, true)) {
            throw new ConfigurationException(
                sprintf('Restore operations are blocked for database [%s].', $database),
            );
        }

        return $database;
    }

    /**
     * @return array{required:bool,satisfied_via:string}
     */
    private function ensureConfirmationSatisfied(): array
    {
        if (! (bool) $this->config->get('checkpoint.restore.require_confirmation', true)) {
            return [
                'required' => false,
                'satisfied_via' => 'disabled',
            ];
        }

        if ($this->ciBypassActive()) {
            return [
                'required' => true,
                'satisfied_via' => 'ci_bypass',
            ];
        }

        $phrase = trim((string) $this->config->get('checkpoint.restore.confirmation_phrase', 'RESTORE'));
        $token = trim((string) $this->config->get('checkpoint.restore.confirmation_token', ''));

        if ($phrase === '' || $token !== $phrase) {
            throw new ConfigurationException(
                'Restore confirmation is required. Set checkpoint.restore.confirmation_token to match checkpoint.restore.confirmation_phrase before running a restore.',
            );
        }

        return [
            'required' => true,
            'satisfied_via' => 'token',
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ensureRestoreTargetValid(CommandRun $run, array $context): string
    {
        $target = trim((string) ($context['restore_target'] ?? $run->argument_text ?? ''));

        if ($run->operation !== 'pitr_restore') {
            return $target;
        }

        if ($target === '') {
            throw new ConfigurationException('pitr_restore requires a valid restore target timestamp.');
        }

        try {
            CarbonImmutable::parse($target);
        } catch (\Throwable) {
            throw new ConfigurationException(
                sprintf('pitr_restore target [%s] must be a valid datetime string.', $target),
            );
        }

        return $target;
    }

    /**
     * @return array{
     *     required: bool,
     *     run_id: int|null,
     *     operation: string|null,
     *     backup_label: string|null,
     *     artifact_path: string|null,
     *     last_known_good_at: string|null
     * }
     */
    private function ensureVerificationSignal(CommandRun $run, string $restoreTarget, array $context): array
    {
        if (! (bool) $this->config->get('checkpoint.restore.require_verified_backup', false)) {
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
            'logical_restore_file', 'logical_restore_latest' => $this->matchingLogicalRestoreVerification($query, $restoreTarget, $context),
            'pgbackrest_restore' => $this->matchingPgBackRestRestoreVerification($query, $restoreTarget, $context),
            default => $query->first(),
        };

        if (! $verifiedRun instanceof CommandRun) {
            throw new ConfigurationException(
                sprintf('Restore operation [%s] requires a verified backup signal before execution.', $run->operation),
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
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $value = $this->config->get($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    private function ciBypassActive(): bool
    {
        return (bool) $this->config->get('checkpoint.restore.allow_in_ci', true)
            && (bool) $this->config->get('checkpoint.restore.ci', false);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CommandRun>  $query
     */
    private function requireExplicitPgBackRestBackupLabel(string $restoreTarget, \Illuminate\Database\Eloquent\Builder $query): void
    {
        if ($restoreTarget === '') {
            throw new ConfigurationException(
                'pgbackrest_restore requires an explicit backup set label when checkpoint.restore.require_verified_backup is enabled.',
            );
        }

        $query->where('backup_label', $restoreTarget);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CommandRun>  $query
     */
    private function matchingLogicalRestoreVerification(
        \Illuminate\Database\Eloquent\Builder $query,
        string $restoreTarget,
        array $context,
    ): ?CommandRun {
        $query
            ->where('operation', 'logical_backup')
            ->where('artifact_path', $restoreTarget);

        $expectedSnapshot = is_array($context['restore_target_snapshot'] ?? null)
            ? $context['restore_target_snapshot']
            : null;

        if ($expectedSnapshot === null) {
            return $query->first();
        }

        /** @var \Illuminate\Support\Collection<int, CommandRun> $candidates */
        $candidates = $query->limit(10)->get();

        return $candidates->first(
            fn (CommandRun $candidate): bool => $this->artifactSnapshotMatches($candidate, $expectedSnapshot),
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CommandRun>  $query
     */
    private function matchingPgBackRestRestoreVerification(
        \Illuminate\Database\Eloquent\Builder $query,
        string $restoreTarget,
        array $context,
    ): ?CommandRun {
        $this->requireExplicitPgBackRestBackupLabel($restoreTarget, $query);

        $query->whereIn('operation', [
            'pgbackrest_check',
            'pgbackrest_verify',
        ]);
        $query->where('verification_state', 'verified');

        if (is_numeric($context['repository'] ?? null)) {
            $query->where('repository', (int) $context['repository']);
        }

        if (is_string($context['stanza'] ?? null) && trim((string) $context['stanza']) !== '') {
            $query->where('stanza', trim((string) $context['stanza']));
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $expectedSnapshot
     */
    private function artifactSnapshotMatches(CommandRun $candidate, array $expectedSnapshot): bool
    {
        $metadata = is_array($candidate->metadata) ? $candidate->metadata : [];
        $artifactSnapshot = is_array($metadata['artifact_snapshot'] ?? null)
            ? $metadata['artifact_snapshot']
            : null;

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
}
