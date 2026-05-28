<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Exceptions;

use RuntimeException;

final class ConfigurationException extends RuntimeException
{
    public static function mustNotBeEmpty(string $key): self
    {
        return new self("{$key} must be a non-empty string.");
    }

    public static function mustBePositive(string $key): self
    {
        return new self("{$key} must be greater than zero.");
    }

    public static function mustBeBetween(string $key, int $min, int $max): self
    {
        return new self("{$key} must be between {$min} and {$max}.");
    }

    public static function cannotResolveDirectory(string $label): self
    {
        return new self("Unable to resolve the configured {$label} directory.");
    }

    public static function directoryCreationFailed(string $label, string $path): self
    {
        return new self("Unable to create {$label} directory [{$path}].");
    }

    public static function unsupportedOperation(string $operation, string $context): self
    {
        return new self("Unsupported {$context} operation [{$operation}].");
    }

    public static function targetNotFound(string $path): self
    {
        return new self("Configured target [{$path}] does not exist.");
    }

    public static function targetEscapesOutputDir(string $path): self
    {
        return new self("Target [{$path}] must be inside the configured output directory.");
    }

    public static function targetNotDirectory(string $path): self
    {
        return new self("Target [{$path}] must be a directory export.");
    }

    public static function targetNotFile(string $path): self
    {
        return new self("Target [{$path}] must be a restoreable file export.");
    }

    public static function targetChangedAfterValidation(string $path): self
    {
        return new self("Target [{$path}] changed after validation and must be selected again.");
    }

    public static function noBackupExportsFound(): self
    {
        return new self('No logical backup exports were found for logical_restore_latest.');
    }

    public static function missingRestoreArgument(): self
    {
        return new self('logical_restore_file requires a backup path or export name.');
    }

    public static function missingReplicationMetadata(string $key): self
    {
        return new self("replication_sync requires replication.{$key} metadata.");
    }

    public static function unsupportedReplicationEngine(string $engine): self
    {
        return new self("Unsupported replication engine [{$engine}] for Postgres driver. Postgres driver supports pgsql -> pgsql only.");
    }

    public static function replicationLocalOnly(): self
    {
        return new self('postgres replication execution currently supports only local/configured endpoint semantics. Remote or cross-host source/destination endpoints are not supported. Use matching local profile endpoints and run remote replication through external tooling.');
    }

    public static function governanceBlocked(string $reasons): self
    {
        return new self("Replication apply is blocked by governance preflight at execution time: {$reasons}. Re-queue with approved destination/change window, or run dry-run mode.");
    }

    public static function invalidReplicationJson(): self
    {
        return new self('replication_sync argument must be valid JSON.');
    }

    public static function missingReplicationArtifactPath(): self
    {
        return new self('Postgres replication requires a writable staging artifact path.');
    }

    public static function missingDrillExecutable(): self
    {
        return new self('checkpoint.drivers.postgres.drill_command must contain a valid executable token.');
    }

    public static function notAnArray(string $key): self
    {
        return new self("checkpoint.drivers.postgres.extra_args.{$key} must be an array.");
    }

    public static function physicalRestoreRequiresArgument(): self
    {
        return new self('physical_restore requires a backup directory path as argument.');
    }

    public static function physicalRestoreTargetNotFound(string $path): self
    {
        return new self("Physical backup target directory [{$path}] does not exist.");
    }

    public static function physicalRestoreMissingBaseTar(string $path): self
    {
        return new self("Physical backup target [{$path}] does not contain base.tar.gz.");
    }

    public static function physicalRestoreManualOnly(): self
    {
        return new self('Postgres physical restore requires the PostgreSQL server to be stopped before restoring. Stop the server, then run: tar -xzf <target>/base.tar.gz -C <pgdata>. Once complete, start the server and enqueue the restore as a completed CommandRun.');
    }
}
