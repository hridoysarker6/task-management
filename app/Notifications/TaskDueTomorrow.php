<?php

namespace App\Notifications;

use App\Models\Task;

/** The daily reminder for a task due the next day. */
class TaskDueTomorrow extends AppNotification
{
    public function __construct(public Task $task) {}

    public function event(): string
    {
        return 'due_tomorrow';
    }

    public function title(): string
    {
        return "{$this->task->key_label} is due tomorrow";
    }

    public function body(): ?string
    {
        return $this->task->title;
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
