<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications;

use Illuminate\Notifications\Notification;

abstract class BaseNotification extends Notification
{
    public function via(): array
    {
        $notificationChannels = config('checkpoint.notifications.notifications.'.static::class, []);

        if (! is_array($notificationChannels)) {
            return [];
        }

        return array_filter($notificationChannels, static fn (mixed $channel): bool => is_string($channel) && $channel !== '');
    }

    protected function applicationName(): string
    {
        $name = config('app.name', 'Laravel');
        $env = app()->environment();

        return "{$name} ({$env})";
    }
}
