<?php

declare(strict_types=1);

use AdityaaCodes\LaravelCheckpoint\Enums\CommandRunStatus;
use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use AdityaaCodes\LaravelCheckpoint\Models\CommandRun;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifiable;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupCompletedNotification;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupDrillCompletedNotification;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupDrillFailedNotification;
use AdityaaCodes\LaravelCheckpoint\Notifications\Notifications\BackupFailedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
    config()->set('checkpoint.notifications.mail.to', 'test@example.com');
    config()->set('checkpoint.notifications.notifications', [
        BackupFailedNotification::class => ['mail'],
        BackupCompletedNotification::class => ['mail'],
        BackupDrillFailedNotification::class => ['mail'],
        BackupDrillCompletedNotification::class => ['mail'],
    ]);
});

it('EventHandler maps BackupFailed to BackupFailedNotification', function (): void {
    $run = CommandRun::factory()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
    ]);

    event(new BackupFailed($run, 1, 'Backup failed'));

    Notification::assertSentTo(new Notifiable, BackupFailedNotification::class);
});

it('EventHandler maps BackupCompleted to BackupCompletedNotification', function (): void {
    $run = CommandRun::factory()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Succeeded,
    ]);

    event(new BackupCompleted($run, 0, 'Backup completed'));

    Notification::assertSentTo(new Notifiable, BackupCompletedNotification::class);
});

it('EventHandler maps BackupDrillFailed to BackupDrillFailedNotification', function (): void {
    $run = CommandRun::factory()->create([
        'operation' => 'backup_drill',
        'status' => CommandRunStatus::Failed,
    ]);

    event(new BackupDrillFailed($run, 1, 'Drill failed'));

    Notification::assertSentTo(new Notifiable, BackupDrillFailedNotification::class);
});

it('EventHandler maps BackupDrillCompleted to BackupDrillCompletedNotification', function (): void {
    $run = CommandRun::factory()->create([
        'operation' => 'backup_drill',
        'status' => CommandRunStatus::Succeeded,
    ]);

    event(new BackupDrillCompleted($run));

    Notification::assertSentTo(new Notifiable, BackupDrillCompletedNotification::class);
});

it('skips notification when no channels configured', function (): void {
    config()->set('checkpoint.notifications.notifications', [
        BackupFailedNotification::class => [],
    ]);

    $run = CommandRun::factory()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
    ]);

    event(new BackupFailed($run, 1, 'Backup failed'));

    Notification::assertNothingSent();
});

it('skips notification when class not in config', function (): void {
    config()->set('checkpoint.notifications.notifications', []);

    $run = CommandRun::factory()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
    ]);

    event(new BackupFailed($run, 1, 'Backup failed'));

    Notification::assertNothingSent();
});

it('skips notification when CP_ALERT_EMAIL is empty', function (): void {
    config()->set('checkpoint.notifications.mail.to', '');
    config()->set('checkpoint.notifications.notifications', [
        BackupFailedNotification::class => [],
    ]);

    $run = CommandRun::factory()->create([
        'operation' => 'logical_backup',
        'status' => CommandRunStatus::Failed,
    ]);

    event(new BackupFailed($run, 1, 'Backup failed'));

    Notification::assertNothingSent();
});
