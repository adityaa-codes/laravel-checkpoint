<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Actions\BuildPitrReadinessReportAction;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

final class DoctorCommand extends CheckpointCommand
{
    protected $signature = 'checkpoint:doctor
        {--brief : Show triage-first output with top issues and next action.}
        {--format=table : Output format: table, json, compact-json, or agent.}
        {--agent : Emit compact AI-agent friendly JSON output.}
        {--policy-profile= : Override gate policy profile for CI/automation.}
        {--pitr : Evaluate PITR readiness for a target timestamp.}
        {--target= : PITR target datetime (defaults to now).}
        {--full : Show full operational report (health + recent runs + summary + verification).}
        {--limit=10 : Number of recent runs to include (with --full).}';

    protected $description = 'Show checkpoint health, PITR readiness, or operational report.';

    public function __construct(
        private readonly Repository $config,
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
        private readonly GatePolicyEvaluator $gatePolicyEvaluator,
        private readonly BuildPitrReadinessReportAction $buildPitrReadinessReport,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pitrMode = (bool) $this->option('pitr');
        $fullMode = (bool) $this->option('full');
        $format = $this->stringOption('format') ?? 'table';
        $agentMode = (bool) $this->option('agent');
        $briefMode = (bool) $this->option('brief');
        $policyProfile = $this->policyProfileOverride();
        $outputMode = $this->resolveOutputMode($format, $agentMode);

        if ($fullMode) {
            return $this->handleFullReport($outputMode, $briefMode, $policyProfile);
        }

        if ($pitrMode) {
            return $this->handlePitrReadiness($outputMode, $policyProfile);
        }

        return $this->handleHealthChecks($outputMode, $briefMode, $policyProfile);
    }

    private function handleHealthChecks(string $outputMode, bool $briefMode, ?string $policyProfile): int
    {
        if ($this->enhancedInteractiveMode() && $outputMode === 'table') {
            intro('Checkpoint Doctor: Health Checks');
            note('What: validate config posture, binaries, storage, queue, and safety signals.');
            note('When: during setup, after config edits, or while debugging failures.');
            note('Next: if healthy, continue with checkpoint:backup and checkpoint:status.');
        }

        try {
            $checks = $this->reportBuilder->healthChecks();
            $gateDecision = $this->gatePolicyEvaluator->evaluate($checks, $this->reportBuilder->summary(), $policyProfile);
        } catch (\Throwable $exception) {
            report($exception);

            $checks = [[
                'code' => 'health.error',
                'check' => 'Health check execution',
                'status' => 'fail',
                'severity' => 'blocker',
                'notes' => $exception->getMessage(),
                'data' => ['exception' => $exception::class],
            ]];
            $gateDecision = $this->gatePolicyEvaluator->evaluate($checks, [], $policyProfile);
        }

        $exitCode = $gateDecision['exit_code'];

        if ($outputMode === 'json' || $outputMode === 'compact-json') {
            $this->line($this->jsonReport($checks, $briefMode, $gateDecision, $outputMode === 'compact-json'));

            return $exitCode;
        }

        if ($outputMode === 'agent') {
            $this->line($this->agentReport($checks, $briefMode, $gateDecision));

            return $exitCode;
        }

        $this->renderHealthTable($checks, $briefMode);

        return $exitCode;
    }

    private function handlePitrReadiness(string $outputMode, ?string $policyProfile): int
    {
        if ($this->enhancedInteractiveMode() && $outputMode === 'table') {
            note('What: evaluate whether PITR prerequisites are currently satisfied.');
            note('When: before relying on point-in-time recovery in real incidents.');
            note('Next: remediate failing checks, then rerun checkpoint:doctor --pitr.');
        }

        $targetInput = $this->stringOption('target');
        ['payload' => $payload, 'exitCode' => $exitCode] = $this->buildPitrReport($targetInput);

        if ($outputMode === 'agent') {
            return $this->renderPitrAgentOutput($payload, $exitCode);
        }

        if ($outputMode === 'json' || $outputMode === 'compact-json') {
            return $this->renderPitrJsonOutput($payload, $exitCode, $outputMode === 'compact-json');
        }

        return $this->renderPitrTableOutput($payload, $exitCode);
    }

    private function handleFullReport(string $outputMode, bool $briefMode, ?string $policyProfile): int
    {
        if ($this->enhancedInteractiveMode() && $outputMode === 'table') {
            intro('Checkpoint Operational Report');
            note('What: consolidated operational report across runs, health, and verification.');
            note('When: handoff reporting, audits, and broader operational review.');
            note('Next: use checkpoint:doctor to troubleshoot failing checks from this report.');
        }

        ['requested' => $requestedLimit, 'effective' => $effectiveLimit] = $this->recentRunLimits();

        $reportPayload = $this->buildReportPayload($effectiveLimit);

        $gateDecision = $this->gatePolicyEvaluator->evaluate(
            $reportPayload['health']['checks'],
            $reportPayload['summary'],
            $policyProfile,
        );
        $exitCode = $gateDecision['exit_code'];

        if ($outputMode === 'agent') {
            $this->renderReportAgentOutput($requestedLimit, $effectiveLimit, $reportPayload, $briefMode, $gateDecision);
        } elseif ($outputMode === 'json' || $outputMode === 'compact-json') {
            $this->renderReportJsonOutput($outputMode, $reportPayload, $requestedLimit, $effectiveLimit, $briefMode, $gateDecision);
        } else {
            $this->renderReportTableReport($reportPayload, $requestedLimit, $effectiveLimit, $briefMode);
        }

        return $exitCode;
    }

