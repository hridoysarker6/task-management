<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CompanyApproved extends Notification
{
    public function __construct(public Organization $organization) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome! '.$this->organization->name.' has been approved')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Your company "'.$this->organization->name.'" has been approved. You are its Company Admin.')
            ->line('Sign in to set up departments, roles and invite your team.')
            ->action('Sign in', route('login'));
    }
}
