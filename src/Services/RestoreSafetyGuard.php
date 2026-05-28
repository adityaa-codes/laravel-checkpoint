<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\RestoreDecisionEvent;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository;

/** @internal */
final readonly class RestoreSafetyGuard
{
    public function __construct(
        private Repository $config,
        private BlastRadiusAssessor $blastRadius,
        private RestoreVerificationSignalLocator $verificationSignalLocator,
    ) {}

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
            $verificationSignal = $this->verificationSignalLocator->locate($run, $restoreTarget, $context);
            $blastRadiusAssessment = $this->blastRadius->assess(
                operation: $run->operation,
                environment: $environment,
                database: $database,
                restoreTarget: $restoreTarget,
                verifiedBackupRequired: $verificationSignal['required'],
                verifiedSignalRunId: $verificationSignal['run_id'],
            );
            $this->blastRadius->assertPolicy($blastRadiusAssessment);
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
                'pitr_base_target' => $this->verificationSignalLocator->pitrBaseTarget($context),
                'pitr_binlog_files' => $this->verificationSignalLocator->pitrBinlogFiles($context),
                'confirmation_required' => $confirmation['required'],
                'confirmation_satisfied_via' => $confirmation['satisfied_via'],
                'verified_backup_required' => $verificationSignal['required'],
                'verified_signal_run_id' => $verificationSignal['run_id'],
                'verified_signal_operation' => $verificationSignal['operation'],
                'verified_signal_backup_label' => $verificationSignal['backup_label'],
                'verified_signal_artifact_path' => $verificationSignal['artifact_path'],
                'verified_signal_last_known_good_at' => $verificationSignal['last_known_good_at'],
                'blast_radius' => $blastRadiusAssessment,
            ],
        ];

        $this->recordDecisionEvent($run, 'allow', 'restore_safety_passed', [
            'restore_audit' => $audit['restore_audit'],
        ]);

        return $audit;
    }

    private function recordDecisionEvent(CommandRun $run, string $decision, string $reason, array $payload): void
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
        return collect([
            'logical_restore_latest',
            'logical_restore_file',
            'pitr_restore',
            'physical_restore',
        ])->contains($operation);
    }

    private function ensureEnvironmentAllowed(): string
    {
        $currentEnvironment = $this->config->get('app.env', 'production');
        $allowedEnvironments = $this->config->get('checkpoint.restore.allowed_environments', []);

        if ($allowedEnvironments !== [] && ! collect($allowedEnvironments)->contains($currentEnvironment)) {
            throw new ConfigurationException("Restore operations are blocked in environment [{$currentEnvironment}].");
        }

        return $currentEnvironment;
    }

    private function ensureDatabaseAllowed(): string
    {
        $allowedDatabases = $this->config->get('checkpoint.restore.allowed_databases', []);
        $database = $this->config->get(
            'database.connections.'.$this->config->get('database.default').'.database',
            '',
        );

        if ($allowedDatabases === []) {
            return $database;
        }

        if ($database === '' || ! collect($allowedDatabases)->contains($database)) {
            throw new ConfigurationException("Restore operations are blocked for database [{$database}].");
        }

        return $database;
    }

    private function ensureConfirmationSatisfied(): array
    {
        if (! $this->config->get('checkpoint.restore.require_confirmation', true)) {
            return ['required' => false, 'satisfied_via' => 'disabled'];
        }

        if ($this->ciBypassActive()) {
            return ['required' => true, 'satisfied_via' => 'ci_bypass'];
        }

        $phrase = str($this->config->get('checkpoint.restore.confirmation_phrase', 'RESTORE'))->trim()->value();
        $token = str($this->config->get('checkpoint.restore.confirmation_token', ''))->trim()->value();

        if ($phrase === '' || $token !== $phrase) {
            throw new ConfigurationException(
                'Restore confirmation is required. Set checkpoint.restore.confirmation_token to match checkpoint.restore.confirmation_phrase before running a restore.',
            );
        }

        return ['required' => true, 'satisfied_via' => 'token'];
    }

    private function ensureRestoreTargetValid(CommandRun $run, array $context): string
    {
        $target = str($context['restore_target'] ?? $run->argument_text ?? '')->trim()->value();

        if ($run->operation !== 'pitr_restore') {
            return $target;
        }

        if ($target === '') {
            throw new ConfigurationException('pitr_restore requires a valid restore target timestamp.');
        }

        try {
            Carbon::parse($target);
        } catch (\Throwable $exception) {
            report($exception);

            throw new ConfigurationException("pitr_restore target [{$target}] must be a valid datetime string.", $exception->getCode(), $exception);
        }

        return $target;
    }

    private function ciBypassActive(): bool
    {
        return $this->config->get('checkpoint.restore.allow_in_ci', true)
            && $this->config->get('checkpoint.restore.ci', false);
    }
}
