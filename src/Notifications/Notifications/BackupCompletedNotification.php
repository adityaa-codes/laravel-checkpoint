<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications\Notifications;

use AdityaaCodes\LaravelCheckpoint\Events\BackupCompleted;
use AdityaaCodes\LaravelCheckpoint\Notifications\BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;

final class BackupCompletedNotification extends BaseNotification
{
    public function __construct(public BackupCompleted $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('[%s] Backup Completed', $this->applicationName()))
            ->line(sprintf('A backup operation completed successfully on %s.', $this->applicationName()))
            ->line(sprintf('Operation: %s', $this->event->run->operation ?? 'unknown'))
            ->line(sprintf('Run ID: %d', (int) $this->event->run->getKey()));
    }
}
