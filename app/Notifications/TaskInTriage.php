<?php

namespace App\Notifications;

use App\Models\Task;

/** A request is waiting in triage; told to everyone who can triage the project. */
class TaskInTriage extends AppNotification
{
    public function __construct(public Task $task, public ?string $actorName) {}

    public function event(): string
    {
        return 'task_in_triage';
    }

    public function title(): string
    {
        return "New request to triage: {$this->task->key_label}";
    }

    public function body(): ?string
    {
        return $this->task->title.($this->actorName ? "\nRaised by {$this->actorName}." : '');
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
