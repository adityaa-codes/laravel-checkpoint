<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

it('applies the command run migrations on a fresh install', function (): void {
    Schema::dropIfExists('db_ops_backup_drill_runs');
    Schema::dropIfExists('db_ops_command_runs');

    freshCommandRunMigration()->up();
    metadataCommandRunMigration()->up();
    orphanClaimMigration()->up();
    backupDrillRunMigration()->up();
    reportingIndexesMigration()->up();

    expect(Schema::hasTable('db_ops_command_runs'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'backup_type'))->toBeTrue()
        ->and(Schema::hasColumn('db_ops_command_runs', 'orphan_recovery_claimed_at'))->toBeTrue()
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_orphan_recovery_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_verified_at_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_created_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_finished_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_activity_lookup_index')
        ->and(backupDrillRunIndexNames())->toContain('db_ops_backup_drill_runs_executed_at_result_index')
        ->and(backupDrillRunIndexNames())->toContain('db_ops_backup_drill_runs_result_executed_at_index');
});

it('adds the orphan recovery claim column and index on upgrade installs', function (): void {
    Schema::dropIfExists('db_ops_backup_drill_runs');
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

    orphanClaimMigration()->up();
    orphanClaimMigration()->up();
    reportingIndexesMigration()->up();
    reportingIndexesMigration()->up();

    expect(Schema::hasColumn('db_ops_command_runs', 'orphan_recovery_claimed_at'))->toBeTrue()
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_orphan_recovery_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_verified_at_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_status_created_at_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_finished_lookup_index')
        ->and(commandRunIndexNames())->toContain('db_ops_command_runs_restore_activity_lookup_index');
});

/**
 * @return object{up: callable():void}
 */
function freshCommandRunMigration(): object
{
    /** @var object{up: callable():void} $migration */
    $migration = require __DIR__.'/../../database/migrations/create_checkpoint_command_runs_table.php.stub';

    return $migration;
}

/**
 * @return object{up: callable():void}
 */
function metadataCommandRunMigration(): object
{
    /** @var object{up: callable():void} $migration */
    $migration = require __DIR__.'/../../database/migrations/add_checkpoint_metadata_to_command_runs_table.php.stub';

    return $migration;
}

/**
 * @return object{up: callable():void}
 */
function orphanClaimMigration(): object
{
    /** @var object{up: callable():void} $migration */
    $migration = require __DIR__.'/../../database/migrations/add_orphan_recovery_claim_to_command_runs_table.php.stub';

    return $migration;
}

/**
 * @return object{up: callable():void}
 */
function reportingIndexesMigration(): object
{
    /** @var object{up: callable():void} $migration */
    $migration = require __DIR__.'/../../database/migrations/add_reporting_indexes_to_checkpoint_tables.php.stub';

    return $migration;
}

/**
 * @return object{up: callable():void}
 */
function backupDrillRunMigration(): object
{
    /** @var object{up: callable():void} $migration */
    $migration = require __DIR__.'/../../database/migrations/create_checkpoint_backup_drill_runs_table.php.stub';

    return $migration;
}

/**
 * @return list<string>
 */
function commandRunIndexNames(): array
{
    return array_map(
        static fn (object $index): string => (string) $index->name,
        DB::select("PRAGMA index_list('db_ops_command_runs')"),
    );
}

/**
 * @return list<string>
 */
function backupDrillRunIndexNames(): array
{
    return array_map(
        static fn (object $index): string => (string) $index->name,
        DB::select("PRAGMA index_list('db_ops_backup_drill_runs')"),
    );
}
