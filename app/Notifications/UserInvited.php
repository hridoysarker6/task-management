<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class UserInvited extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Invitation $invitation, public Organization $organization, public ?string $inviterName = null, public ?string $token = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to join {$this->organization->name}")
            ->greeting('Hello'.($this->invitation->name ? ' '.$this->invitation->name : '').',')
            ->line(($this->inviterName ?? 'A colleague')." has invited you to join {$this->organization->name} as {$this->invitation->role->name}.")
            ->action('Accept invitation', route('invitations.show', $this->token ?? $this->invitation->plainToken))
            ->line('This invitation expires on '.$this->invitation->expires_at->format('j M Y').'.');
    }
}
