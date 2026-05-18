<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

/** @internal */
final readonly class CommandJsonContract
{
    /**
     * @var array<string, int>
     */
    private const array SURFACE_VERSIONS = [
        'catalog_export' => 1,
        'doctor' => 3,
        'pitr_readiness' => 1,
        'retention_policy' => 1,
        'report' => 2,
        'status' => 1,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function envelope(string $surface, array $payload): array
    {
        $isAgentContractPayload = isset($payload['result'], $payload['code'], $payload['summary'], $payload['data']);

        $compact = $isAgentContractPayload ? $this->compactBlock($payload) : null;

        return [
            ...$payload,
            ...($compact !== null ? ['compact' => $compact] : []),
            ...($isAgentContractPayload ? ['schema_version' => self::SURFACE_VERSIONS[$surface] ?? 1] : []),
            'version' => self::SURFACE_VERSIONS[$surface] ?? 1,
            'surface' => $surface,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{verdict:string,severity:string,top_issue:?string,next_action:?string,exit_code:int}
     */
    private function compactBlock(array $payload): array
    {
        $verdict = match ((string) ($payload['result'] ?? '')) {
            'passed' => 'PASS',
            'partial' => 'WARN',
            'failed' => 'FAIL',
            default => 'UNKNOWN',
        };

        $severity = match ($verdict) {
            'FAIL' => 'P0',
            'WARN' => 'P1',
            'PASS' => 'NONE',
            default => 'P2',
        };

        $gateDecision = $payload['data']['gates'] ?? null;
        if (is_array($gateDecision)) {
            $gate = (string) ($gateDecision['failed_gate'] ?? 'none');
            if ($gate === 'evidence') {
                $severity = 'P1';
            } elseif ($gate === 'safety') {
                $severity = 'P0';
            }
        }

        $topIssue = $this->resolveTopIssue($payload);
        $nextAction = $this->resolveNextAction($payload);

        $gateExitCode = $payload['data']['gates']['exit_code'] ?? null;
        $exitCode = is_int($gateExitCode)
            ? $gateExitCode
            : match ($verdict) {
                'PASS' => 0,
                'WARN' => 2,
                'FAIL' => 10,
                default => 12,
            };

        return [
            'verdict' => $verdict,
            'severity' => $severity,
            'top_issue' => $topIssue,
            'next_action' => $nextAction,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveTopIssue(array $payload): ?string
    {
        $firstFailedRunReason = $this->stringValue($payload, ['data', 'last_failed_run', 'failure_reason']);
        if ($firstFailedRunReason !== null) {
            return $firstFailedRunReason;
        }

        $summaryFailedRunReason = $this->stringValue($payload, ['data', 'summary', 'latest_failed_run', 'failure_reason']);
        if ($summaryFailedRunReason !== null) {
            return $summaryFailedRunReason;
        }

        $firstCheckNotes = $this->stringValue($payload, ['data', 'checks', 0, 'notes']);
        if ($firstCheckNotes !== null) {
            return $firstCheckNotes;
        }

        $summary = $this->stringValue($payload, ['summary']);

        return $summary !== '' ? $summary : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveNextAction(array $payload): ?string
    {
        $actionNow = $this->stringValue($payload, ['data', 'action_now']);
        if ($actionNow !== null) {
            return $actionNow;
        }

        $lastFailedNextAction = $this->stringValue($payload, ['data', 'last_failed_run', 'next_action']);
        if ($lastFailedNextAction !== null) {
            return $lastFailedNextAction;
        }

        $summaryNextAction = $this->stringValue($payload, ['data', 'summary', 'latest_failed_run', 'next_action']);
        if ($summaryNextAction !== null) {
            return $summaryNextAction;
        }

        $suggestions = $payload['suggestions'] ?? null;
        if (is_array($suggestions)) {
            foreach ($suggestions as $suggestion) {
                if (is_string($suggestion) && str($suggestion)->trim()->isNotEmpty()) {
                    return $suggestion;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<int|string>  $path
     */
    private function stringValue(array $payload, array $path): ?string
    {
        $cursor = $payload;

        foreach ($path as $segment) {
            if (is_array($cursor) && isset($cursor[$segment])) {
                $cursor = $cursor[$segment];

                continue;
            }

            return null;
        }

        if (! is_string($cursor)) {
            return null;
        }

        $value = str($cursor)->trim()->value();

        return $value === '' || $value === '-' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function stripNullValues(array $payload): array
    {
        $filtered = [];

        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (is_array($value)) {
                $filtered[$key] = $this->stripNullValues($value);

                continue;
            }

            $filtered[$key] = $value;
        }

        return $filtered;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function compactEnvelope(string $surface, array $payload): array
    {
        return $this->stripNullValues($this->envelope($surface, $payload));
    }
}
