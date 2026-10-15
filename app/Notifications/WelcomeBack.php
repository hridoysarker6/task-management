<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/** Sent when a former employee is rehired: they sign in with their old account. */
class WelcomeBack extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Organization $organization, public Role $role) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Welcome back to {$this->organization->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("You have rejoined {$this->organization->name} as {$this->role->name}.")
            ->line('Sign in with your existing account. If you forgot the password, use the reset link on the sign-in page.')
            ->action('Sign in', route('login'));
    }
}
