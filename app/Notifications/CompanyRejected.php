<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class CompanyRejected extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Organization $organization, public string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your application for '.$this->organization->name.' was not approved')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We could not approve your company registration.')
            ->line('Reason: '.$this->reason)
            ->line('You can correct the details and resubmit your application.')
            ->action('Edit application', route('application.edit'));
    }
}
