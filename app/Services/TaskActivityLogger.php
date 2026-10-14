<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Support\Tenancy\CurrentOrganization;

/** Writes the lines of a task's history. The actor is whoever is acting in the current company (null = the system). */
class TaskActivityLogger
{
    public function __construct(private CurrentOrganization $current) {}

    /** @param array<string, mixed>|null $meta */
    public function log(Task $task, string $event, ?string $field = null, ?string $old = null, ?string $new = null, ?array $meta = null, ?int $actorId = null): TaskActivity
    {
        return TaskActivity::create([
            'organization_id' => $task->organization_id,
            'task_id' => $task->id,
            'membership_id' => $actorId ?? $this->current->membership?->id,
            'event' => $event,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }
}
