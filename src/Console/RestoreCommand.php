<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildPitrReadinessReportAction;
use AdityaaCodes\LaravelCheckpoint\Actions\EnqueueCommandRunAction;
use AdityaaCodes\LaravelCheckpoint\Enums\CheckpointOperation;
use AdityaaCodes\LaravelCheckpoint\Jobs\ProcessCommandRunJob;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Services\RestoreSafetyGuard;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Throwable;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\text;

final class RestoreCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:restore
        {--file= : Restore from a specific backup file path}
        {--pitr= : PITR target timestamp (e.g. "2026-03-11 11:30:00")}
        {--pitr-dry-run : Evaluate PITR readiness without executing (was doctor:pitr)}
        {--target= : PITR dry-run target timestamp (e.g. "2026-03-11 11:30:00")}
        {--verification=moderate : Post-restore verification mode (moderate|full)}
        {--verify-only : Run verification on the last restore without re-running restore}
        {--sync : Execute synchronously instead of queueing}
        {--force : Skip confirmation prompts}
        {--format=table : Output format: table or json}';

    protected $description = 'Restore a database from a backup or to a point-in-time.';

    public function __construct(
        private readonly BuildPitrReadinessReportAction $buildPitrReadinessReport,
        private readonly RestoreSafetyGuard $restoreSafetyGuard,
        private readonly EnqueueCommandRunAction $enqueueCommandRun,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            if ($this->option('pitr-dry-run')) {
                return $this->handlePitrDryRun();
            }

            if ($this->option('verify-only')) {
                return $this->handleVerifyOnly();
            }

            $operation = $this->resolveOperation();
            $argument = $this->resolveArgument($operation);
            $verificationMode = $this->resolveVerificationMode();

            if ($this->enhancedInteractiveMode()) {
                intro('Database Restore');
            }

            $this->displayRestorePreview($operation, $argument);

            $this->runSafetyGuard($operation, $argument);

            if (! $this->option('force')) {
                $this->requireConfirmation();
            }

            $checkpointOperation = $this->toCheckpointOperation($operation);
            $payloadArgument = $this->buildEnqueueArgument($operation, $argument, $verificationMode);

            if ($this->option('sync')) {
                return $this->handleSync($checkpointOperation, $payloadArgument, $operation, $argument);
            }

            return $this->handleAsync($checkpointOperation, $payloadArgument);
        } catch (Throwable $exception) {
            report($exception);
            $this->promptError($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveOperation(): string
    {
        if ($this->stringOption('file') !== null) {
            return 'logical_restore_file';
        }

        if ($this->stringOption('pitr') !== null) {
            return 'pitr_restore';
        }

        return 'logical_restore_latest';
    }

    private function resolveArgument(string $operation): ?string
    {
        if ($operation === 'logical_restore_file') {
            return $this->stringOption('file');
        }

        if ($operation === 'pitr_restore') {
            return $this->stringOption('pitr');
        }

        return null;
    }

    private function resolveVerificationMode(): string
    {
        $mode = Str::trim(Str::lower($this->stringOption('verification') ?? 'moderate'));

        return in_array($mode, ['moderate', 'full'], true) ? $mode : 'moderate';
    }

    private function displayRestorePreview(string $operation, ?string $argument): void
    {
        $database = config('database.connections.'.config('database.default').'.database', 'unknown');
        $environment = config('app.env', 'production');

        $rows = [
            ['Operation', $this->operationLabel($operation)],
            ['Database', $database],
            ['Environment', $environment],
        ];

        if ($operation === 'logical_restore_file' && is_string($argument)) {
            $rows[] = ['Backup File', $argument];
        }

        if ($operation === 'pitr_restore' && is_string($argument)) {
            $rows[] = ['PITR Target', $argument];
        }

        $rows[] = ['Sync Mode', $this->option('sync') ? 'yes' : 'no'];
        $rows[] = ['Verification', $this->resolveVerificationMode()];

        $latestBackup = $this->latestBackupInfo();

        if ($latestBackup !== null) {
            $rows[] = ['Latest Backup', $latestBackup['label']];
            $rows[] = ['Backup Age', $latestBackup['age']];
        }

        $this->promptTable(['Field', 'Value'], $rows);
    }

    /**
     * @return array{label:string,age:string}|null
     */
    private function latestBackupInfo(): ?array
    {
        $run = CommandRun::query()
            ->where('operation', 'logical_backup')
            ->where('status', 'succeeded')
            ->whereNotNull('last_known_good_at')
            ->latest('last_known_good_at')
            ->latest('id')
            ->first();

        if (! $run instanceof CommandRun) {
            return null;
        }

        $timestamp = $run->last_known_good_at?->diffForHumans() ?? 'unknown';

        return [
            'label' => sprintf('Run #%d (%s)', (int) $run->getKey(), $run->last_known_good_at?->format('Y-m-d H:i:s') ?? 'unknown'),
            'age' => $timestamp,
        ];
    }

    private function operationLabel(string $operation): string
    {
        return match ($operation) {
            'logical_restore_latest' => 'Restore Latest Backup',
            'logical_restore_file' => 'Restore From File',
            'pitr_restore' => 'Point-in-Time Recovery',
            default => $operation,
        };
    }

    private function runSafetyGuard(string $operation, ?string $argument): void
    {
        $run = new CommandRun([
            'operation' => $operation,
            'argument_text' => $argument,
        ]);

        $audit = $this->restoreSafetyGuard->ensureSafe($run, [
            'restore_target' => $argument ?? '',
        ]);

        $this->displayBlastRadius($audit);
    }

    /**
     * @param  array<string, mixed>  $audit
     */
    private function displayBlastRadius(array $audit): void
    {
        $restoreAudit = $audit['restore_audit'] ?? [];
        $blastRadius = $restoreAudit['blast_radius'] ?? null;

        if (! is_array($blastRadius)) {
            return;
        }

        $score = $blastRadius['score'] ?? 0;
        $status = $blastRadius['status'] ?? 'unknown';
        $blockScore = $blastRadius['block_score'] ?? 80;

        $this->promptTable(['Metric', 'Value'], [
            ['Blast Radius Score', sprintf('%d/100 (block at %d)', $score, $blockScore)],
            ['Status', $status],
        ]);

        $factors = $blastRadius['factors'] ?? [];

        if ($factors === []) {
            return;
        }

        $this->line('');

        foreach ($factors as $factor) {
            if (! is_array($factor)) {
                continue;
            }

            $marker = ($factor['contributes'] ?? false) ? '⚠' : '✓';
            $this->promptInfo(sprintf('  %s %s', $marker, $factor['note'] ?? $factor['name'] ?? 'unknown'));
        }
    }

    private function requireConfirmation(): void
    {
        $input = text(
            label: 'Type RESTORE to confirm',
            placeholder: 'Type RESTORE...',
            required: true,
            validate: static fn (string $value): ?string => Str::upper(Str::trim($value)) === 'RESTORE'
                ? null
                : 'You must type RESTORE to proceed.',
        );

        if (Str::upper(Str::trim($input)) !== 'RESTORE') {
            throw new \RuntimeException('Restore confirmation not received.');
        }
    }

    private function toCheckpointOperation(string $operation): CheckpointOperation
    {
        return match ($operation) {
            'logical_restore_latest' => CheckpointOperation::RestoreLatest,
            'logical_restore_file' => CheckpointOperation::RestoreFile,
            'pitr_restore' => CheckpointOperation::PitrRestore,
            default => throw new \RuntimeException(sprintf('Unknown restore operation: %s', $operation)),
        };
    }

    private function buildEnqueueArgument(string $operation, ?string $argument, string $verificationMode): ?string
    {
        if ($operation === 'pitr_restore') {
            return json_encode([
                'restore_target' => $argument,
                'verification_mode' => $verificationMode,
            ], JSON_THROW_ON_ERROR);
        }

        if ($operation === 'logical_restore_file') {
            return json_encode([
                'file' => $argument,
                'verification_mode' => $verificationMode,
            ], JSON_THROW_ON_ERROR);
        }

        return null;
    }

    private function handleSync(CheckpointOperation $operation, ?string $argument, string $resolvedOperation, ?string $resolvedArgument): int
    {
        $run = $this->enqueueCommandRun->execute($operation, $argument);
        Bus::dispatchSync(new ProcessCommandRunJob($run));
        $run->refresh();

        if ($run->status->value !== 'succeeded') {
            $this->promptError(sprintf(
                'Sync %s run #%d failed (status: %s, exit code: %d).',
                $resolvedOperation,
                (int) $run->getKey(),
                $run->status->value,
                $run->exit_code ?? -1,
            ));

            return self::FAILURE;
        }

        $this->promptInfo(sprintf(
            'Sync %s run #%d completed successfully.',
            $resolvedOperation,
            (int) $run->getKey(),
        ));

        $this->displayRestoreResult($run, $resolvedOperation, $resolvedArgument);

        if ($this->enhancedInteractiveMode()) {
            outro('Restore complete.');
        }

        return self::SUCCESS;
    }

    private function handleAsync(CheckpointOperation $operation, ?string $argument): int
    {
        $run = $this->enqueueCommandRun->execute($operation, $argument);

        $this->promptInfo(sprintf('Queued Restore run #%d.', (int) $run->getKey()));

        if ($this->enhancedInteractiveMode()) {
            outro('Restore enqueued.');
        }

        return self::SUCCESS;
    }

    private function handlePitrDryRun(): int
    {
        $targetInput = $this->stringOption('target');

        if ($this->enhancedInteractiveMode()) {
            intro('PITR Readiness Evaluation');
        }

        $payload = $this->buildPitrReadinessReport->execute($targetInput !== '' && $targetInput !== null ? $targetInput : null);
        $readiness = $payload['readiness'];
        $checks = $payload['checks'];
        $summary = $payload['summary'];

        $format = $this->resolveOutputMode($this->stringOption('format') ?? 'table');

        if ($format === 'json') {
            $this->line(json_encode([
                'version' => 1,
                'surface' => 'pitr_readiness',
                'driver' => config('checkpoint.driver'),
                'generated_at' => $payload['generated_at'],
                'target' => $payload['target'],
                'readiness' => $readiness,
                'checks' => $checks,
                'summary' => $summary,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $readiness === 'ready' ? self::SUCCESS : self::FAILURE;
        }

        $checkRows = [];
        foreach ($checks as $check) {
            $checkRows[] = [$check['code'], $check['status']];
        }

        $this->promptTable(['Check', 'Status'], $checkRows);

        $this->line('');
        $this->promptInfo(sprintf(
            'PITR Readiness: %s (%d pass, %d fail)',
            $readiness,
            $summary['pass'],
            $summary['fail'],
        ));

        return $readiness === 'ready' ? self::SUCCESS : self::FAILURE;
    }

    private function handleVerifyOnly(): int
    {
        $run = CommandRun::query()
            ->whereIn('operation', ['logical_restore_latest', 'logical_restore_file', 'pitr_restore', 'physical_restore'])
            ->whereNotNull('restore_post_verification_result')
            ->latest('id')
            ->first();

        if (! $run instanceof CommandRun) {
            $this->promptWarning('No restore runs with post-restore verification results found.');

            return self::FAILURE;
        }

        $verificationResult = $run->restore_post_verification_result;
        $status = $verificationResult === 'pass' ? 'pass' : 'fail';
        $exitCode = $verificationResult === 'pass' ? self::SUCCESS : self::FAILURE;

        $this->promptTable(['Field', 'Value'], [
            ['Run ID', (string) $run->getKey()],
            ['Operation', $run->operation],
            ['Status', $run->status->value],
            ['Verification Result', $verificationResult ?? 'unknown'],
            ['Completed At', $run->finished_at ?? 'unknown'],
        ]);

        $this->promptInfo(sprintf('Post-restore verification result: %s', $status));

        return $exitCode;
    }

    private function displayRestoreResult(CommandRun $run, string $operation, ?string $argument): void
    {
        $this->promptTable(['Field', 'Value'], [
            ['Run ID', (string) $run->getKey()],
            ['Operation', $operation],
            ['Status', $run->status->value],
            ['Exit Code', (string) ($run->exit_code ?? '-')],
            ['Verification Result', $run->restore_post_verification_result ?? 'pending'],
        ]);
    }
}
