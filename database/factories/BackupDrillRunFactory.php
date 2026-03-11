<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Database\Factories;

use AdityaaCodes\LaravelCheckpoint\Models\BackupDrillRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BackupDrillRun>
 */
class BackupDrillRunFactory extends Factory
{
    protected $model = BackupDrillRun::class;

    public function definition(): array
    {
        return [
            'run_uuid' => fake()->uuid(),
            'marker_uuid' => fake()->uuid(),
            'marker_email' => fake()->safeEmail(),
            'marker_count' => fake()->numberBetween(1, 50),
            'marker_result' => 'pass',
            'rto_target_seconds' => 900,
            'rto_actual_seconds' => fake()->numberBetween(60, 600),
            'rto_result' => 'pass',
            'rpo_target_seconds' => 300,
            'rpo_actual_seconds' => fake()->numberBetween(10, 180),
            'rpo_result' => 'pass',
            'overall_result' => 'pass',
            'executed_by' => fake()->name(),
            'executed_at' => now()->subHour(),
        ];
    }

    public function passing(): self
    {
        return $this->state(fn (): array => [
            'marker_result' => 'pass',
            'rto_target_seconds' => 900,
            'rto_actual_seconds' => fake()->numberBetween(60, 600),
            'rto_result' => 'pass',
            'rpo_target_seconds' => 300,
            'rpo_actual_seconds' => fake()->numberBetween(10, 180),
            'rpo_result' => 'pass',
            'overall_result' => 'pass',
        ]);
    }

    public function failing(): self
    {
        return $this->state(fn (): array => [
            'marker_result' => fake()->randomElement(['fail', 'pass']),
            'rto_target_seconds' => 900,
            'rto_actual_seconds' => fake()->numberBetween(901, 1800),
            'rto_result' => 'fail',
            'rpo_target_seconds' => 300,
            'rpo_actual_seconds' => fake()->numberBetween(301, 900),
            'rpo_result' => 'fail',
            'overall_result' => 'fail',
        ]);
    }
}
