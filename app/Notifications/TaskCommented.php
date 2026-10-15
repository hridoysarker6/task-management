<?php

namespace App\Notifications;

use App\Models\Task;

/** A new comment on a task the person watches. */
class TaskCommented extends AppNotification
{
    public function __construct(public Task $task, public string $excerpt, public ?string $actorName) {}

    public function event(): string
    {
        return 'comment';
    }

    public function title(): string
    {
        return ($this->actorName ?? 'Someone')." commented on {$this->task->key_label}";
    }

    public function body(): ?string
    {
        return $this->excerpt;
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
