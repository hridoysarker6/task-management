<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class CompanyInfoRequested extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Organization $organization, public string $note) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('More information needed for '.$this->organization->name)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We need a little more information before we can approve your company.')
            ->line($this->note)
            ->action('Update application', route('application.edit'));
    }
}
