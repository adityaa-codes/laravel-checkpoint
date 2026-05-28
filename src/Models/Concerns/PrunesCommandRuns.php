<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Models\Concerns;

use AdityaaCodes\LaravelCheckpoint\Actions\EvaluateRetentionPolicyAction;
use Illuminate\Database\Eloquent\Builder;

trait PrunesCommandRuns
{
    /** @return Builder<static> */
    public function prunable(): Builder
    {
        /** @var Builder<static> $query */
        $query = static::query();
        /** @var Builder<self> $retentionQuery */
        $retentionQuery = $query;

        resolve(EvaluateRetentionPolicyAction::class)->applyEligibleRetentionPredicate($retentionQuery, now());

        return $query;
    }

    public function pruneAll(): int
    {
        $deleted = 0;

        $this->prunable()
            ->select(['id', 'metadata'])
            ->orderBy('id')
            ->chunkById(250, function ($runs) use (&$deleted): void {
                $ids = [];

                foreach ($runs as $run) {
                    $this->outputStore()->cleanup($run);
                    $ids[] = $run->getKey();
                }

                if ($ids !== []) {
                    $deleted += (int) static::query()->whereKey($ids)->delete();
                }
            });

        return $deleted;
    }
}
