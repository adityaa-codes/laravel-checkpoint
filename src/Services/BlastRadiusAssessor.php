<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Services;

use AdityaaCodes\LaravelCheckpoint\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

/** @internal */
final readonly class BlastRadiusAssessor
{
    public function __construct(
        private Repository $config,
    ) {}

    /**
     * @return array{
     *   enabled:bool,
     *   score:int,
     *   status:string,
     *   warn_score:int,
     *   block_score:int,
     *   factors:list<array{name:string,weight:int,contributes:bool,note:string}>
     * }
     */
    public function assess(
        string $operation,
        string $environment,
        string $database,
        string $restoreTarget,
        bool $verifiedBackupRequired,
        ?int $verifiedSignalRunId,
    ): array {
        $enabled = $this->config->get('checkpoint.restore.blast_radius.enabled', true);
        $warnScore = max(0, min(100, $this->config->get('checkpoint.restore.blast_radius.warn_score', 50)));
        $blockScore = max(0, min(100, $this->config->get('checkpoint.restore.blast_radius.block_score', 80)));
        $weights = $this->weights();

        if (! $enabled) {
            return [
                'enabled' => false,
                'score' => 0,
                'status' => 'disabled',
                'warn_score' => $warnScore,
                'block_score' => $blockScore,
                'factors' => [],
            ];
        }

        $envLower = str($environment)->lower()->value();
        $dbLower = str($database)->lower()->value();

        $factors = [
            [
                'name' => 'environment',
                'weight' => $weights['environment'],
                'contributes' => str($envLower)->startsWith('prod') || $envLower === 'live',
                'note' => "restore running in {$environment} environment",
            ],
            [
                'name' => 'database',
                'weight' => $weights['database'],
                'contributes' => $database !== '' && ! collect(['checkpoint_shadow', 'checkpoint_restore_shadow'])->contains($dbLower),
                'note' => $database !== '' ? "database target {$database}" : 'database target unknown',
            ],
            [
                'name' => 'target',
                'weight' => $weights['target'],
                'contributes' => $operation === 'logical_restore_latest',
                'note' => $restoreTarget !== '' ? "restore target {$restoreTarget}" : 'restore target unresolved',
            ],
            [
                'name' => 'verification',
                'weight' => $weights['verification'],
                'contributes' => $verifiedBackupRequired && ! is_int($verifiedSignalRunId),
                'note' => $verifiedBackupRequired
                    ? (is_int($verifiedSignalRunId)
                        ? "verified signal linked to run {$verifiedSignalRunId}"
                        : 'verified signal required but missing')
                    : 'verified signal requirement disabled',
            ],
        ];

        $score = 0;

        foreach ($factors as $factor) {
            if ($factor['contributes']) {
                $score += $factor['weight'];
            }
        }

        $score = max(0, min(100, $score));
        $status = $score >= $blockScore ? 'block' : ($score >= $warnScore ? 'warn' : 'pass');

        return [
            'enabled' => true,
            'score' => $score,
            'status' => $status,
            'warn_score' => $warnScore,
            'block_score' => $blockScore,
            'factors' => $factors,
        ];
    }

    /**
     * @param  array{
     *   enabled:bool,
     *   score:int,
     *   status:string,
     *   warn_score:int,
     *   block_score:int,
     *   factors:list<array{name:string,weight:int,contributes:bool,note:string}>
     * }  $blastRadius
     */
    public function assertPolicy(array $blastRadius): void
    {
        if ($blastRadius['enabled'] !== true || $blastRadius['status'] !== 'block') {
            return;
        }

        throw new ConfigurationException(
            "Restore blast radius score [{$blastRadius['score']}] exceeds block threshold [{$blastRadius['block_score']}].",
        );
    }

    /**
     * @return array{environment:int,database:int,target:int,verification:int}
     */
    private function weights(): array
    {
        $configured = $this->config->get('checkpoint.restore.blast_radius.weights', []);

        return [
            'environment' => max(0, min(100, $configured['environment'] ?? 30)),
            'database' => max(0, min(100, $configured['database'] ?? 25)),
            'target' => max(0, min(100, $configured['target'] ?? 20)),
            'verification' => max(0, min(100, $configured['verification'] ?? 25)),
        ];
    }
}
