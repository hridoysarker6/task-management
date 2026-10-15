<?php

namespace App\Notifications;

use App\Models\Membership;
use App\Models\Organization;
use App\Services\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Base of every notification a person gets about their work. It goes to the bell (database) and/or email
 * depending on what that person chose for this kind of event, and is always queued so a slow mail server
 * never slows a request down.
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** The key of the event (see NotificationEvents): what a person switches on or off. */
    abstract public function event(): string;

    abstract public function title(): string;

    abstract public function body(): ?string;

    /** Where the notification leads when clicked. */
    abstract public function url(): string;

    abstract public function organizationId(): ?int;

    public function taskId(): ?int
    {
        return null;
    }

    public function actionText(): string
    {
        return 'Open';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof Membership
            ? app(NotificationPreferenceService::class)->channels($notifiable, $this->event())
            : ['database', 'mail'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'organization_id' => $this->organizationId(),
            'task_id' => $this->taskId(),
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->organizationId() ? Organization::find($this->organizationId()) : null;

        return (new MailMessage)->subject($this->title())->view('emails.notification', [
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
            'actionText' => $this->actionText(),
            'company' => $organization ? $organization->name : config('app.name'),
            'logo' => $organization && $organization->logo_path ? url(Storage::disk('public')->url($organization->logo_path)) : null,
            'preferencesUrl' => $notifiable instanceof Membership ? route('notifications.preferences') : null,
        ]);
    }
}