    private function statusWord(string $level): string
    {
        $key = match ($level) {
            'pass' => 'messages.cli.doctor_pass',
            'warn' => 'messages.cli.doctor_warn',
            default => 'messages.cli.doctor_fail',
        };

        $value = __($key);

        if ($value !== $key) {
            return $value;
        }

        return match ($level) {
            'pass' => 'PASS',
            'warn' => 'WARN',
            default => 'FAIL',
        };
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @param  array<string, mixed>  $gateDecision
     */
    private function jsonReport(array $checks, bool $briefMode, array $gateDecision, bool $compactJson = false): string
    {
        $checks = $this->withSeverity($checks);

        if (! $briefMode) {
            $report = [
                'ok' => $this->reportBuilder->healthOk($checks),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'generated_at' => now()->toIso8601String(),
                'gates' => $this->machineGateDecision($gateDecision),
                'checks' => $this->mapChecksJson($checks),
            ];
            $report = $compactJson
                ? $this->jsonContract->compactEnvelope('doctor', $report)
                : $this->jsonContract->envelope('doctor', $report);

            return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $report = [
            'ok' => $this->reportBuilder->healthOk($checks),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'generated_at' => now()->toIso8601String(),
            'mode' => 'brief',
            'gates' => $this->machineGateDecision($gateDecision),
            'totals' => $this->severityTotals($checks),
            'checks' => $this->mapChecksJson($this->topIssues($checks, 3)),
            'next_actions' => array_slice($this->suggestionsFromChecks($checks, compact: true), 0, 3),
        ];
        $report = $compactJson
            ? $this->jsonContract->compactEnvelope('doctor', $report)
            : $this->jsonContract->envelope('doctor', $report);

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $checks
     * @return list<array<string, mixed>>
     */
    private function mapChecksJson(array $checks): array
    {
        return array_map(fn (array $check): array => [
            'code' => $check['code'],
            'check' => $check['check'],
            'status' => $check['status'],
            'severity' => $check['severity'],
            'notes' => $check['notes'],
            'data' => $check['data'],
        ], $checks);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @param  array<string, mixed>  $gateDecision
     */
    private function agentReport(array $checks, bool $briefMode, array $gateDecision): string
    {
        $checks = $this->withSeverity($checks);
        $failedCount = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'fail'));
        $warnCount = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'warn'));
        $totals = $this->severityTotals($checks);
        $suggestions = $this->suggestionsFromChecks($checks, compact: $briefMode);
        $ok = $this->reportBuilder->healthOk($checks);

        $report = [
            'result' => $failedCount > 0 ? 'failed' : ($warnCount > 0 ? 'partial' : 'passed'),
            'code' => $failedCount > 0 ? 'doctor.health.failed' : ($warnCount > 0 ? 'doctor.health.warn' : 'doctor.health.ok'),
            'summary' => sprintf('%d fail, %d warn, %d pass checks.', $failedCount, $warnCount, count($checks) - $failedCount - $warnCount),
            'data' => [
                'ok' => $ok,
                'mode' => $briefMode ? 'brief' : 'full',
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'generated_at' => now()->toIso8601String(),
                'gates' => $gateDecision,
                'checks' => array_map(fn (array $check): array => [
                    'code' => $check['code'],
                    'check' => $check['check'],
                    'status' => $check['status'],
                    'severity' => $check['severity'],
                    'notes' => $check['notes'],
                    'data' => $check['data'],
                ], $briefMode ? $this->topIssues($checks, 3) : $checks),
                'severity_totals' => $totals,
                'slo' => $this->healthSloPayload($checks, $failedCount, $warnCount),
            ],
            'suggestions' => $briefMode ? array_slice($suggestions, 0, 3) : $suggestions,
        ];

        $report = $this->jsonContract->envelope('doctor', $report);

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @return list<string>
     */
    private function suggestionsFromChecks(array $checks, bool $compact = false): array
    {
        $suggestions = [];

        foreach ($checks as $check) {
            if ((string) $check['status'] === 'pass') {
                continue;
            }

            foreach ($this->suggestionsForCheck($check, $compact) as $suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }

    /**
     * @param  array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}  $check
     * @return list<string>
     */
    private function suggestionsForCheck(array $check, bool $compact): array
    {
        $code = (string) $check['code'];

        if ($compact) {
            $suggestions = [$this->compactSuggestionForCheck($code)];

            if (str_starts_with($code, 'backup_drill.')) {
                $playbookCommands = $check['data']['recommended_commands'] ?? null;

                if (is_array($playbookCommands)) {
                    foreach ($playbookCommands as $command) {
                        if (is_string($command) && trim($command) !== '') {
                            $suggestions[] = $command;
                        }
                    }
                }
            }

            return $suggestions;
        }

        return $this->verboseSuggestionsForCheck($check);
    }

    /**
     * @param  array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}  $check
     * @return list<string>
     */
    private function verboseSuggestionsForCheck(array $check): array
    {
        $code = (string) $check['code'];

        if ($code === 'queue.orphaned_runs') {
            return ['Run checkpoint:recover-orphans and ensure queue workers emit regular heartbeats.'];
        }

        if (str_starts_with($code, 'backup_drill.')) {
            $suggestions = ['Run checkpoint:drill (or record a drill run) and verify freshness, pass-rate, and trend thresholds.'];

            $playbookCommands = $check['data']['recommended_commands'] ?? null;

            if (is_array($playbookCommands)) {
                foreach ($playbookCommands as $command) {
                    if (is_string($command) && trim($command) !== '') {
                        $suggestions[] = 'Run '.$command.' to execute the drill remediation playbook.';
                    }
                }
            }

            return $suggestions;
        }

        if ($code === 'backup.last_known_good') {
            return ['Queue a fresh backup and verify last-known-good signals are updated.'];
        }

        if ($code === 'restore.post_verification') {
            return ['Inspect latest restore post-verification checks in doctor/report output and rerun restore with corrected inputs if checks failed.'];
        }

        if ($code === 'verification.runs') {
            return ['Run verification commands and inspect failed verification runs to restore healthy verification coverage.'];
        }

        return $this->remediationCommandsForCheck($check);
    }

    /**
     * @param  array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}  $check
     * @return list<string>
     */
    private function remediationCommandsForCheck(array $check): array
    {
        $code = (string) $check['code'];

        $commands = str_starts_with($code, 'driver.binary.')
            ? ($check['data']['remediation_commands'] ?? null)
            : null;

        if (! is_array($commands)) {
            return [];
        }

        $suggestions = [];

        foreach ($commands as $command) {
            if (is_string($command) && trim($command) !== '') {
                $suggestions[] = 'Run '.trim($command).'.';
            }
        }

        return $suggestions;
    }

    private function compactSuggestionForCheck(string $code): string
    {
        return match (true) {
            $code === 'queue.orphaned_runs' => 'checkpoint:recover-orphans',
            str_starts_with($code, 'backup_drill.') => 'checkpoint:drill',
            $code === 'backup.last_known_good' => 'Queue a backup',
            $code === 'backup.duration_anomaly' => 'Inspect backup duration history',
            $code === 'restore.post_verification' => 'Inspect restore verification results',
            $code === 'verification.runs' => 'Inspect verification runs',
            $code === 'restore.posture.environments' => 'Review restore policy',
            $code === 'restore.posture.databases' => 'Review database posture',
            $code === 'restore.posture.ci_bypass' => 'Review CI configuration',
            $code === 'restore.posture.verified_backup' => 'Enable verified backups',
            $code === 'config.backup_command' => 'Set backup command in config',
            str_starts_with($code, 'driver.binary.') => sprintf('Install %s', str($code)->after('driver.binary.')->replace('.', ' ')->toString()),
            str_starts_with($code, 'binary.') => sprintf('Install %s', str($code)->after('binary.')->replace('.', ' ')->toString()),
            default => 'Review check output',
        };
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    private function healthSloPayload(array $checks, int $failedCount, int $warnCount): array
    {
        $totalChecks = count($checks);
        $passCount = max(0, $totalChecks - $failedCount - $warnCount);
        $indicators = [
            [
                'name' => 'failed_checks',
                'target' => 0,
                'current' => $failedCount,
                'status' => $failedCount > 0 ? 'fail' : 'pass',
                'unit' => 'checks',
            ],
            [
                'name' => 'warning_checks',
                'target' => 0,
                'current' => $warnCount,
                'status' => $warnCount > 0 ? 'warn' : 'pass',
                'unit' => 'checks',
            ],
            [
                'name' => 'passing_checks',
                'target' => $totalChecks,
                'current' => $passCount,
                'status' => $failedCount > 0 ? 'fail' : ($warnCount > 0 ? 'warn' : 'pass'),
                'unit' => 'checks',
            ],
        ];

        return [
            'window' => 'current_health_snapshot',
            'indicators' => $indicators,
            'overall_status' => $failedCount > 0 ? 'fail' : ($warnCount > 0 ? 'warn' : 'pass'),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>,severity?:string}>  $checks
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    private function withSeverity(array $checks): array
    {
        return array_map(function (array $check): array {
            $status = $check['status'];
            $severity = match ($status) {
                'fail' => 'blocker',
                'warn' => 'warning',
                default => 'info',
            };

            return [
                'code' => $check['code'],
                'check' => $check['check'],
                'status' => $status,
                'severity' => $severity,
                'notes' => $check['notes'],
                'data' => $check['data'],
            ];
        }, $checks);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>,severity?:string}>  $checks
     */
    private function renderHealthTable(array $checks, bool $briefMode): void
    {
        $checks = $this->withSeverity($checks);

        if (! $briefMode) {
            $this->renderHealthFullTable($checks);

            return;
        }

        $this->renderHealthTriage($checks);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function renderHealthFullTable(array $checks): void
    {
        $orderedChecks = $this->orderedChecksForDisplay($checks);
        $visibleChecks = $this->shouldCollapsePassingChecks()
            ? array_values(array_filter($orderedChecks, static fn (array $check): bool => $check['status'] !== 'pass'))
            : $orderedChecks;

        $this->promptTable(['Check', 'Status', 'Priority', 'Severity', 'Notes'], array_map(
            fn (array $check): array => [
                $check['check'],
                $this->statusWord($check['status']),
                $this->priorityLabel((string) ($check['status'] ?? 'pass')),
                strtoupper($check['severity']),
                $check['notes'],
            ],
            $visibleChecks,
        ));

        if ($this->shouldCollapsePassingChecks()) {
            $suppressedPassChecks = count(array_filter($orderedChecks, static fn (array $check): bool => $check['status'] === 'pass'));

            if ($suppressedPassChecks > 0) {
                $this->line(sprintf('Suppressed %d passing checks (P2/P3). Re-run with -v for full detail.', $suppressedPassChecks));
            }
        }
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function renderHealthTriage(array $checks): void
    {
        $totals = $this->severityTotals($checks);
        $topIssues = $this->topIssues($checks, 3);
        $suggestions = $this->suggestionsFromChecks($checks, compact: true);
        $priorityCounts = $this->priorityCounts($checks);
        $suppressedPassChecks = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'pass'));

        $this->line('Doctor triage (brief)');
        $this->line(sprintf(
            'Blockers: %d | Warnings: %d | Info: %d',
            $totals['blocker'],
            $totals['warning'],
            $totals['info'],
        ));
        $this->line(sprintf('P0: %d | P1: %d | Suppressed lower-priority: %d', $priorityCounts['p0'], $priorityCounts['p1'], $suppressedPassChecks));
        $this->line(sprintf('Actionable warnings: %d', $this->effectiveWarningCount($checks)));

        if ($topIssues !== []) {
            $issue = $topIssues[0];
            $this->line(sprintf('Top issue: [%s] %s — %s', strtoupper($issue['severity']), $issue['check'], $issue['notes']));
        }

        $this->line('Action now: '.($suggestions[0] ?? 'Run checkpoint:doctor --format=json for full issue details.'));
        $this->line('Deep dive: php artisan checkpoint:doctor --format=json');
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return array{blocker:int,warning:int,info:int}
     */
    private function severityTotals(array $checks): array
    {
        $blocker = count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'blocker'));
        $warning = count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'warning'));

        return [
            'blocker' => $blocker,
            'warning' => $warning,
            'info' => max(0, count($checks) - $blocker - $warning),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return list<array<string, mixed>>
     */
    private function topIssues(array $checks, int $limit): array
    {
        $issues = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] !== 'pass'));
        $issues = $this->orderedChecksForDisplay($issues);

        return array_slice($issues, 0, max(1, $limit));
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return array{p0:int,p1:int}
     */
    private function priorityCounts(array $checks): array
    {
        return [
            'p0' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail')),
            'p1' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'warn')),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function effectiveWarningCount(array $checks): int
    {
        $environment = app()->environment();

        if (! in_array($environment, ['local', 'testing'], true)) {
            return count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'warning'));
        }

        $advisoryCodes = [
            'queue.worker_visibility',
            'restore.post_verification',
            'backup.last_known_good',
            'backup.duration_anomaly',
            'backup_drill.latest_run',
            'backup_drill.pass_rate',
            'backup_drill.trend',
            'backup_drill.playbook',
            'verification.runs',
        ];

        return count(array_filter($checks, static fn (array $check): bool => $check['severity'] === 'warning'
            && ! in_array($check['code'], $advisoryCodes, true)));
    }

