<?php

namespace App\Notifications;

use App\Models\Task;

/** A task moved to another status (not into or out of Done: that is TaskClosed). */
class TaskStatusChanged extends AppNotification
{
    public function __construct(public Task $task, public string $from, public string $to, public ?string $actorName) {}

    public function event(): string
    {
        return 'status_changed';
    }

    public function title(): string
    {
        return "{$this->task->key_label} moved to {$this->to}";
    }

    public function body(): ?string
    {
        return $this->task->title."\n".($this->actorName ?? 'Someone')." moved it from {$this->from} to {$this->to}.";
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
