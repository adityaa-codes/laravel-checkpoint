<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;

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

        $this->recordDecisionEvent($run, 'evaluate', 'restore_safety_evaluated', [
            'operation' => $run->operation,
            'restore_target' => $context['restore_target'] ?? $run->argument_text,
        ]);

        try {
            $environment = $this->ensureEnvironmentAllowed();
            $database = $this->ensureDatabaseAllowed();
            $confirmation = $this->ensureConfirmationSatisfied();
            $restoreTarget = $this->ensureRestoreTargetValid($run, $context);
            $verificationSignal = $this->ensureVerificationSignal($run, $restoreTarget, $context);
            $blastRadius = $this->blastRadiusAssessment(
                operation: $run->operation,
                environment: $environment,
                database: $database,
                restoreTarget: $restoreTarget,
                verifiedBackupRequired: $verificationSignal['required'],
                verifiedSignalRunId: $verificationSignal['run_id'],
            );
            $this->assertBlastRadiusPolicy($blastRadius);
        } catch (ConfigurationException $exception) {
            $this->recordDecisionEvent($run, 'block', 'restore_safety_blocked', [
                'message' => $exception->getMessage(),
                'restore_target' => $context['restore_target'] ?? $run->argument_text,
            ]);

            throw $exception;
        }

        $audit = [
            'restore_audit' => [
                'environment' => $environment,
                'database' => $database !== '' ? $database : null,
                'target' => $restoreTarget !== '' ? $restoreTarget : null,
                'pitr_base_target' => $this->pitrBaseTarget($context),
                'pitr_binlog_files' => $this->pitrBinlogFiles($context),
                'confirmation_required' => $confirmation['required'],
                'confirmation_satisfied_via' => $confirmation['satisfied_via'],
                'verified_backup_required' => $verificationSignal['required'],
                'verified_signal_run_id' => $verificationSignal['run_id'],
                'verified_signal_operation' => $verificationSignal['operation'],
                'verified_signal_backup_label' => $verificationSignal['backup_label'],
                'verified_signal_artifact_path' => $verificationSignal['artifact_path'],
                'verified_signal_last_known_good_at' => $verificationSignal['last_known_good_at'],
                'blast_radius' => $blastRadius,
            ],
        ];

        $this->recordDecisionEvent($run, 'allow', 'restore_safety_passed', [
            'restore_audit' => $audit['restore_audit'],
        ]);

        return $audit;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordDecisionEvent(CommandRun $run, string $decision, string $reason, array $payload = []): void
    {
        RestoreDecisionEvent::query()->create([
            'command_run_id' => $run->exists ? (int) $run->getKey() : null,
            'operation' => $run->operation,
            'decision' => $decision,
            'reason' => $reason,
            'payload' => $payload !== [] ? $payload : null,
            'created_at' => now(),
        ]);
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
            Carbon::parse($target);
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
            'pitr_restore' => $this->matchingPitrRestoreVerification($query, $context),
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
            fn (CommandRun $candidate): bool => $this->artifactSnapshotMatches($candidate, $expectedSnapshot)
                && $this->provenanceMatches($candidate, $context),
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

        /** @var \Illuminate\Support\Collection<int, CommandRun> $candidates */
        $candidates = $query->limit(10)->get();

        return $candidates->first(
            fn (CommandRun $candidate): bool => $this->provenanceMatches($candidate, $context),
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<CommandRun>  $query
     */
    private function matchingPitrRestoreVerification(
        \Illuminate\Database\Eloquent\Builder $query,
        array $context,
    ): ?CommandRun {
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

        /** @var \Illuminate\Support\Collection<int, CommandRun> $candidates */
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

    /**
     * @param  array<string, mixed>  $context
     */
    private function provenanceMatches(CommandRun $candidate, array $context): bool
    {
        $contextMetadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $expectedDriver = is_string($contextMetadata['driver'] ?? null) ? trim((string) $contextMetadata['driver']) : '';
        $expectedDatabase = is_string($contextMetadata['database'] ?? null) ? trim((string) $contextMetadata['database']) : '';

        if ($expectedDriver !== '' && $candidate->resolvedDriverName() !== $expectedDriver) {
            return false;
        }

        if ($expectedDatabase !== '') {
            $candidateMetadata = is_array($candidate->metadata) ? $candidate->metadata : [];
            $candidateDatabase = is_string($candidateMetadata['database'] ?? null)
                ? trim((string) $candidateMetadata['database'])
                : '';

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
    private function pitrBinlogFiles(array $context): array
    {
        $contextMetadata = is_array($context['metadata'] ?? null) ? $context['metadata'] : [];
        $value = $contextMetadata['binlog_files'] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_string($item) ? trim($item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function pitrBaseTarget(array $context): ?string
    {
        $baseTarget = $context['pitr_base_target'] ?? null;

        if (! is_string($baseTarget)) {
            return null;
        }

        $baseTarget = trim($baseTarget);

        return $baseTarget !== '' ? $baseTarget : null;
    }

    /**
     * @return array{
     *   enabled:bool,
     *   score:int,
     *   status:string,
     *   warn_score:int,
     *   block_score:int,
     *   factors:list<array{name:string,weight:int,contributes:bool,note:string}>
     * }
     */
    private function blastRadiusAssessment(
        string $operation,
        string $environment,
        string $database,
        string $restoreTarget,
        bool $verifiedBackupRequired,
        ?int $verifiedSignalRunId,
    ): array {
        $enabled = (bool) $this->config->get('checkpoint.restore.blast_radius.enabled', true);
        $warnScore = max(0, min(100, (int) $this->config->get('checkpoint.restore.blast_radius.warn_score', 50)));
        $blockScore = max(0, min(100, (int) $this->config->get('checkpoint.restore.blast_radius.block_score', 80)));
        $weights = $this->blastRadiusWeights();

        if (! $enabled) {
            return [
                'enabled' => false,
                'score' => 0,
                'status' => 'disabled',
                'warn_score' => $warnScore,
                'block_score' => $blockScore,
                'factors' => [],
            ];
        }

        $factors = [
            [
                'name' => 'environment',
                'weight' => $weights['environment'],
                'contributes' => in_array($environment, ['production', 'prod'], true),
                'note' => in_array($environment, ['production', 'prod'], true)
                    ? sprintf('restore running in %s environment', $environment)
                    : sprintf('restore running in %s environment', $environment),
            ],
            [
                'name' => 'database',
                'weight' => $weights['database'],
                'contributes' => $database !== '' && ! in_array(strtolower($database), ['checkpoint_shadow', 'checkpoint_restore_shadow'], true),
                'note' => $database !== '' ? sprintf('database target %s', $database) : 'database target unknown',
            ],
            [
                'name' => 'target',
                'weight' => $weights['target'],
                'contributes' => $operation === 'logical_restore_latest' || str_contains(strtolower($restoreTarget), 'latest'),
                'note' => $restoreTarget !== '' ? sprintf('restore target %s', $restoreTarget) : 'restore target unresolved',
            ],
            [
                'name' => 'verification',
                'weight' => $weights['verification'],
                'contributes' => $verifiedBackupRequired && ! is_int($verifiedSignalRunId),
                'note' => $verifiedBackupRequired
                    ? (is_int($verifiedSignalRunId)
                        ? sprintf('verified signal linked to run %d', $verifiedSignalRunId)
                        : 'verified signal required but missing')
                    : 'verified signal requirement disabled',
            ],
        ];

        $score = 0;

        foreach ($factors as $factor) {
            if ($factor['contributes'] === true) {
                $score += $factor['weight'];
            }
        }

        $score = max(0, min(100, $score));
        $status = $score >= $blockScore ? 'block' : ($score >= $warnScore ? 'warn' : 'pass');

        return [
            'enabled' => true,
            'score' => $score,
            'status' => $status,
            'warn_score' => $warnScore,
            'block_score' => $blockScore,
            'factors' => $factors,
        ];
    }

    /**
     * @param  array{
     *   enabled:bool,
     *   score:int,
     *   status:string,
     *   warn_score:int,
     *   block_score:int,
     *   factors:list<array{name:string,weight:int,contributes:bool,note:string}>
     * }  $blastRadius
     */
    private function assertBlastRadiusPolicy(array $blastRadius): void
    {
        if (($blastRadius['enabled'] ?? false) !== true) {
            return;
        }

        if (($blastRadius['status'] ?? 'pass') !== 'block') {
            return;
        }

        throw new ConfigurationException(sprintf(
            'Restore blast radius score [%d] exceeds block threshold [%d].',
            (int) ($blastRadius['score'] ?? 0),
            (int) ($blastRadius['block_score'] ?? 0),
        ));
    }

    /**
     * @return array{environment:int,database:int,target:int,verification:int}
     */
    private function blastRadiusWeights(): array
    {
        $configured = $this->config->get('checkpoint.restore.blast_radius.weights', []);
        $weights = is_array($configured) ? $configured : [];

        return [
            'environment' => $this->normalizedWeight($weights['environment'] ?? 30),
            'database' => $this->normalizedWeight($weights['database'] ?? 25),
            'target' => $this->normalizedWeight($weights['target'] ?? 20),
            'verification' => $this->normalizedWeight($weights['verification'] ?? 25),
        ];
    }

    private function normalizedWeight(mixed $value): int
    {
        if (! is_int($value)) {
            return 0;
        }

        return max(0, min(100, $value));
    }
}
