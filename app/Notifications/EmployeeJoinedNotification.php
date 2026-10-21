<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class EmployeeJoinedNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{employeeId: int, title: string, message: string, url: string}  $payload
     */
    public function __construct(public array $payload) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{employeeId: int, title: string, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
