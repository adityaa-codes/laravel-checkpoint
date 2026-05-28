<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models\Concerns;

trait HasRestoreAudit
{
    /**
     * @return array{confirmation_satisfied_via:?string,verified_signal_run_id:?int}
     */
    public function restoreAuditSummary(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
        $confirmation = $this->restore_confirmation_satisfied_via;
        $verifiedSignalRunId = $this->restore_verified_signal_run_id;

        if ($confirmation === null && is_string($restoreAudit['confirmation_satisfied_via'] ?? null)) {
            $confirmation = $restoreAudit['confirmation_satisfied_via'];
        }

        if ($verifiedSignalRunId === null && is_numeric($restoreAudit['verified_signal_run_id'] ?? null)) {
            $verifiedSignalRunId = (int) $restoreAudit['verified_signal_run_id'];
        }

        return [
            'confirmation_satisfied_via' => $confirmation,
            'verified_signal_run_id' => $verifiedSignalRunId,
        ];
    }

    /**
     * @return array{aggregate_result:?string}
     */
    public function restorePostVerificationSummary(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : [];
        $postVerification = is_array($restoreAudit['post_restore_verification'] ?? null)
            ? $restoreAudit['post_restore_verification']
            : [];
        $aggregateResult = $this->restore_post_verification_result;

        if (
            $aggregateResult === null
            && is_string($postVerification['aggregate_result'] ?? null)
            && $postVerification['aggregate_result'] !== ''
        ) {
            $aggregateResult = $postVerification['aggregate_result'];
        }

        return [
            'aggregate_result' => $aggregateResult,
        ];
    }
}
