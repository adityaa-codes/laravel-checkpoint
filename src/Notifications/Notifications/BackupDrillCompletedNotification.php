<?php

declare(strict_types=1);

namespace AdityaaCodes\LaravelCheckpoint\Notifications\Notifications;

use AdityaaCodes\LaravelCheckpoint\Events\BackupDrillCompleted;
use AdityaaCodes\LaravelCheckpoint\Notifications\BaseNotification;
use Illuminate\Notifications\Messages\MailMessage;

final class BackupDrillCompletedNotification extends BaseNotification
{
    public function __construct(public BackupDrillCompleted $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(sprintf('[%s] Drill Completed', $this->applicationName()))
            ->line(sprintf('A backup drill operation completed successfully on %s.', $this->applicationName()))
            ->line(sprintf('Operation: %s', $this->event->run->operation ?? 'unknown'))
            ->line(sprintf('Run ID: %d', (int) $this->event->run->getKey()));
    }
}
