<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/** Sent to System Admins when an application reaches the review queue (or is resubmitted). */
class CompanyRegistered extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Organization $organization, public bool $resubmitted = false) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->resubmitted ? 'Application resubmitted: ' : 'New company registration: ').$this->organization->name)
            ->line($this->organization->name.' '.($this->resubmitted ? 'has updated and resubmitted its application.' : 'has registered and is waiting for your review.'))
            ->action('Review application', route('admin.companies.show', $this->organization));
    }
}
