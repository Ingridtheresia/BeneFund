<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class ActivityNotification extends Notification
{
    public function __construct(private readonly array $activity) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->activity;
    }
}
