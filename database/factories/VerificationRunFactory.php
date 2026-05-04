<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Database\Factories;

use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Models\VerificationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VerificationRun>
 */
class VerificationRunFactory extends Factory
{
    protected $model = VerificationRun::class;

    public function definition(): array
    {
        return [
            'command_run_id' => CommandRun::factory(),
            'verification_type' => fake()->randomElement(['physical_backup', 'physical_backup']),
            'status' => 'verified',
            'verified_at' => now(),
            'metadata' => [
                'driver' => 'pgbasebackup',
            ],
            'error_detail' => null,
        ];
    }

    public function verified(): self
    {
        return $this->state(fn (): array => [
            'status' => 'verified',
            'verified_at' => now(),
            'error_detail' => null,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => 'failed',
            'verified_at' => now(),
            'error_detail' => 'Verification command failed.',
        ]);
    }
}
