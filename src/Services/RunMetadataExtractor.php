<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;

/** @internal */
final readonly class RunMetadataExtractor
{
    /**
     * @return array<string, mixed>|null
     */
    public function restoreAudit(CommandRun $run): ?array
    {
        $metadata = $run->metadata ?? [];
        $restoreAudit = $metadata['restore_audit'] ?? [];
        $summary = $run->restoreAuditSummary();

        if ($summary['confirmation_satisfied_via'] !== null) {
            $restoreAudit['confirmation_satisfied_via'] = $summary['confirmation_satisfied_via'];
        }

        if ($summary['verified_signal_run_id'] !== null) {
            $restoreAudit['verified_signal_run_id'] = $summary['verified_signal_run_id'];
        }

        $postVerificationSummary = $run->restorePostVerificationSummary();

        if ($postVerificationSummary['aggregate_result'] !== null) {
            $postVerification = $restoreAudit['post_restore_verification'] ?? [];
            $postVerification['aggregate_result'] = $postVerificationSummary['aggregate_result'];
            $restoreAudit['post_restore_verification'] = $postVerification;
        }

        return $restoreAudit !== [] ? $restoreAudit : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function postRestoreVerification(CommandRun $run): ?array
    {
        $metadata = $run->metadata ?? [];
        $restoreAudit = $metadata['restore_audit'] ?? [];
        $postVerification = $restoreAudit['post_restore_verification'] ?? [];
        $summary = $run->restorePostVerificationSummary();

        if ($summary['aggregate_result'] !== null) {
            $postVerification['aggregate_result'] = $summary['aggregate_result'];
        }

        return $postVerification !== [] ? $postVerification : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function replication(CommandRun $run): ?array
    {
        $metadata = $run->metadata ?? [];
        $replication = $metadata['replication'] ?? null;

        if ($replication === null) {
            return null;
        }

        return [
            'engine' => $replication['engine'] ?? null,
            'source' => $replication['source'] ?? null,
            'destination' => $replication['destination'] ?? null,
            'queue_only' => $replication['queue_only'] ?? null,
            'dry_run_requested' => $replication['dry_run_requested'] ?? null,
            'apply_requested' => $replication['apply_requested'] ?? null,
            'force_requested' => $replication['force_requested'] ?? null,
            'overwrite_destination' => $replication['overwrite_destination'] ?? null,
            'governance_preflight' => $replication['governance_preflight'] ?? null,
            'result' => $replication['result'] ?? null,
            'sanity' => $replication['sanity'] ?? null,
            'failure_analysis' => $replication['failure_analysis'] ?? null,
            'failure_context' => $replication['failure_context'] ?? null,
        ];
    }
}
