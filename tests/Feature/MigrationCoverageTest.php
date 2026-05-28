<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('applies the command run migrations on a fresh install', function (): void {
    Schema::dropIfExists('db_ops_restore_decision_events');
    Schema::dropIfExists('db_ops_restore_decision_events');
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_verification_runs');
    Schema::dropIfExists('db_ops_command_runs');

    freshCommandRunMigration()->up();
    metadataCommandRunMigration()->up();
    orphanClaimMigration()->up();
    heartbeatMigration()->up();
    operatorSummaryColumnsMigration()->up();
    restoreDecisionEventsMigration()->up();
    backupDrillRunMigration()->up();
    verificationRunMigration()->up();
    reportingIndexesMigration()->up();

    expect(Schema::hasTable('db_ops_command_runs'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'backup_type'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'orphan_recovery_claimed_at'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'heartbeat_at'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'driver_name'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_confirmation_satisfied_via'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_verified_signal_run_id'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_post_verification_result'))->toBeTrue()
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_orphan_recovery_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_running_heartbeat_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_verified_at_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_created_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_updated_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_finished_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_activity_lookup_index')
        ->and(Schema::hasTable('db_ops_restore_decision_events'))->toBeTrue()
        ->and(restoreDecisionEventIndexNames())->toContain('db_ops_restore_decision_events_run_created_at_index')
        ->and(restoreDecisionEventIndexNames())->toContain('db_ops_restore_decision_events_decision_created_at_index')
        ->and(backupDrillRunIndexNames())->toContain('db_ops_backup_drill_runs_executed_at_result_index')
        ->and(backupDrillRunIndexNames())->toContain('db_ops_backup_drill_runs_result_executed_at_index')
        ->and(Schema::hasTable('db_ops_verification_runs'))->toBeTrue()
        ->and(verificationRunIndexNames())->toContain('db_ops_verification_runs_command_run_verified_at_index')
        ->and(verificationRunIndexNames())->toContain('db_ops_verification_runs_status_verified_at_index');
});

it('creates all checkpoint tables from the squashed migration', function (): void {
    Schema::dropIfExists('db_ops_restore_decision_events');
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_verification_runs');
    Schema::dropIfExists('db_ops_command_runs');

    squashedTablesMigration()->up();

    expect(Schema::hasTable('db_ops_command_runs'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'backup_type'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'orphan_recovery_claimed_at'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'heartbeat_at'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'driver_name'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_confirmation_satisfied_via'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_verified_signal_run_id'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_post_verification_result'))->toBeTrue()
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_orphan_recovery_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_running_heartbeat_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_verified_at_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_created_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_updated_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_finished_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_activity_lookup_index')
        ->and(Schema::hasTable('db_ops_restore_decision_events'))->toBeTrue()
        ->and(restoreDecisionEventIndexNames())->toContain('db_ops_restore_decision_events_run_created_at_index')
        ->and(restoreDecisionEventIndexNames())->toContain('db_ops_restore_decision_events_decision_created_at_index')
        ->and(Schema::hasTable('db_ops_backup_drill_runs'))->toBeTrue()
        ->and(backupDrillRunIndexNames())->toContain('db_ops_backup_drill_runs_executed_at_result_index')
        ->and(backupDrillRunIndexNames())->toContain('db_ops_backup_drill_runs_result_executed_at_index')
        ->and(Schema::hasTable('db_ops_verification_runs'))->toBeTrue()
        ->and(verificationRunIndexNames())->toContain('db_ops_verification_runs_command_run_verified_at_index')
        ->and(verificationRunIndexNames())->toContain('db_ops_verification_runs_status_verified_at_index');
});

it('is idempotent when squashed migration runs twice', function (): void {
    Schema::dropIfExists('db_ops_restore_decision_events');
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_verification_runs');
    Schema::dropIfExists('db_ops_command_runs');

    squashedTablesMigration()->up();
    squashedTablesMigration()->up();

    expect(Schema::hasTable('db_ops_command_runs'))->toBeTrue()
        ->and(Schema::hasTable('db_ops_restore_decision_events'))->toBeTrue()
        ->and(Schema::hasTable('db_ops_backup_drill_runs'))->toBeTrue()
        ->and(Schema::hasTable('db_ops_verification_runs'))->toBeTrue();
});

it('supports rolling back the squashed migration', function (): void {
    Schema::dropIfExists('db_ops_restore_decision_events');
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_verification_runs');
    Schema::dropIfExists('db_ops_command_runs');

    squashedTablesMigration()->up();

    expect(Schema::hasTable('db_ops_command_runs'))->toBeTrue();

    squashedTablesMigration()->down();

    expect(Schema::hasTable('db_ops_command_runs'))->toBeFalse()
        ->and(Schema::hasTable('db_ops_restore_decision_events'))->toBeFalse()
        ->and(Schema::hasTable('db_ops_backup_drill_runs'))->toBeFalse()
        ->and(Schema::hasTable('db_ops_verification_runs'))->toBeFalse();
});

it('adds operational summary columns and indexes on upgrade installs', function (): void {
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_verification_runs');
    Schema::dropIfExists('db_ops_command_runs');

    Schema::create('db_ops_command_runs', function (Blueprint $table): void {
        $table->id();
        $table->nullableMorphs('requested_by');
        $table->string('operation');
        $table->text('argument_text')->nullable();
        $table->string('backup_type')->nullable();
        $table->string('backup_label')->nullable();
        $table->string('stanza')->nullable();
        $table->unsignedInteger('repository')->nullable();
        $table->string('verification_state')->nullable();
        $table->text('restore_target')->nullable();
        $table->text('artifact_path')->nullable();
        $table->unsignedBigInteger('backup_size_bytes')->nullable();
        $table->unsignedInteger('duration_seconds')->nullable();
        $table->unsignedBigInteger('throughput_bytes_per_second')->nullable();
        $table->timestamp('verified_at')->nullable();
        $table->timestamp('last_known_good_at')->nullable();
        $table->json('metadata')->nullable();
        $table->string('status');
        $table->text('command_line')->nullable();
        $table->longText('command_output')->nullable();
        $table->integer('exit_code')->nullable();
        $table->unsignedInteger('attempts')->default(0);
        $table->timestamp('started_at')->nullable();
        $table->timestamp('finished_at')->nullable();
        $table->timestamps();

        $table->index(['operation', 'status']);
        $table->index('last_known_good_at');
        $table->index('created_at');
    });

    expect(Schema::hasColumn('db_ops_command_runs', 'orphan_recovery_claimed_at'))->toBeFalse();
    expect(Schema::hasColumn('db_ops_command_runs', 'driver_name'))->toBeFalse();

    orphanClaimMigration()->up();
    orphanClaimMigration()->up();
    heartbeatMigration()->up();
    heartbeatMigration()->up();
    operatorSummaryColumnsMigration()->up();
    operatorSummaryColumnsMigration()->up();
    restoreDecisionEventsMigration()->up();
    verificationRunMigration()->up();
    reportingIndexesMigration()->up();
    reportingIndexesMigration()->up();

    expect(Schema::hasColumn('db_ops_command_runs', 'orphan_recovery_claimed_at'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'heartbeat_at'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'driver_name'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_confirmation_satisfied_via'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_verified_signal_run_id'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'restore_post_verification_result'))->toBeTrue()
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_orphan_recovery_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_running_heartbeat_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_verified_at_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_created_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_updated_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_finished_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_activity_lookup_index')
        ->and(Schema::hasTable('db_ops_restore_decision_events'))->toBeTrue()
        ->and(restoreDecisionEventIndexNames())->toContain('db_ops_restore_decision_events_run_created_at_index')
        ->and(restoreDecisionEventIndexNames())->toContain('db_ops_restore_decision_events_decision_created_at_index')
        ->and(Schema::hasTable('db_ops_verification_runs'))->toBeTrue()
        ->and(verificationRunIndexNames())->toContain('db_ops_verification_runs_command_run_verified_at_index')
        ->and(verificationRunIndexNames())->toContain('db_ops_verification_runs_status_verified_at_index');
});

function freshCommandRunMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_checkpoint_command_runs_table.php.stub';

    return $migration;
}

function metadataCommandRunMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/add_checkpoint_metadata_to_command_runs_table.php.stub';

    return $migration;
}

function orphanClaimMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/add_orphan_recovery_claim_to_command_runs_table.php.stub';

    return $migration;
}

function heartbeatMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/add_heartbeat_to_command_runs_table.php.stub';

    return $migration;
}

function reportingIndexesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/add_reporting_indexes_to_checkpoint_tables.php.stub';

    return $migration;
}

function operatorSummaryColumnsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/add_operator_summary_columns_to_command_runs_table.php.stub';

    return $migration;
}

function restoreDecisionEventsMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_checkpoint_restore_decision_events_table.php.stub';

    return $migration;
}

function backupDrillRunMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_checkpoint_backup_drill_runs_table.php.stub';

    return $migration;
}

function verificationRunMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_checkpoint_verification_runs_table.php.stub';

    return $migration;
}

function squashedTablesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require __DIR__.'/../../database/migrations/create_checkpoint_tables.php.stub';

    return $migration;
}

/**
 * @return list<string>
 */
function commandRunIndexNames(): array
{
    return indexNamesForTable('db_ops_command_runs');
}

/**
 * @return list<string>
 */
function backupDrillRunIndexNames(): array
{
    return indexNamesForTable('db_ops_backup_drill_runs');
}

/**
 * @return list<string>
 */
function restoreDecisionEventIndexNames(): array
{
    return indexNamesForTable('db_ops_restore_decision_events');
}

/**
 * @return list<string>
 */
function verificationRunIndexNames(): array
{
    return indexNamesForTable('db_ops_verification_runs');
}

/**
 * @return list<string>
 */
function indexNamesForTable(string $table): array
{
    $connection = DB::connection();
    $driver = $connection->getDriverName();

    return match ($driver) {
        'sqlite' => collect($connection->select(sprintf("PRAGMA index_list('%s')", $table)))->map(static fn (object $index): string => (string) ($index->name ?? ''))->values()->all(),
        'mysql' => collect($connection->select(sprintf('SHOW INDEX FROM `%s`', $table)))->map(static fn (object $index): string => (string) ($index->Key_name ?? ''))->unique()->values()->all(),
        'pgsql' => collect($connection->select('SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?', [$table]))->map(static fn (object $index): string => (string) ($index->indexname ?? ''))->values()->all(),
        default => [],
    };
}
