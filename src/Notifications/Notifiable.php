<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications;

use Illuminate\Notifications\Notifiable as NotifiableTrait;

class Notifiable
{
    use NotifiableTrait;

    /** @return string|array<int,string> */
    public function routeNotificationForMail(): string|array
    {
        $to = config('checkpoint.notifications.mail.to');

        if (empty($to)) {
            return '';
        }

        return $to;
    }

    public function routeNotificationForSlack(): string
    {
        return (string) config('checkpoint.notifications.slack.webhook_url', '');
    }

    public function routeNotificationForTelegram(): string
    {
        return (string) config('checkpoint.notifications.telegram.chat_id', '');
    }

    public function getKey(): int
    {
        return 1;
    }
}
