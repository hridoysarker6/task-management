<?php

namespace App\Notifications;

use App\Models\Task;

/** Someone mentioned the person with @ in a comment. */
class TaskMentioned extends AppNotification
{
    public function __construct(public Task $task, public string $excerpt, public ?string $actorName) {}

    public function event(): string
    {
        return 'mention';
    }

    public function title(): string
    {
        return ($this->actorName ?? 'Someone')." mentioned you on {$this->task->key_label}";
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
