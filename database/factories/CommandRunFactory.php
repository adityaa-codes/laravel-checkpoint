<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Database\Factories;

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommandRun>
 */
class CommandRunFactory extends Factory
{
    protected $model = CommandRun::class;

    public function definition(): array
    {
        return [
            'operation' => fake()->randomElement([
                'logical_backup',
                'logical_restore_latest',
                'logical_restore_file',
                'pitr_restore',
                'backup_drill',
                'physical_backup',
                'physical_backup',
            ]),
            'argument_text' => fake()->optional()->word(),
            'backup_type' => null,
            'backup_label' => null,
            'stanza' => null,
            'driver_name' => null,
            'repository' => null,
            'verification_state' => null,
            'restore_target' => null,
            'restore_confirmation_satisfied_via' => null,
            'restore_verified_signal_run_id' => null,
            'artifact_path' => null,
            'backup_size_bytes' => null,
            'duration_seconds' => null,
            'throughput_bytes_per_second' => null,
            'verified_at' => null,
            'last_known_good_at' => null,
            'metadata' => null,
            'status' => CommandRunStatus::Pending,
            'command_line' => null,
            'command_output' => null,
            'exit_code' => null,
            'attempts' => 0,
            'started_at' => null,
            'heartbeat_at' => null,
            'finished_at' => null,
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (): array => [
            'status' => CommandRunStatus::Pending,
            'attempts' => 0,
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'command_output' => null,
            'duration_seconds' => null,
            'throughput_bytes_per_second' => null,
        ]);
    }

    public function running(): self
    {
        return $this->state(fn (): array => [
            'status' => CommandRunStatus::Running,
            'attempts' => 1,
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'heartbeat_at' => now()->subMinutes(fake()->numberBetween(0, 2)),
            'finished_at' => null,
            'command_line' => fake()->sentence(3),
            'exit_code' => null,
            'command_output' => null,
        ]);
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => [
            'status' => CommandRunStatus::Succeeded,
            'attempts' => 1,
            'started_at' => now()->subMinutes(10),
            'heartbeat_at' => now()->subMinutes(9),
            'finished_at' => now()->subMinutes(9),
            'command_line' => fake()->sentence(3),
            'command_output' => fake()->sentence(),
            'exit_code' => 0,
            'duration_seconds' => 60,
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => CommandRunStatus::Failed,
            'attempts' => fake()->numberBetween(1, 3),
            'started_at' => now()->subMinutes(15),
            'heartbeat_at' => now()->subMinutes(14),
            'finished_at' => now()->subMinutes(14),
            'command_line' => fake()->sentence(3),
            'command_output' => fake()->sentence(),
            'exit_code' => 1,
            'duration_seconds' => 60,
        ]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'status' => CommandRunStatus::Cancelled,
            'attempts' => 0,
            'started_at' => null,
            'heartbeat_at' => null,
            'finished_at' => now()->subMinutes(5),
            'command_line' => null,
            'command_output' => null,
            'exit_code' => null,
        ]);
    }
}
