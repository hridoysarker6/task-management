<?php

namespace App\Observers;

use App\Models\Membership;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Services\TaskActivityLogger;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Writes `task_activities` for every change made to a task, whoever or whatever made it
 * (a form, a drag on the board, an import). That keeps the history complete without each caller remembering to log.
 */
class TaskObserver
{
    /** Column => label shown in the timeline. Columns not listed here (positions, counters) are not logged. */
    private const FIELDS = [
        'title' => 'title',
        'description' => 'description',
        'type' => 'type',
        'priority' => 'priority',
        'parent_id' => 'parent',
        'sprint_id' => 'sprint',
        'story_points' => 'story_points',
        'original_estimate_min' => 'original_estimate_min',
        'remaining_estimate_min' => 'remaining_estimate_min',
        'start_date' => 'start_date',
        'due_date' => 'due_date',
        'reporter_id' => 'reporter',
        'resolution_note' => 'resolution_note',
    ];

    public function __construct(private TaskActivityLogger $log, private CurrentOrganization $current) {}

    public function creating(Task $task): void
    {
        $actor = $this->current->membership?->id;

        $task->created_by ??= $actor;
        $task->updated_by ??= $actor;
    }

    public function created(Task $task): void
    {
        $this->log->log($task, 'created', meta: ['title' => $task->title, 'type' => $task->type]);
    }

    public function updating(Task $task): void
    {
        if ($task->isDirty() && ($actor = $this->current->membership?->id)) {
            $task->updated_by = $actor;
        }
    }

    public function updated(Task $task): void
    {
        foreach ($task->getChanges() as $column => $new) {
            $old = $task->getOriginal($column);

            if ($column === 'status_id') {
                $this->log->log($task, 'status_changed', 'status', $this->status($old), $this->status($new), ['from' => $old, 'to' => $new]);
            } elseif ($column === 'assignee_id') {
                $this->log->log($task, 'assigned', 'assignee', $this->person($old), $this->person($new), ['from' => $old, 'to' => $new]);
            } elseif ($column === 'completed_at') {
                continue; // follows from the status change
            } elseif (isset(self::FIELDS[$column])) {
                $field = self::FIELDS[$column];
                $this->log->log($task, 'updated', $field, $this->value($column, $old), $this->value($column, $new));
            }
        }
    }

    public function deleted(Task $task): void
    {
        if (! $task->isForceDeleting()) {
            $this->log->log($task, 'deleted');
        }
    }

    public function restored(Task $task): void
    {
        $this->log->log($task, 'restored');
    }

    private function status(mixed $id): ?string
    {
        return $id ? ProjectStatus::find($id)?->name : null;
    }

    private function person(mixed $id): ?string
    {
        return $id ? Membership::withoutGlobalScopes()->withTrashed()->with('user:id,name')->find($id)?->user?->name : null;
    }

    private function value(string $column, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($column) {
            // The text of a description can be long and is shown as "changed", so only the fact is kept.
            'description' => '',
            'priority' => Task::PRIORITIES[(int) $value] ?? (string) $value,
            'parent_id' => ($t = Task::withTrashed()->with('project')->find($value)) ? $t->key_label : (string) $value,
            'reporter_id' => $this->person($value),
            'start_date', 'due_date' => substr((string) $value, 0, 10),
            default => (string) $value,
        };
    }
}
