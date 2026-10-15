<?php

namespace App\Notifications;

use App\Models\Task;

/** A task was closed (done) or reopened. */
class TaskClosed extends AppNotification
{
    public function __construct(public Task $task, public bool $closed, public ?string $actorName) {}

    public function event(): string
    {
        return 'task_closed';
    }

    public function title(): string
    {
        return "{$this->task->key_label} was ".($this->closed ? 'closed' : 'reopened');
    }

    public function body(): ?string
    {
        return $this->task->title."\n".($this->actorName ?? 'Someone').($this->closed ? ' marked it done.' : ' reopened it.');
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
