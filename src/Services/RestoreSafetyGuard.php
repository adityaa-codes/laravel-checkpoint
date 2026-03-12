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
     */
    public function ensureSafe(CommandRun $run, array $context = []): void
    {
        if (! $this->isRestoreOperation($run->operation)) {
            return;
        }

        $this->ensureEnvironmentAllowed();
        $this->ensureDatabaseAllowed();
        $this->ensureConfirmationSatisfied();
        $this->ensureRestoreTargetValid($run, $context);
        $this->ensureVerificationSignal($run, $context);
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

    private function ensureEnvironmentAllowed(): void
    {
        $currentEnvironment = (string) $this->config->get('app.env', 'production');
        $allowedEnvironments = $this->stringList('checkpoint.restore.allowed_environments');

        if ($allowedEnvironments !== [] && ! in_array($currentEnvironment, $allowedEnvironments, true)) {
            throw new ConfigurationException(
                sprintf('Restore operations are blocked in environment [%s].', $currentEnvironment),
            );
        }
    }

    private function ensureDatabaseAllowed(): void
    {
        $allowedDatabases = $this->stringList('checkpoint.restore.allowed_databases');

        if ($allowedDatabases === []) {
            return;
        }

        $database = (string) $this->config->get(
            'database.connections.'.$this->config->get('database.default').'.database',
            '',
        );

        if ($database === '' || ! in_array($database, $allowedDatabases, true)) {
            throw new ConfigurationException(
                sprintf('Restore operations are blocked for database [%s].', $database),
            );
        }
    }

    private function ensureConfirmationSatisfied(): void
    {
        if (! (bool) $this->config->get('checkpoint.restore.require_confirmation', true)) {
            return;
        }

        if ($this->ciBypassActive()) {
            return;
        }

        $phrase = trim((string) $this->config->get('checkpoint.restore.confirmation_phrase', 'RESTORE'));
        $token = trim((string) $this->config->get('checkpoint.restore.confirmation_token', ''));

        if ($phrase === '' || $token !== $phrase) {
            throw new ConfigurationException(
                'Restore confirmation is required. Set checkpoint.restore.confirmation_token to match checkpoint.restore.confirmation_phrase before running a restore.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ensureRestoreTargetValid(CommandRun $run, array $context): void
    {
        if ($run->operation !== 'pitr_restore') {
            return;
        }

        $target = trim((string) ($context['restore_target'] ?? $run->argument_text ?? ''));

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
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function ensureVerificationSignal(CommandRun $run, array $context): void
    {
        if (! (bool) $this->config->get('checkpoint.restore.require_verified_backup', false)) {
            return;
        }

        $restoreTarget = trim((string) ($context['restore_target'] ?? $run->argument_text ?? ''));
        $query = CommandRun::query()
            ->succeeded()
            ->whereNotNull('last_known_good_at');

        match ($run->operation) {
            'logical_restore_file' => $query->where('artifact_path', 'like', '%'.$restoreTarget.'%'),
            'pgbackrest_restore' => $restoreTarget !== ''
                ? $query->where('backup_label', $restoreTarget)
                : $query->whereNotNull('backup_label'),
            default => $query,
        };

        if (! $query->exists()) {
            throw new ConfigurationException(
                sprintf('Restore operation [%s] requires a verified backup signal before execution.', $run->operation),
            );
        }
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
}
