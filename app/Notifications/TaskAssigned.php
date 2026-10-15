<?php

namespace App\Notifications;

use App\Models\Task;

/** Told to the new assignee ("assigned"), and to the one who lost the task ("unassigned"). */
class TaskAssigned extends AppNotification
{
    public function __construct(public Task $task, public ?string $actorName, public string $mode = 'assigned', public ?string $newAssignee = null) {}

    public function event(): string
    {
        return 'task_assigned';
    }

    public function title(): string
    {
        $who = $this->actorName ?? 'Someone';

        return $this->mode === 'assigned'
            ? "{$who} assigned you {$this->task->key_label}"
            : "{$this->task->key_label} is no longer assigned to you";
    }

    public function body(): ?string
    {
        return $this->task->title.($this->mode === 'unassigned' && $this->newAssignee ? " — now with {$this->newAssignee}" : '');
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
