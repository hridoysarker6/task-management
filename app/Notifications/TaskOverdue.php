<?php

namespace App\Notifications;

use App\Models\Task;

/** The daily reminder for an overdue task: to the assignee, and to their manager. */
class TaskOverdue extends AppNotification
{
    public function __construct(public Task $task, public ?string $assigneeName = null, public bool $forManager = false) {}

    public function event(): string
    {
        return 'overdue';
    }

    public function title(): string
    {
        $days = (int) $this->task->due_date->diffInDays(today());

        return ($this->forManager ? "{$this->task->key_label} ({$this->assigneeName}) is" : "{$this->task->key_label} is")." overdue by {$days} day".($days === 1 ? '' : 's');
    }

    public function body(): ?string
    {
        return $this->task->title.' — was due '.$this->task->due_date->toFormattedDateString().'.';
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
