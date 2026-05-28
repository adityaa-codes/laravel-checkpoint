<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications;

use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillFailed;
use AdityaaCodes\LaravelCheckpoint\Events\BackupFailed;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Notification;

class EventHandler
{
    /** @var array<class-string, class-string<Notification>> */
    protected static array $eventToNotificationMap = [
        BackupFailed::class => Notifications\BackupFailedNotification::class,
        BackupCompleted::class => Notifications\BackupCompletedNotification::class,
        BackupDrillFailed::class => Notifications\BackupDrillFailedNotification::class,
        BackupDrillCompleted::class => Notifications\BackupDrillCompletedNotification::class,
    ];

    public function __construct(
        protected Repository $config,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(array_keys(static::$eventToNotificationMap), function ($event) {
            $notifiable = $this->determineNotifiable();

            $notification = $this->determineNotification($event);

            $channels = $notification->via();
            if ($channels === []) {
                return;
            }

            $notifiable->notify($notification);
        });
    }

    protected function determineNotifiable(): Notifiable
    {
        $notifiableClass = $this->config->get('checkpoint.notifications.notifiable', Notifiable::class);

        return app($notifiableClass);
    }

    protected function determineNotification(object $event): Notification
    {
        $notificationClasses = $this->config->get('checkpoint.notifications.notifications', []);

        $lookingForNotificationClass = class_basename($event).'Notification';

        $notificationClass = collect($notificationClasses)
            ->keys()
            ->first(fn (string $class) => class_basename($class) === $lookingForNotificationClass);

        if (! $notificationClass) {
            $notificationClass = static::$eventToNotificationMap[$event::class] ?? null;
        }

        if (! $notificationClass) {
            return new Notifications\BackupFailedNotification($event);
        }

        return new $notificationClass($event);
    }
}
