<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Actions;

use AdityaaCodes\LaravelCheckpoint\Actions\Concerns\MakesHealthCheckRows;

final readonly class ComposeVerificationHealthChecksAction
{
    use MakesHealthCheckRows;

    /**
     * @param  array<string, mixed>  $verificationSummary
     * @return list<array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}>
     */
    public function execute(array $verificationSummary): array
    {
        return [
            $this->verificationHealthCheck($verificationSummary),
        ];
    }

    /**
     * @param  array<string, mixed>  $verificationSummary
     * @return array{code:string,check:string,status:string,severity:string,notes:string,data:array<string,mixed>}
     */
    private function verificationHealthCheck(array $verificationSummary): array
    {
        $total = (int) ($verificationSummary['total_runs'] ?? 0);
        $failed = (int) ($verificationSummary['failed_runs'] ?? 0);

        if ($total < 1) {
            return $this->checkRow(
                'verification.runs',
                'Verification: runs',
                'warn',
                'No persisted verification runs yet',
                [
                    'total_runs' => 0,
                    'verified_runs' => 0,
                    'failed_runs' => 0,
                    'health_status' => 'warn',
                    'reason' => 'missing',
                ],
            );
        }

        $status = $failed > 0 ? 'warn' : 'pass';

        return $this->checkRow(
            'verification.runs',
            'Verification: runs',
            $status,
            sprintf('%d total (%d verified, %d failed)', $total, (int) ($verificationSummary['verified_runs'] ?? 0), $failed),
            [
                'total_runs' => $total,
                'verified_runs' => (int) ($verificationSummary['verified_runs'] ?? 0),
                'failed_runs' => $failed,
                'success_rate_percent' => $verificationSummary['success_rate_percent'] ?? null,
                'health_status' => $verificationSummary['health_status'] ?? ($failed > 0 ? 'warn' : 'pass'),
                'latest' => is_array($verificationSummary['latest'] ?? null) ? $verificationSummary['latest'] : null,
                'reason' => $failed > 0 ? 'failed_runs_present' : 'healthy',
            ],
        );
    }
}
