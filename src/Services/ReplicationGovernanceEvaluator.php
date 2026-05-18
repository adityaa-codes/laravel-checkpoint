<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\CheckpointArgumentException;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpoint;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationEndpointKind;
use AdityaaCodes\LaravelCheckpoint\ValueObjects\ReplicationRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository;

/** @internal */
final readonly class ReplicationGovernanceEvaluator
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(ReplicationRequest $request, bool $applyRequested): array
    {
        $allowlist = $this->normalizedAllowlist();
        $destinationCandidates = $this->destinationCandidates($request->destination);
        $destinationAllowlisted = $allowlist === []
            ? true
            : $this->destinationAllowlisted($destinationCandidates, $allowlist);
        $changeWindow = $this->changeWindowDecision($applyRequested);

        $blockedReasons = [];

        if ($applyRequested && ! $destinationAllowlisted) {
            $blockedReasons[] = 'destination_not_allowlisted';
        }

        if ($applyRequested && ! ($changeWindow['allowed'] ?? false)) {
            $blockedReasons[] = 'outside_change_window';
        }

        return [
            'policy_version' => 1,
            'evaluated_at' => now()->toIso8601String(),
            'mode' => $applyRequested ? 'apply' : 'dry_run',
            'allowed' => $blockedReasons === [],
            'blocked_reasons' => $blockedReasons,
            'destination_guard' => [
                'allowlist' => $allowlist,
                'candidates' => $destinationCandidates,
                'allowlisted' => $destinationAllowlisted,
            ],
            'change_window' => $changeWindow,
        ];
    }

    /**
     * @param  array<string, mixed>  $preflight
     */
    public function assertAllowed(array $preflight, bool $applyRequested): void
    {
        if (! $applyRequested) {
            return;
        }

        if (($preflight['allowed'] ?? false)) {
            return;
        }

        $reasons = $preflight['blocked_reasons'] ?? [];
        $reasonText = is_array($reasons) && $reasons !== []
            ? collect($reasons)->map(static fn (mixed $reason): string => (string) $reason)->implode(', ')
            : 'unknown_policy_failure';

        throw new CheckpointArgumentException(
            "Replication apply is blocked by governance preflight: {$reasonText}.",
        );
    }

    /**
     * @return list<string>
     */
    private function normalizedAllowlist(): array
    {
        $configured = $this->config->get('checkpoint.replication.allowlisted_destinations', []);

        if (! is_array($configured)) {
            return [];
        }

        return collect($configured)
            ->filter(fn (mixed $candidate): bool => is_string($candidate))
            ->map(fn (string $candidate): string => str($candidate)->trim()->lower()->value())
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $candidates
     * @param  list<string>  $allowlist
     */
    private function destinationAllowlisted(array $candidates, array $allowlist): bool
    {
        foreach ($candidates as $candidate) {
            if (collect($allowlist)->contains(str($candidate)->lower()->value())) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function destinationCandidates(ReplicationEndpoint $endpoint): array
    {
        $candidates = [];

        if ($endpoint->kind === ReplicationEndpointKind::ConfigProfile) {
            $identifier = str($endpoint->identifier ?? '')->trim()->lower()->value();

            if ($identifier !== '') {
                $candidates[] = "profile:{$identifier}";
                $candidates[] = $identifier;
            }
        }

        if ($endpoint->kind === ReplicationEndpointKind::Dsn) {
            $parts = parse_url($endpoint->rawInput);

            if (is_array($parts) && is_string($parts['host'] ?? null)) {
                $host = str($parts['host'])->trim()->lower()->value();

                if ($host !== '') {
                    $candidates[] = $host;
                }
            }
        }

        if ($endpoint->kind === ReplicationEndpointKind::KeyValue) {
            foreach (['host', 'hostname', 'server', 'destination', 'name'] as $key) {
                $value = $endpoint->attributes[$key] ?? null;

                if (! is_string($value)) {
                    continue;
                }

                $normalized = str($value)->trim()->lower()->value();

                if ($normalized !== '') {
                    $candidates[] = $normalized;
                }
            }
        }

        return collect($candidates)->unique()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function changeWindowDecision(bool $applyRequested): array
    {
        $timezone = $this->config->get('checkpoint.replication.change_window_timezone', 'UTC');
        $days = $this->normalizedChangeWindowDays();
        $start = $this->config->get('checkpoint.replication.change_window_start', '00:00');
        $end = $this->config->get('checkpoint.replication.change_window_end', '23:59');
        $enforced = $this->config->get('checkpoint.replication.enforce_change_window', false);
        $now = Carbon::now($timezone);
        $currentDay = str($now->format('D'))->lower()->value();
        $currentTime = $now->format('H:i');

        if (! $applyRequested) {
            return [
                'enforced' => false,
                'allowed' => true,
                'reason' => 'dry_run_mode',
                'timezone' => $timezone,
                'days' => $days,
                'start' => $start,
                'end' => $end,
                'current_day' => $currentDay,
                'current_time' => $currentTime,
            ];
        }

        if (! $enforced) {
            return [
                'enforced' => false,
                'allowed' => true,
                'reason' => 'policy_disabled',
                'timezone' => $timezone,
                'days' => $days,
                'start' => $start,
                'end' => $end,
                'current_day' => $currentDay,
                'current_time' => $currentTime,
            ];
        }

        $dayAllowed = collect($days)->contains($currentDay);
        $inWindow = $this->timeInWindow($currentTime, $start, $end);
        $allowed = $dayAllowed && $inWindow;

        return [
            'enforced' => true,
            'allowed' => $allowed,
            'reason' => $allowed ? 'within_window' : 'outside_window',
            'timezone' => $timezone,
            'days' => $days,
            'start' => $start,
            'end' => $end,
            'current_day' => $currentDay,
            'current_time' => $currentTime,
            'day_allowed' => $dayAllowed,
            'time_allowed' => $inWindow,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizedChangeWindowDays(): array
    {
        $configured = $this->config->get('checkpoint.replication.change_window_days', [
            'mon',
            'tue',
            'wed',
            'thu',
            'fri',
            'sat',
            'sun',
        ]);

        if (! is_array($configured)) {
            return [];
        }

        return collect($configured)
            ->filter(fn (mixed $day): bool => is_string($day))
            ->map(fn (string $day): string => str($day)->trim()->substr(0, 3)->lower()->value())
            ->filter(fn (string $normalized): bool => $normalized !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function timeInWindow(string $currentTime, string $start, string $end): bool
    {
        $current = $this->minutesFromTime($currentTime);
        $startMinutes = $this->minutesFromTime($start);
        $endMinutes = $this->minutesFromTime($end);

        if ($startMinutes === $endMinutes) {
            return true;
        }

        if ($startMinutes < $endMinutes) {
            return $current >= $startMinutes && $current < $endMinutes;
        }

        return $current >= $startMinutes || $current < $endMinutes;
    }

    private function minutesFromTime(string $value): int
    {
        if (! preg_match('/^(?<h>\d{2}):(?<m>\d{2})$/', $value, $matches)) {
            return 0;
        }

        return ((int) $matches['h'] * 60) + (int) $matches['m'];
    }
}