    /**
     * @return array{payload: array<string, mixed>, exitCode: int}
     */
    private function buildPitrReport(?string $targetInput): array
    {
        try {
            $payload = $this->buildPitrReadinessReport->execute($targetInput);
            $exitCode = $payload['readiness'] === 'ready' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $exception) {
            report($exception);

            $payload = [
                'generated_at' => now()->toIso8601String(),
                'target' => null,
                'readiness' => 'not_ready',
                'checks' => [[
                    'code' => 'pitr_readiness.error',
                    'status' => 'fail',
                    'message' => $exception->getMessage(),
                    'data' => ['exception' => $exception::class],
                ]],
                'summary' => [
                    'pass' => 0,
                    'fail' => 1,
                ],
            ];
            $exitCode = self::FAILURE;
        }

        return ['payload' => $payload, 'exitCode' => $exitCode];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPitrAgentOutput(array $payload, int $exitCode): int
    {
        $this->line(json_encode($this->jsonContract->envelope('pitr_readiness', [
            'result' => $payload['readiness'] === 'ready' ? 'passed' : 'failed',
            'code' => $payload['readiness'] === 'ready'
                ? 'pitr.readiness.ready'
                : 'pitr.readiness.not_ready',
            'summary' => sprintf(
                'PITR readiness: %s (%d pass, %d fail).',
                $payload['readiness'],
                $payload['summary']['pass'],
                $payload['summary']['fail'],
            ),
            'data' => [
                ...$payload,
                'driver' => (string) $this->config->get('checkpoint.driver'),
            ],
            'suggestions' => $this->pitrSuggestions($payload),
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exitCode;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPitrJsonOutput(array $payload, int $exitCode, bool $compactJson): int
    {
        $payload = [
            ...$payload,
            'driver' => (string) $this->config->get('checkpoint.driver'),
        ];

        $payload = $compactJson
            ? $this->jsonContract->compactEnvelope('pitr_readiness', $payload)
            : $this->jsonContract->envelope('pitr_readiness', $payload);

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $exitCode;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function renderPitrTableOutput(array $payload, int $exitCode): int
    {
        $this->promptTable(['Field', 'Value'], [
            ['Driver', (string) $this->config->get('checkpoint.driver')],
            ['Target', $payload['target'] ?? '-'],
            ['Readiness', $payload['readiness']],
            ['Pass checks', (string) $payload['summary']['pass']],
            ['Fail checks', (string) $payload['summary']['fail']],
        ]);

        $checks = $payload['checks'];
        $this->promptTable(
            ['Check', 'Status', 'Message'],
            array_values(array_map(
                static fn (array $check): array => [
                    $check['code'],
                    $check['status'],
                    $check['message'],
                ],
                $checks,
            )),
        );

        return $exitCode;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function pitrSuggestions(array $payload): array
    {
        if (($payload['readiness'] ?? 'not_ready') === 'ready') {
            return [];
        }

        $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];
        $suggestions = [];

        foreach ($checks as $check) {
            if (($check['status'] ?? null) !== 'fail') {
                continue;
            }

            $code = (string) ($check['code'] ?? '');

            if ($code === 'baseline.last_known_good') {
                $suggestions[] = 'Run a successful logical backup to establish a last-known-good PITR baseline.';
            } elseif ($code === 'baseline.artifact_exists') {
                $suggestions[] = 'Restore baseline artifact availability before PITR by fixing storage/path retention.';
            } elseif ($code === 'binlog.chain_configured') {
                $suggestions[] = 'Configure checkpoint.drivers.mysql.pitr.binlog_files with the active MySQL binlog chain.';
            } elseif ($code === 'binlog.chain_files_exist') {
                $suggestions[] = 'Ensure configured MySQL binlog files exist and are readable by workers.';
            } elseif ($code === 'target.not_future') {
                $suggestions[] = 'Use a PITR target timestamp that is not in the future.';
            } elseif ($code === 'target.after_baseline') {
                $suggestions[] = 'Choose a PITR target at or after the baseline last-known-good timestamp.';
            }
        }

        return array_values(array_unique($suggestions));
    }

    /**
     * @return array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}
     */
    private function buildReportPayload(int $effectiveLimit): array
    {
        try {
            return $this->reportBuilder->reportPayload($effectiveLimit);
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'recent_runs' => [],
                'summary' => $this->reportEmptySummary(),
                'breakdown' => $this->reportEmptyBreakdown(),
                'verification' => $this->reportEmptyVerificationSummary(),
                'health' => [
                    'ok' => false,
                    'checks' => [[
                        'code' => 'report.error',
                        'check' => 'Report execution',
                        'status' => 'fail',
                        'notes' => $exception->getMessage(),
                        'data' => ['exception' => $exception::class],
                    ]],
                ],
            ];
        }
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     * @param  array<string,mixed>  $gateDecision
     */
    private function renderReportAgentOutput(int $requestedLimit, int $effectiveLimit, array $reportPayload, bool $briefMode, array $gateDecision): void
    {
        $this->line(json_encode($this->jsonContract->envelope('report', $this->reportAgentReportPayload(
            requestedLimit: $requestedLimit,
            effectiveLimit: $effectiveLimit,
            reportPayload: $reportPayload,
            briefMode: $briefMode,
            gateDecision: $gateDecision,
        )), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     * @param  array<string,mixed>  $gateDecision
     */
    private function renderReportJsonOutput(string $outputMode, array $reportPayload, int $requestedLimit, int $effectiveLimit, bool $briefMode, array $gateDecision): void
    {
        $compactJson = $outputMode === 'compact-json';
        $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
            ? $reportPayload['summary']['latest_failed_run']
            : ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null];

        $reportPayloadData = [
            'mode' => $briefMode ? 'brief' : 'full',
            'generated_at' => now()->toIso8601String(),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'limit_requested' => $requestedLimit,
            'limit' => $effectiveLimit,
            'gates' => $this->machineGateDecision($gateDecision),
            'last_failed_run' => $lastFailedRun,
            'recent_runs' => $reportPayload['recent_runs'],
            'summary' => $reportPayload['summary'],
            'breakdown' => $reportPayload['breakdown'],
            'verification' => $reportPayload['verification'],
            'health' => $reportPayload['health'],
        ];

        $reportPayloadData = $compactJson
            ? $this->jsonContract->compactEnvelope('report', $reportPayloadData)
            : $this->jsonContract->envelope('report', $reportPayloadData);

        $this->line(json_encode($reportPayloadData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptySummary(): array
    {
        $windowDays = max(1, (int) $this->config->get('checkpoint.observability.backup_drill_pass_rate_window_days', 30));
        $drillPassRate = $this->reportEmptyDrillPassRate($windowDays);

        return [
            'pending_runs' => 0,
            'running_runs' => 0,
            'failed_runs_24h' => 0,
            'last_known_good_backup' => ['label' => '-', 'timestamp' => null, 'operation' => null],
            'latest_verified_backup' => ['label' => '-', 'timestamp' => null, 'operation' => null],
            'latest_backup_drill' => ['label' => '-', 'timestamp' => null, 'run_uuid' => null, 'overall_result' => null, 'executed_by' => null],
            'latest_failed_backup_drill' => ['label' => '-', 'timestamp' => null, 'run_uuid' => null, 'overall_result' => null, 'executed_by' => null],
            'backup_drill_pass_rate' => $drillPassRate,
            'backup_drill_pass_rate_30d' => $drillPassRate,
            'backup_drill_trend' => $this->reportEmptyDrillTrend($windowDays),
            'backup_drill_remediation_playbook' => $this->reportEmptyDrillRemediationPlaybook($windowDays),
            'latest_restore_run' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'target' => null, 'audit' => null],
            'latest_restore_failure' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'target' => null],
            'latest_failed_run' => ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptyDrillPassRate(int $windowDays): array
    {
        return [
            'label' => '-',
            'window_days' => $windowDays,
            'total' => 0,
            'passing' => 0,
            'pass_rate_percent' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptyDrillTrend(int $windowDays): array
    {
        return [
            'label' => '-',
            'window_days' => $windowDays,
            'sample_size' => 0,
            'latest_result' => null,
            'latest_run_uuid' => null,
            'latest_executed_at' => null,
            'streak' => ['type' => null, 'length' => 0],
            'recent' => ['results' => [], 'passing' => 0, 'failing' => 0, 'outcomes' => []],
            'trajectory' => 'insufficient_data',
            'status' => 'insufficient_data',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportEmptyDrillRemediationPlaybook(int $windowDays): array
    {
        return [
            'signature' => 'drill.missing_run',
            'severity' => 'critical',
            'title' => 'No backup drill evidence available',
            'summary' => 'No backup drill run is recorded. Schedule and record a drill run before relying on restore readiness.',
            'recommended_commands' => ['checkpoint:drill'],
            'steps' => [],
            'evidence' => [
                'window_days' => $windowDays,
                'total' => 0,
                'passing' => 0,
                'pass_rate_percent' => 0.0,
                'minimum_pass_rate_percent' => (float) $this->config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0),
                'latest_result' => null,
                'latest_run_uuid' => null,
                'latest_age_days' => null,
                'max_age_days' => max(1, (int) $this->config->get('checkpoint.observability.max_backup_drill_age_days', 30)),
                'trend_status' => 'insufficient_data',
                'trend_trajectory' => 'insufficient_data',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reportEmptyBreakdown(): array
    {
        return [
            'window' => [
                'failed_runs_hours' => 24,
            ],
            'totals' => [
                'groups' => 0,
                'runs' => 0,
                'failed_runs_24h' => 0,
            ],
            'by_target' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function reportEmptyVerificationSummary(): array
    {
        return [
            'total_runs' => 0,
            'verified_runs' => 0,
            'failed_runs' => 0,
            'success_rate_percent' => null,
            'health_status' => 'warn',
            'latest' => [
                'id' => null,
                'command_run_id' => null,
                'verification_type' => null,
                'status' => null,
                'verified_at' => null,
            ],
        ];
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     * @param  array<string,mixed>  $gateDecision
     * @return array<string,mixed>
     */
    private function reportAgentReportPayload(int $requestedLimit, int $effectiveLimit, array $reportPayload, bool $briefMode, array $gateDecision): array
    {
        $checks = $reportPayload['health']['checks'];
        $failedChecks = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'fail'));
        $warnChecks = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'warn'));
        $failedRuns = count(array_filter($reportPayload['recent_runs'], static fn (array $run): bool => (string) ($run['status'] ?? '') === 'failed'));
        $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
            ? $reportPayload['summary']['latest_failed_run']
            : ['label' => '-', 'timestamp' => null, 'operation' => null, 'status' => null, 'exit_code' => null, 'failure_reason' => null, 'next_action' => null];

        $result = $failedChecks > 0 ? 'failed' : (($warnChecks > 0 || $failedRuns > 0) ? 'partial' : 'passed');
        $code = $failedChecks > 0 ? 'report.health.failed' : (($warnChecks > 0 || $failedRuns > 0) ? 'report.health.warn' : 'report.health.ok');

        return [
            'result' => $result,
            'code' => $code,
            'summary' => sprintf(
                'Recent failed runs: %d; health checks: %d fail, %d warn.',
                $failedRuns,
                $failedChecks,
                $warnChecks,
            ),
            'data' => [
                'mode' => $briefMode ? 'brief' : 'full',
                'generated_at' => now()->toIso8601String(),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'limit_requested' => $requestedLimit,
                'limit' => $effectiveLimit,
                'gates' => $gateDecision,
                'last_failed_run' => $lastFailedRun,
                'recent_runs' => $reportPayload['recent_runs'],
                'summary' => $reportPayload['summary'],
                'breakdown' => $reportPayload['breakdown'],
                'verification' => $reportPayload['verification'],
                'health' => $reportPayload['health'],
                'slo' => $this->reportSloPayload(
                    reportPayload: $reportPayload,
                    effectiveLimit: $effectiveLimit,
                    failedRuns: $failedRuns,
                    failedChecks: $failedChecks,
                    warnChecks: $warnChecks,
                ),
            ],
            'suggestions' => $this->reportSuggestions($checks, $failedRuns, $lastFailedRun, compact: $briefMode),
        ];
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,breakdown:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    private function reportSloPayload(array $reportPayload, int $effectiveLimit, int $failedRuns, int $failedChecks, int $warnChecks): array
    {
        $runCount = count($reportPayload['recent_runs']);
        $failureRate = $runCount > 0 ? round(($failedRuns / $runCount) * 100, 2) : 0.0;
        $drillTarget = (float) $this->config->get('checkpoint.observability.backup_drill_min_pass_rate', 100.0);
        $drillCurrent = $reportPayload['summary']['backup_drill_pass_rate']['pass_rate_percent'] ?? null;
        $drillCurrentValue = is_numeric($drillCurrent) ? (float) $drillCurrent : 0.0;
        $drillWindowDays = (int) ($reportPayload['summary']['backup_drill_pass_rate']['window_days'] ?? 30);
        $drillStatus = is_numeric($drillCurrent) && $drillCurrentValue >= $drillTarget ? 'pass' : 'warn';
        $verificationStatus = (string) ($reportPayload['verification']['health_status'] ?? 'warn');
        $verificationFailed = is_numeric($reportPayload['verification']['failed_runs'] ?? null) ? (int) $reportPayload['verification']['failed_runs'] : 0;
        $verificationSuccessRate = is_numeric($reportPayload['verification']['success_rate_percent'] ?? null) ? (float) $reportPayload['verification']['success_rate_percent'] : 0.0;
        $failedTargets24h = 0;

        if (is_numeric($reportPayload['breakdown']['totals']['failed_runs_24h'] ?? null)) {
            $failedTargets24h = (int) $reportPayload['breakdown']['totals']['failed_runs_24h'];
        }

        $indicators = $this->buildReportSloIndicators(
            $failedRuns,
            $failedChecks,
            $warnChecks,
            $failureRate,
            $drillTarget,
            $drillCurrentValue,
            $drillStatus,
            $verificationStatus,
            $verificationFailed,
            $verificationSuccessRate,
            $failedTargets24h,
        );

        return [
            'window' => sprintf('latest_%d_runs+%dd_drills', $effectiveLimit, $drillWindowDays),
            'indicators' => $indicators,
            'overall_status' => $this->overallSloStatus($indicators),
        ];
    }

    /**
     * @return array{name:string,target:int|float,current:int|float,status:string,unit:string}
     */
    private function makeReportSloIndicator(string $name, int|float $target, int|float $current, string $status, string $unit): array
    {
        return compact('name', 'target', 'current', 'status', 'unit');
    }

    /**
     * @return list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>
     */
    private function buildReportSloIndicators(
        int $failedRuns,
        int $failedChecks,
        int $warnChecks,
        float $failureRate,
        float $drillTarget,
        float $drillCurrentValue,
        string $drillStatus,
        string $verificationStatus,
        int $verificationFailed,
        float $verificationSuccessRate,
        int $failedTargets24h,
    ): array {
        return [
            $this->makeReportSloIndicator('failed_runs', 0, $failedRuns, $failedRuns > 0 ? 'fail' : 'pass', 'runs'),
            $this->makeReportSloIndicator('failed_checks', 0, $failedChecks, $failedChecks > 0 ? 'fail' : 'pass', 'checks'),
            $this->makeReportSloIndicator('warning_checks', 0, $warnChecks, $warnChecks > 0 ? 'warn' : 'pass', 'checks'),
            $this->makeReportSloIndicator('recent_failure_rate', 0, $failureRate, $failedRuns > 0 ? 'fail' : 'pass', 'percent'),
            $this->makeReportSloIndicator('backup_drill_pass_rate', $drillTarget, round($drillCurrentValue, 2), $drillStatus, 'percent'),
            $this->makeReportSloIndicator('verification_failed_runs', 0, $verificationFailed, $verificationStatus === 'pass' ? 'pass' : 'warn', 'runs'),
            $this->makeReportSloIndicator('verification_success_rate', 100, round($verificationSuccessRate, 2), $verificationStatus === 'pass' ? 'pass' : 'warn', 'percent'),
            $this->makeReportSloIndicator('failed_runs_24h_by_target', 0, $failedTargets24h, $failedTargets24h > 0 ? 'fail' : 'pass', 'runs'),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @param  array{label?:string,timestamp?:string|null,operation?:string|null,status?:string|null,exit_code?:int|null,failure_reason?:string|null,next_action?:string|null}  $lastFailedRun
     * @return list<string>
     */
    private function reportSuggestions(array $checks, int $failedRuns, array $lastFailedRun, bool $compact = false): array
    {
        $suggestions = [];
        $checks = $this->orderedChecksForDisplay($checks);

        if ($failedRuns > 0) {
            $suggestions[] = $compact ? 'Inspect failed runs' : 'Inspect failed runs in data.recent_runs and rerun impacted operation with corrected inputs.';
        }

        if (is_string($lastFailedRun['next_action'] ?? null) && trim($lastFailedRun['next_action']) !== '') {
            $suggestions[] = trim($lastFailedRun['next_action']);
        }

        foreach ($checks as $check) {
            if ((string) $check['status'] === 'pass') {
                continue;
            }

            $code = (string) $check['code'];

            if ($code === 'queue.orphaned_runs') {
                $suggestions[] = $compact ? 'checkpoint:recover-orphans' : 'Run checkpoint:recover-orphans and verify worker heartbeat settings.';
            } elseif (str_starts_with($code, 'backup_drill.')) {
                $suggestions[] = $compact ? 'checkpoint:drill' : 'Run a backup drill and track pass-rate/freshness health signals.';

                $playbookCommands = $check['data']['recommended_commands'] ?? null;

                if (is_array($playbookCommands)) {
                    foreach ($playbookCommands as $command) {
                        if (is_string($command) && trim($command) !== '') {
                            $suggestions[] = $compact ? $command : 'Run '.$command.' to execute the drill remediation playbook.';
                        }
                    }
                }
            } elseif ($code === 'backup.last_known_good') {
                $suggestions[] = $compact ? 'Queue a backup' : 'Queue a successful backup to refresh the last-known-good signal.';
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportTableReport(array $reportPayload, int $requestedLimit, int $effectiveLimit, bool $briefMode): void
    {
        if ($briefMode) {
            $this->renderReportBriefTableReport($reportPayload);

            return;
        }

        $this->renderReportFullTableReport($reportPayload, $requestedLimit, $effectiveLimit);
    }

    /**
     * @param  array{summary:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportBriefTableReport(array $reportPayload): void
    {
        $lastFailedRun = is_array($reportPayload['summary']['latest_failed_run'] ?? null)
            ? $reportPayload['summary']['latest_failed_run']
            : [];
        $failedChecks = count(array_filter($reportPayload['health']['checks'], static fn (array $check): bool => (string) $check['status'] === 'fail'));
        $warnChecks = count(array_filter($reportPayload['health']['checks'], static fn (array $check): bool => (string) $check['status'] === 'warn'));
        $suppressedLowerPriority = count(array_filter($reportPayload['health']['checks'], static fn (array $check): bool => (string) $check['status'] === 'pass'));
        $reason = (string) ($lastFailedRun['failure_reason'] ?? 'No recent failed run reason available.');
        $actionNow = (string) ($lastFailedRun['next_action'] ?? 'Run php artisan checkpoint:doctor --full --limit=10 --format=json for full failure context.');

        $this->line('Checkpoint report (brief)');
        $this->line(sprintf(
            'Failed runs (24h): %d | Health checks: %d fail, %d warn',
            (int) ($reportPayload['summary']['failed_runs_24h'] ?? 0),
            $failedChecks,
            $warnChecks,
        ));
        $this->line(sprintf('P0: %d | P1: %d | Suppressed lower-priority: %d', $failedChecks, $warnChecks, $suppressedLowerPriority));
        $this->line('Last failed: '.(string) ($lastFailedRun['label'] ?? '-'));
        $this->line('Cause: '.$reason);
        $this->line('Action now: '.$actionNow);
        $this->line('Deep dive: php artisan checkpoint:doctor --full --limit=10 --format=json');
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportFullTableReport(array $reportPayload, int $requestedLimit, int $effectiveLimit): void
    {
        $this->renderReportSummaryTable($reportPayload, $requestedLimit, $effectiveLimit);
        $this->renderReportRecentRunsTable($reportPayload);
        $this->renderReportHealthChecksTable($reportPayload);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>,summary:array<string,mixed>,verification:array<string,mixed>,health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportSummaryTable(array $reportPayload, int $requestedLimit, int $effectiveLimit): void
    {
        $this->promptTable(['Field', 'Value'], [
            ['Driver', (string) $this->config->get('checkpoint.driver')],
            ['Limit requested', (string) $requestedLimit],
            ['Limit applied', (string) $effectiveLimit],
            ['Recent runs returned', (string) count($reportPayload['recent_runs'])],
            ['Health OK', $reportPayload['health']['ok'] ? 'yes' : 'no'],
            ['Pending runs', (string) ($reportPayload['summary']['pending_runs'] ?? 0)],
            ['Running runs', (string) ($reportPayload['summary']['running_runs'] ?? 0)],
            ['Failed runs (24h)', (string) ($reportPayload['summary']['failed_runs_24h'] ?? 0)],
            ['Last known good backup', (string) ($reportPayload['summary']['last_known_good_backup']['label'] ?? '-')],
            ['Latest verified backup', (string) ($reportPayload['summary']['latest_verified_backup']['label'] ?? '-')],
            ['Latest backup drill', (string) ($reportPayload['summary']['latest_backup_drill']['label'] ?? '-')],
            ['Latest failed drill', (string) ($reportPayload['summary']['latest_failed_backup_drill']['label'] ?? '-')],
            ['Drill remediation playbook', (string) ($reportPayload['summary']['backup_drill_remediation_playbook']['title'] ?? '-')],
            ['Latest restore run', (string) ($reportPayload['summary']['latest_restore_run']['label'] ?? '-')],
            ['Latest restore failure', (string) ($reportPayload['summary']['latest_restore_failure']['label'] ?? '-')],
            ['Latest restore post-verification', (string) ($reportPayload['summary']['latest_restore_run']['post_restore_verification']['aggregate_result'] ?? '-')],
            ['Verification runs', (string) ($reportPayload['verification']['total_runs'] ?? 0)],
            ['Verification failed', (string) ($reportPayload['verification']['failed_runs'] ?? 0)],
            ['Verification health', (string) ($reportPayload['verification']['health_status'] ?? 'warn')],
        ]);
    }

    /**
     * @param  array{recent_runs:list<array<string,mixed>>}  $reportPayload
     */
    private function renderReportRecentRunsTable(array $reportPayload): void
    {
        $recentRuns = $reportPayload['recent_runs'];

        if ($recentRuns === []) {
            return;
        }

        $this->promptTable(['ID', 'Operation', 'Status', 'Exit', 'Backup', 'Verify', 'Started', 'Finished'], array_map(
            static fn (array $run): array => [
                (string) ($run['id'] ?? '-'),
                (string) ($run['operation'] ?? '-'),
                (string) ($run['status'] ?? '-'),
                $run['exit_code'] !== null ? (string) $run['exit_code'] : '-',
                (string) ($run['backup'] ?? '-'),
                (string) ($run['verification_state'] ?? '-'),
                (string) ($run['started_at'] ?? '-'),
                (string) ($run['finished_at'] ?? '-'),
            ],
            $recentRuns,
        ));
    }

    /**
     * @param  array{health:array{ok:bool,checks:list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>}}  $reportPayload
     */
    private function renderReportHealthChecksTable(array $reportPayload): void
    {
        $orderedChecks = $this->orderedChecksForDisplay($reportPayload['health']['checks']);
        $visibleChecks = $this->shouldCollapsePassingChecks()
            ? array_values(array_filter($orderedChecks, static fn (array $check): bool => (string) ($check['status'] ?? '') !== 'pass'))
            : $orderedChecks;

        $this->promptTable(['Check', 'Status', 'Priority', 'Notes'], array_map(
            fn (array $check): array => [
                (string) ($check['check'] ?? '-'),
                (string) ($check['status'] ?? '-'),
                $this->priorityLabel((string) ($check['status'] ?? 'pass')),
                (string) ($check['notes'] ?? '-'),
            ],
            $visibleChecks,
        ));

        if ($this->shouldCollapsePassingChecks()) {
            $suppressedPassChecks = count(array_filter($orderedChecks, static fn (array $check): bool => (string) ($check['status'] ?? '') === 'pass'));

            if ($suppressedPassChecks > 0) {
                $this->line(sprintf('Suppressed %d passing checks (P2/P3). Re-run with -v for full detail.', $suppressedPassChecks));
            }
        }
    }
}
