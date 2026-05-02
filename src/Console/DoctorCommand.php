<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Console;

use AdityaaCodes\LaravelCheckpoint\Console\Concerns\UsesLaravelPrompts;
use AdityaaCodes\LaravelCheckpoint\Services\CommandJsonContract;
use AdityaaCodes\LaravelCheckpoint\Services\ConfigValidator;
use AdityaaCodes\LaravelCheckpoint\Services\GatePolicyEvaluator;
use AdityaaCodes\LaravelCheckpoint\Services\OperationalReportBuilder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;

final class DoctorCommand extends Command
{
    use UsesLaravelPrompts;

    protected $signature = 'checkpoint:doctor {--brief : Show triage-first health output with top issues and next action.} {--format=table} {--agent : Emit compact AI-agent friendly JSON output.} {--policy-profile= : Override gate policy profile for CI/automation.}';

    protected $description = 'Show checkpoint package health checks.';

    protected $aliases = ['checkpoint:check:doctor'];

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly Repository $config,
        private readonly OperationalReportBuilder $reportBuilder,
        private readonly CommandJsonContract $jsonContract,
        private readonly GatePolicyEvaluator $gatePolicyEvaluator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = $this->stringOption('format') ?? 'table';
        $agentMode = (bool) $this->option('agent');
        $briefMode = (bool) $this->option('brief');
        $policyProfile = $this->policyProfileOverride();
        $outputMode = $agentMode ? 'agent' : (in_array($format, ['table', 'json'], true) ? $format : 'table');

        if ($this->enhancedInteractiveMode() && $outputMode === 'table') {
            intro('Checkpoint Doctor: Health Checks');
            note('What: validate config posture, binaries, storage, queue, and safety signals.');
            note('When: during setup, after config edits, or while debugging failures.');
            note('Next: if healthy, continue with checkpoint:do:backup and checkpoint:do:status.');
        }

        try {
            $this->validator->validate();
            $checks = $this->reportBuilder->healthChecks();
            $gateDecision = $this->gatePolicyEvaluator->evaluate($checks, $this->reportBuilder->summary(), $policyProfile);
            $exitCode = $gateDecision['exit_code'];
        } catch (\Throwable $exception) {
            $checks = [[
                'code' => 'config.validation',
                'check' => 'Config validation',
                'status' => 'fail',
                'severity' => 'blocker',
                'notes' => $exception->getMessage(),
                'data' => [
                    'exception' => $exception::class,
                ],
            ]];
            $gateDecision = $this->gatePolicyEvaluator->evaluate($checks, [], $policyProfile);

            if ($outputMode === 'json') {
                $this->line($this->jsonReport($checks, $briefMode, $gateDecision));

                return $gateDecision['exit_code'];
            }

            if ($outputMode === 'agent') {
                $this->line($this->agentReport($checks, $briefMode, $gateDecision));

                return $gateDecision['exit_code'];
            }

            $this->renderTable($checks, $briefMode);

            return $gateDecision['exit_code'];
        }

        if ($outputMode === 'json') {
            $this->line($this->jsonReport($checks, $briefMode, $gateDecision));

            return $exitCode;
        }

        if ($outputMode === 'agent') {
            $this->line($this->agentReport($checks, $briefMode, $gateDecision));

            return $exitCode;
        }

        $this->renderTable($checks, $briefMode);

