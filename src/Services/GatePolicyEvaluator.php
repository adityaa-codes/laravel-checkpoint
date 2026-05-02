<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use Throwable;

/** @internal */
final readonly class GatePolicyEvaluator
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>,severity?:string}>  $checks
     * @param  array<string,mixed>  $summary
     * @return array{
     *   profile:string,
     *   profile_source:'override'|'environment'|'default',
     *   verdict:'pass'|'warn'|'fail',
     *   failed_gate:'none'|'safety'|'evidence'|'policy',
     *   safety_failed:bool,
     *   evidence_failed:bool,
     *   warning_count:int,
     *   failed_count:int,
     *   exit_code:int
     * }
     */
    public function evaluate(array $checks, array $summary = [], ?string $profileOverride = null): array
    {
        try {
            ['profile' => $profile, 'source' => $profileSource] = $this->resolvedProfile($profileOverride);

            if (! $this->hasProfile($profile)) {
                throw new \RuntimeException(sprintf('Unknown gate profile [%s].', $profile));
            }

            $policy = $this->profilePolicy($profile);
            $codeMap = $this->codeMap();
            $normalizedChecks = $this->normalizeChecks($checks);

            $failedCount = count(array_filter($normalizedChecks, static fn (array $check): bool => $check['status'] === 'fail'));
            $warningCount = count(array_filter($normalizedChecks, static fn (array $check): bool => $check['status'] === 'warn'));

            $safetyFailed = $this->safetyFailed($normalizedChecks, $policy);
            $evidenceFailed = $this->evidenceFailed($normalizedChecks, $summary, $policy);
            $warnExit = (bool) ($policy['exit_on_warn'] ?? false) && ! $safetyFailed && ! $evidenceFailed && $warningCount > 0;

            if ($safetyFailed) {
                return [
                    'profile' => $profile,
                    'profile_source' => $profileSource,
                    'verdict' => 'fail',
                    'failed_gate' => 'safety',
                    'safety_failed' => true,
                    'evidence_failed' => $evidenceFailed,
                    'warning_count' => $warningCount,
                    'failed_count' => $failedCount,
                    'exit_code' => $codeMap['safety_fail'],
                ];
            }

            if ($evidenceFailed) {
                return [
                    'profile' => $profile,
                    'profile_source' => $profileSource,
                    'verdict' => 'fail',
                    'failed_gate' => 'evidence',
                    'safety_failed' => false,
                    'evidence_failed' => true,
                    'warning_count' => $warningCount,
                    'failed_count' => $failedCount,
                    'exit_code' => $codeMap['evidence_fail'],
                ];
            }

            if ($warnExit) {
                return [
                    'profile' => $profile,
                    'profile_source' => $profileSource,
                    'verdict' => 'warn',
                    'failed_gate' => 'none',
                    'safety_failed' => false,
                    'evidence_failed' => false,
                    'warning_count' => $warningCount,
                    'failed_count' => $failedCount,
                    'exit_code' => $codeMap['warn'],
                ];
            }

            return [
                'profile' => $profile,
                'profile_source' => $profileSource,
                'verdict' => 'pass',
                'failed_gate' => 'none',
                'safety_failed' => false,
                'evidence_failed' => false,
                'warning_count' => $warningCount,
                'failed_count' => $failedCount,
                'exit_code' => $codeMap['pass'],
            ];
        } catch (Throwable) {
            $codeMap = $this->codeMap();

            return [
                'profile' => 'unknown',
                'profile_source' => 'default',
                'verdict' => 'fail',
                'failed_gate' => 'policy',
                'safety_failed' => false,
                'evidence_failed' => false,
                'warning_count' => 0,
                'failed_count' => 0,
                'exit_code' => $codeMap['policy_error'],
            ];
        }
    }

    /**
     * @return array{profile:string,source:'override'|'environment'|'default'}
     */
    private function resolvedProfile(?string $profileOverride = null): array
    {
        $override = $this->normalizeOverrideProfile($profileOverride);

        if ($override !== null) {
            return ['profile' => $override, 'source' => 'override'];
        }

        $environment = app()->environment();
        $map = $this->config->get('checkpoint.gates.environment_profile_map', []);

        if (! is_array($map)) {
            return ['profile' => $this->defaultProfile(), 'source' => 'default'];
        }

        $mappedProfile = $map[$environment] ?? null;

        if (is_string($mappedProfile) && trim($mappedProfile) !== '') {
            return ['profile' => trim($mappedProfile), 'source' => 'environment'];
        }

        return ['profile' => $this->defaultProfile(), 'source' => 'default'];
    }

    private function defaultProfile(): string
    {
        $profile = $this->config->get('checkpoint.gates.default_profile', 'production');

        if (! is_string($profile)) {
            return 'production';
        }

        $profile = trim($profile);

        return $profile !== '' ? $profile : 'production';
    }

    private function normalizeOverrideProfile(?string $profileOverride = null): ?string
    {
        $candidate = $profileOverride;

        if (! is_string($candidate) || trim($candidate) === '') {
            $configured = $this->config->get('checkpoint.gates.override_profile');
            $candidate = is_string($configured) ? $configured : null;
        }

        if (! is_string($candidate)) {
            return null;
        }

        $candidate = trim($candidate);

        return $candidate !== '' ? $candidate : null;
    }

    private function hasProfile(string $profile): bool
    {
        $profiles = $this->config->get('checkpoint.gates.profiles', []);

        return is_array($profiles) && array_key_exists($profile, $profiles);
    }

    /**
     * @return array{exit_on_warn:bool,safety:array<string,mixed>,evidence:array<string,mixed>}
     */
    private function profilePolicy(string $profile): array
    {
        $profiles = $this->config->get('checkpoint.gates.profiles', []);

        if (! is_array($profiles) || ! isset($profiles[$profile]) || ! is_array($profiles[$profile])) {
            return [
                'exit_on_warn' => false,
                'safety' => [
                    'fail_on_statuses' => ['fail'],
                    'fail_on_warning_codes' => [],
                ],
                'evidence' => [
                    'enabled' => false,
                    'fail_on_codes' => [],
                    'max_restore_verification_age_days' => 0,
                ],
            ];
        }

        return [
            'exit_on_warn' => (bool) ($profiles[$profile]['exit_on_warn'] ?? false),
            'safety' => is_array($profiles[$profile]['safety'] ?? null) ? $profiles[$profile]['safety'] : [],
            'evidence' => is_array($profiles[$profile]['evidence'] ?? null) ? $profiles[$profile]['evidence'] : [],
        ];
    }

    /**
     * @return array{pass:int,warn:int,safety_fail:int,evidence_fail:int,policy_error:int}
     */
    private function codeMap(): array
    {
        $configured = $this->config->get('checkpoint.gates.code_map', []);

        if (! is_array($configured)) {
            $configured = [];
        }

        return [
            'pass' => max(0, (int) ($configured['pass'] ?? 0)),
            'warn' => max(0, (int) ($configured['warn'] ?? 2)),
            'safety_fail' => max(0, (int) ($configured['safety_fail'] ?? 10)),
            'evidence_fail' => max(0, (int) ($configured['evidence_fail'] ?? 11)),
            'policy_error' => max(0, (int) ($configured['policy_error'] ?? 12)),
        ];
    }

    /**
     * @param  list<array{code:string,check:string,status:string,notes:string,data:array<string,mixed>,severity?:string}>  $checks
     * @return list<array{code:string,status:string}>
     */
    private function normalizeChecks(array $checks): array
    {
        return array_map(static function (array $check): array {
            return [
                'code' => (string) ($check['code'] ?? ''),
                'status' => (string) ($check['status'] ?? 'warn'),
            ];
        }, $checks);
    }

    /**
     * @param  list<array{code:string,status:string}>  $checks
     * @param  array{exit_on_warn:bool,safety:array<string,mixed>,evidence:array<string,mixed>}  $policy
     */
    private function safetyFailed(array $checks, array $policy): bool
    {
        $safety = $policy['safety'] ?? [];
        $failOnStatuses = is_array($safety['fail_on_statuses'] ?? null) ? $safety['fail_on_statuses'] : ['fail'];
        $failOnStatuses = array_values(array_filter(array_map(static fn (mixed $status): string => is_string($status) ? $status : '', $failOnStatuses)));
        $failOnWarningCodes = is_array($safety['fail_on_warning_codes'] ?? null) ? $safety['fail_on_warning_codes'] : [];
        $failOnWarningCodes = array_values(array_filter(array_map(static fn (mixed $code): string => is_string($code) ? $code : '', $failOnWarningCodes)));

        foreach ($checks as $check) {
            if (in_array($check['status'], $failOnStatuses, true)) {
                return true;
            }

            if ($check['status'] === 'warn' && in_array($check['code'], $failOnWarningCodes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{code:string,status:string}>  $checks
     * @param  array<string,mixed>  $summary
     * @param  array{exit_on_warn:bool,safety:array<string,mixed>,evidence:array<string,mixed>}  $policy
     */
    private function evidenceFailed(array $checks, array $summary, array $policy): bool
    {
        $evidence = $policy['evidence'] ?? [];
        $enabled = (bool) ($evidence['enabled'] ?? false);

        if (! $enabled) {
            return false;
        }

        $failOnCodes = is_array($evidence['fail_on_codes'] ?? null) ? $evidence['fail_on_codes'] : [];
        $failOnCodes = array_values(array_filter(array_map(static fn (mixed $code): string => is_string($code) ? $code : '', $failOnCodes)));

        foreach ($checks as $check) {
            if (in_array($check['code'], $failOnCodes, true) && in_array($check['status'], ['warn', 'fail'], true)) {
                return true;
            }
        }

        $maxAgeDays = (int) ($evidence['max_restore_verification_age_days'] ?? 0);

        if ($maxAgeDays <= 0) {
            return false;
        }

        $timestamp = $summary['latest_restore_run']['timestamp'] ?? null;

        if (! is_string($timestamp) || trim($timestamp) === '') {
            return true;
        }

        try {
            $lastRestore = Carbon::parse($timestamp);
        } catch (Throwable) {
            return true;
        }

        return $lastRestore->lt(now()->subDays($maxAgeDays));
    }
}
