<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use Illuminate\Support\Str;

/** @internal */
final readonly class ReplicationFailureSuggestionMapper
{
    public function __construct(
        private ReplicationSecretRedactor $secretRedactor,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{
     *     category:string,
     *     signature:string,
     *     immediate_fix:string,
     *     deeper_diagnostics:list<string>,
     *     diagnostics:array<string, mixed>
     * }
     */
    public function map(string $stage, string $failureOutput, array $context = []): array
    {
        $normalized = str($failureOutput)->lower()->value();
        $category = $this->categoryFor($normalized, $stage);
        $playbook = $this->playbook($category);

        return [
            'category' => $category,
            'signature' => $playbook['signature'],
            'immediate_fix' => $playbook['immediate_fix'],
            'deeper_diagnostics' => $playbook['deeper_diagnostics'],
            'diagnostics' => $this->redactedDiagnostics($stage, $failureOutput, $context),
        ];
    }

    private function categoryFor(string $normalizedOutput, string $stage): string
    {
        if ($stage === 'apply_gate' || str($normalizedOutput)->contains('destination overwrite denied')) {
            return 'destination_overwrite_conflict';
        }

        if (Str::contains($normalizedOutput, [
            'password authentication failed',
            'access denied for user',
            'authentication failed',
            'invalid password',
            'using password: yes',
        ])) {
            return 'auth_credential_failure';
        }

        if (Str::contains($normalizedOutput, [
            'could not translate host name',
            'name or service not known',
            'temporary failure in name resolution',
            'connection refused',
            'could not connect to server',
            'no route to host',
            'connection timed out',
        ])) {
            return 'dns_network_connection_refused';
        }

        if (Str::contains($normalizedOutput, [
            'permission denied',
            'insufficient privilege',
            'must be superuser',
            'you need (at least one of) the',
            'access denied',
        ])) {
            return 'privilege_permission_denied';
        }

        if (Str::contains($normalizedOutput, [
            'command not found',
            'no such file or directory',
            'executable file not found',
            'not recognized as an internal or external command',
        ])) {
            return 'binary_missing';
        }

        if (Str::contains($normalizedOutput, [
            'invalid dsn',
            'invalid uri',
            'could not parse',
            'malformed',
            'failed to parse',
        ])) {
            return 'invalid_url_dsn_parse';
        }

        if (Str::contains($normalizedOutput, [
            'schema mismatch',
            'version mismatch',
            'unsupported server version',
            'unknown column',
            'relation',
            'does not exist',
        ])) {
            return 'schema_version_mismatch';
        }

        if (Str::contains($normalizedOutput, [
            'checksum mismatch',
            'hash mismatch',
            'sanity check failed',
            'verification mismatch',
            'snapshot mismatch',
        ])) {
            return 'checksum_sanity_verification_mismatch';
        }

        return 'unknown_replication_failure';
    }

    /**
     * @return array{signature:string,immediate_fix:string,deeper_diagnostics:list<string>}
     */
    private function playbook(string $category): array
    {
        return match ($category) {
            'auth_credential_failure' => [
                'signature' => 'Authentication or credential validation failed.',
                'immediate_fix' => 'Rotate or correct source/destination credentials and retry dry-run first.',
                'deeper_diagnostics' => [
                    'Confirm the replication user can log in from the worker host.',
                    'Verify credential secrets in config/profile wiring match current database users.',
                ],
            ],
            'dns_network_connection_refused' => [
                'signature' => 'DNS resolution or network connectivity to source/destination failed.',
                'immediate_fix' => 'Validate host, port, and network path; then rerun dry-run.',
                'deeper_diagnostics' => [
                    'Check DNS resolution, firewall rules, VPN routes, and security group policies.',
                    'Validate database listener bind address and port reachability from queue workers.',
                ],
            ],
            'privilege_permission_denied' => [
                'signature' => 'Database privileges are insufficient for replication workflow.',
                'immediate_fix' => 'Grant required dump/restore privileges for replication role and retry.',
                'deeper_diagnostics' => [
                    'Compare required grants for export/import with current role grants.',
                    'Validate file-system permissions for staging artifact directory.',
                ],
            ],
            'binary_missing' => [
                'signature' => 'Required client binary is missing from runtime environment.',
                'immediate_fix' => 'Install/configure the required database client binaries on workers.',
                'deeper_diagnostics' => [
                    'Validate configured binary paths and worker PATH environment.',
                    'Run doctor/status surfaces to verify binary discovery across environments.',
                ],
            ],
            'invalid_url_dsn_parse' => [
                'signature' => 'Replication endpoint URL/DSN could not be parsed.',
                'immediate_fix' => 'Correct endpoint format (profile, DSN, or key=value) and retry.',
                'deeper_diagnostics' => [
                    'Check engine prefix and delimiter formatting for DSN inputs.',
                    'Validate encoded characters in usernames/passwords and host literals.',
                ],
            ],
            'schema_version_mismatch' => [
                'signature' => 'Source and destination schema or engine versions are incompatible.',
                'immediate_fix' => 'Align schema migrations/engine versions before apply.',
                'deeper_diagnostics' => [
                    'Run schema diff and pending migration checks on both endpoints.',
                    'Compare source/destination server versions and extension compatibility.',
                ],
            ],
            'destination_overwrite_conflict' => [
                'signature' => 'Destination overwrite is blocked by replication safety policy.',
                'immediate_fix' => 'Enable overwrite_destination/force only after validating destination state.',
                'deeper_diagnostics' => [
                    'Run dry-run mode to confirm export integrity before destructive apply.',
                    'Review critical table guardrails and operational approval requirements.',
                ],
            ],
            'checksum_sanity_verification_mismatch' => [
                'signature' => 'Replication sanity/checksum verification failed.',
                'immediate_fix' => 'Abort apply and investigate source snapshot integrity before rerun.',
                'deeper_diagnostics' => [
                    'Recompute artifact hashes and compare with recorded source snapshot metadata.',
                    'Inspect potential data drift from concurrent writes or partial artifact generation.',
                ],
            ],
            default => [
                'signature' => 'Replication failed with an unclassified signature.',
                'immediate_fix' => 'Re-run in dry-run mode and inspect sanitized failure excerpt.',
                'deeper_diagnostics' => [
                    'Capture full driver output and correlate with database/server logs.',
                    'Escalate with sanitized diagnostics payload and run metadata.',
                ],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redactedDiagnostics(string $stage, string $failureOutput, array $context): array
    {
        $diagnostics = [
            'stage' => $stage,
            'excerpt' => $this->redactedExcerpt($failureOutput),
        ];

        foreach ($context as $key => $value) {
            if ($key === '') {
                continue;
            }

            if (is_string($value)) {
                $diagnostics[$key] = $this->secretRedactor->redact($value);

                continue;
            }

            $diagnostics[$key] = $value;
        }

        return $diagnostics;
    }

    private function redactedExcerpt(string $output): string
    {
        $lines = collect(preg_split('/\R/', $output) ?: [])
            ->map(fn (string $line): string => str($line)->trim()->value())
            ->filter(fn (string $line): bool => $line !== '')
            ->values()
            ->all();

        $excerpt = collect($lines)
            ->slice(max(0, count($lines) - 3))
            ->implode(' | ');

        return $this->secretRedactor->redact($excerpt);
    }
}
