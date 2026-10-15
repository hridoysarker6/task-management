<?php

namespace App\Notifications;

use App\Models\Task;

/** Told to the person who raised a request: accepted or rejected (with the reason). */
class TriageDecision extends AppNotification
{
    public function __construct(public Task $task, public bool $accepted, public ?string $reason = null) {}

    public function event(): string
    {
        return 'triage_decision';
    }

    public function title(): string
    {
        return "Your request {$this->task->key_label} was ".($this->accepted ? 'accepted' : 'rejected');
    }

    public function body(): ?string
    {
        return $this->task->title.(! $this->accepted && $this->reason ? "\nReason: {$this->reason}" : '');
    }

    public function organizationId(): ?int
    {
        return $this->task->organization_id;
    }

    public function taskId(): ?int
    {
        return $this->task->id;
    }

    public function url(): string
    {
        return route('tasks.show', $this->task->key_label);
    }
}
