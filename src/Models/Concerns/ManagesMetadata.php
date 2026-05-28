<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

trait ManagesMetadata
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function recordMetadata(array $attributes): self
    {
        $originalVerificationState = $this->verification_state;
        $originalVerifiedAt = $this->verified_at;

        $metadataJson = $attributes['metadata'] ?? $attributes;
        $columnNames = ['backup_type', 'artifact_path', 'verification_state', 'verified_at', 'backup_size_bytes', 'last_known_good_at'];
        $flatColumns = [];

        foreach ($columnNames as $column) {
            if (array_key_exists($column, $attributes)) {
                $flatColumns[$column] = $attributes[$column];
            }
        }

        $this->forceFill([
            'metadata' => $metadataJson,
            ...$flatColumns,
            ...$this->denormalizedMetadataColumns($attributes),
        ])->save();

        $this->persistVerificationOutcome($attributes, $originalVerificationState, $originalVerifiedAt);

        return $this;
    }

    public function resolvedDriverName(?string $fallback = null): ?string
    {
        if (is_string($this->driver_name) && $this->driver_name !== '') {
            return $this->driver_name;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $metadataDriver = $metadata['driver'] ?? null;

        if (is_string($metadataDriver) && $metadataDriver !== '') {
            return $metadataDriver;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistVerificationOutcome(array $attributes, ?string $originalVerificationState, ?Carbon $originalVerifiedAt): void
    {
        $verificationState = $this->verification_state;
        $verifiedAt = $this->verified_at;

        if (! collect(['verified', 'failed'])->containsStrict($verificationState) || ! $verifiedAt instanceof Carbon) {
            return;
        }

        $stateChanged = $originalVerificationState !== $verificationState;
        $timestampChanged = ! $originalVerifiedAt instanceof Carbon || ! $originalVerifiedAt->equalTo($verifiedAt);

        if (! $stateChanged && ! $timestampChanged) {
            return;
        }

        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : (is_array($this->metadata) ? $this->metadata : null);
        $errorDetail = $verificationState === 'failed' ? $this->resolvedVerificationErrorDetail($attributes) : null;

        $this->verificationRuns()->create([
            'verification_type' => $this->operation,
            'status' => $verificationState,
            'verified_at' => $verifiedAt,
            'metadata' => $metadata,
            'error_detail' => $errorDetail,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolvedVerificationErrorDetail(array $attributes): ?string
    {
        $errorDetail = $attributes['error_detail'] ?? null;

        if (is_string($errorDetail) && $errorDetail !== '') {
            return $errorDetail;
        }

        if (is_string($this->command_output) && $this->command_output !== '') {
            return Str::substr($this->command_output, 0, 4000);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function denormalizedMetadataColumns(array $attributes): array
    {
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : null;

        if ($metadata === null) {
            return [];
        }

        $columns = [
            'driver_name' => null,
            'restore_confirmation_satisfied_via' => null,
            'restore_verified_signal_run_id' => null,
            'restore_post_verification_result' => null,
        ];

        if (is_string($metadata['driver'] ?? null) && $metadata['driver'] !== '') {
            $columns['driver_name'] = $metadata['driver'];
        }

        $restoreAudit = is_array($metadata['restore_audit'] ?? null) ? $metadata['restore_audit'] : null;

        if (is_array($restoreAudit)) {
            if (is_string($restoreAudit['confirmation_satisfied_via'] ?? null) && $restoreAudit['confirmation_satisfied_via'] !== '') {
                $columns['restore_confirmation_satisfied_via'] = $restoreAudit['confirmation_satisfied_via'];
            }

            if (is_numeric($restoreAudit['verified_signal_run_id'] ?? null)) {
                $columns['restore_verified_signal_run_id'] = (int) $restoreAudit['verified_signal_run_id'];
            }

            $postVerification = is_array($restoreAudit['post_restore_verification'] ?? null)
                ? $restoreAudit['post_restore_verification']
                : null;

            if (
                is_array($postVerification)
                && is_string($postVerification['aggregate_result'] ?? null)
                && $postVerification['aggregate_result'] !== ''
            ) {
                $columns['restore_post_verification_result'] = $postVerification['aggregate_result'];
            }
        }

        return $columns;
    }
}