        return $exitCode;
    }

    private function statusWord(string $level): string
    {
        $key = match ($level) {
            'pass' => 'messages.cli.doctor_pass',
            'warn' => 'messages.cli.doctor_warn',
            default => 'messages.cli.doctor_fail',
        };

        $value = (string) __($key);

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
     */
    private function jsonReport(array $checks, bool $briefMode, array $gateDecision): string
    {
        $checks = $this->withSeverity($checks);
        if (! $briefMode) {
            $report = [
                'ok' => $this->reportBuilder->healthOk($checks),
                'driver' => (string) $this->config->get('checkpoint.driver'),
                'generated_at' => now()->toIso8601String(),
                'gates' => $this->machineGateDecision($gateDecision),
                'checks' => array_map(fn (array $check): array => [
                    'code' => $check['code'],
                    'check' => $check['check'],
                    'status' => $check['status'],
                    'severity' => $check['severity'],
                    'notes' => $check['notes'],
                    'data' => $check['data'],
                ], $checks),
            ];

            $report = $this->jsonContract->envelope('doctor', $report);

            return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $totals = $this->severityTotals($checks);
        $report = [
            'ok' => $this->reportBuilder->healthOk($checks),
            'driver' => (string) $this->config->get('checkpoint.driver'),
            'generated_at' => now()->toIso8601String(),
            'mode' => 'brief',
            'gates' => $this->machineGateDecision($gateDecision),
            'totals' => $totals,
            'checks' => array_map(fn (array $check): array => [
                'code' => $check['code'],
                'check' => $check['check'],
                'status' => $check['status'],
                'severity' => $check['severity'],
                'notes' => $check['notes'],
                'data' => $check['data'],
            ], $this->topIssues($checks, 3)),
            'next_actions' => array_slice($this->suggestionsFromChecks($checks), 0, 3),
        ];

        $report = $this->jsonContract->envelope('doctor', $report);

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) ? $value : null;
    }

    private function policyProfileOverride(): ?string
    {
        $override = $this->stringOption('policy-profile');

        if (! is_string($override)) {
            return null;
        }

        $override = trim($override);

        return $override !== '' ? $override : null;
    }

    /**
     * @param  array<string, mixed>  $gateDecision
     * @return array{profile:string,profile_source:string,verdict:string,failed_gate:string,exit_code:int}
     */
    private function machineGateDecision(array $gateDecision): array
    {
        return [
            'profile' => (string) ($gateDecision['profile'] ?? 'unknown'),
            'profile_source' => (string) ($gateDecision['profile_source'] ?? 'default'),
            'verdict' => (string) ($gateDecision['verdict'] ?? 'fail'),
            'failed_gate' => (string) ($gateDecision['failed_gate'] ?? 'policy'),
            'exit_code' => (int) ($gateDecision['exit_code'] ?? 12),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     */
    private function agentReport(array $checks, bool $briefMode, array $gateDecision): string
    {
        $checks = $this->withSeverity($checks);
        $failedCount = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'fail'));
        $warnCount = count(array_filter($checks, static fn (array $check): bool => (string) $check['status'] === 'warn'));
        $totals = $this->severityTotals($checks);
        $suggestions = $this->suggestionsFromChecks($checks);
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
                'slo' => $this->sloPayload($checks, $failedCount, $warnCount),
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
    private function suggestionsFromChecks(array $checks): array
    {
        $suggestions = [];

        foreach ($checks as $check) {
            if ((string) $check['status'] === 'pass') {
                continue;
            }

            $code = (string) $check['code'];

            if ($code === 'config.validation') {
                $suggestions[] = 'Fix checkpoint config validation errors and rerun checkpoint:doctor --agent.';

                continue;
            }

            if ($code === 'queue.orphaned_runs') {
                $suggestions[] = 'Run checkpoint:recover-orphans and ensure queue workers emit regular heartbeats.';

                continue;
            }

            if (str_starts_with($code, 'backup_drill.')) {
                $suggestions[] = 'Run checkpoint:enqueue-drill (or record a drill run) and verify freshness, pass-rate, and trend thresholds.';

                $playbookCommands = $check['data']['recommended_commands'] ?? null;

                if (is_array($playbookCommands)) {
                    foreach ($playbookCommands as $command) {
                        if (is_string($command) && trim($command) !== '') {
                            $suggestions[] = 'Run '.$command.' to execute the drill remediation playbook.';
                        }
                    }
                }

                continue;
            }

            if ($code === 'backup.last_known_good') {
                $suggestions[] = 'Queue a fresh backup and verify last-known-good signals are updated.';

                continue;
            }

            if ($code === 'restore.post_verification') {
                $suggestions[] = 'Inspect latest restore post-verification checks in doctor/report output and rerun restore with corrected inputs if checks failed.';

                continue;
            }

            if ($code === 'verification.runs') {
                $suggestions[] = 'Run verification commands and inspect failed verification runs to restore healthy verification coverage.';

                continue;
            }

            $commands = str_starts_with($code, 'driver.binary.')
                ? ($check['data']['remediation_commands'] ?? null)
                : null;

            if (is_array($commands)) {
                foreach ($commands as $command) {
                    if (is_string($command) && trim($command) !== '') {
                        $suggestions[] = 'Run '.trim($command).'.';
                    }
                }
            }
        }

        $suggestions = array_values(array_unique($suggestions));

        return array_slice($suggestions, 0, 5);
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>}>  $checks
     * @return array{window:string,indicators:list<array{name:string,target:int|float,current:int|float,status:string,unit:string}>,overall_status:string}
     */
    private function sloPayload(array $checks, int $failedCount, int $warnCount): array
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
    private function renderTable(array $checks, bool $briefMode): void
    {
        $checks = $this->withSeverity($checks);

        if (! $briefMode) {
            $orderedChecks = $this->orderedChecksForDisplay($checks);
            $visibleChecks = $this->shouldCollapsePassingChecks()
                ? array_values(array_filter($orderedChecks, static fn (array $check): bool => $check['status'] !== 'pass'))
                : $orderedChecks;

            $this->promptTable(['Check', 'Status', 'Priority', 'Severity', 'Notes'], array_map(
                fn (array $check): array => [
                    $check['check'],
                    $this->statusWord($check['status']),
                    $this->priorityLabel($check),
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

            return;
        }

        $totals = $this->severityTotals($checks);
        $topIssues = $this->topIssues($checks, 3);
        $suggestions = $this->suggestionsFromChecks($checks);
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
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    private function topIssues(array $checks, int $limit): array
    {
        $issues = array_values(array_filter($checks, static fn (array $check): bool => $check['status'] !== 'pass'));
        $issues = $this->orderedChecksForDisplay($issues);

        return array_slice($issues, 0, max(1, $limit));
    }

    /**
     * @param  list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>  $checks
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    private function orderedChecksForDisplay(array $checks): array
    {
        usort($checks, function (array $left, array $right): int {
            $rank = [
                'fail' => 0,
                'warn' => 1,
                'pass' => 2,
            ];

            $leftRank = $rank[$left['status']] ?? 3;
            $rightRank = $rank[$right['status']] ?? 3;

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) $left['check'], (string) $right['check']);
        });

        return $checks;
    }

    private function shouldCollapsePassingChecks(): bool
    {
        return ! $this->getOutput()->isVerbose();
    }

    /**
     * @param  array{status:string,severity:string}  $check
     */
    private function priorityLabel(array $check): string
    {
        return match ($check['status']) {
            'fail' => 'P0',
            'warn' => 'P1',
            default => 'P3',
        };
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
}
