<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Task;
use App\Models\TaskLink;
use Illuminate\Validation\ValidationException;

/** Links between tasks: blocks, relates to, duplicates, clones. One row per pair; the other side's wording is derived. */
class TaskLinkService
{
    public function __construct(private TaskActivityLogger $activity) {}

    /** @throws ValidationException */
    public function link(Task $source, Task $target, string $type, Membership $by): TaskLink
    {
        if (! in_array($type, Task::LINK_TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose a link type.']);
        }
        if ($source->id === $target->id) {
            throw ValidationException::withMessages(['target' => 'A task cannot be linked to itself.']);
        }
        if ($source->organization_id !== $target->organization_id) {
            throw ValidationException::withMessages(['target' => 'Choose a task from your company.']);
        }

        $exists = TaskLink::where('source_task_id', $source->id)->where('target_task_id', $target->id)->where('type', $type)->exists()
            // "relates to" reads the same both ways, so the reverse pair counts as a duplicate too.
            || ($type === 'relates_to' && TaskLink::where('source_task_id', $target->id)->where('target_task_id', $source->id)->where('type', $type)->exists());

        if ($exists) {
            throw ValidationException::withMessages(['target' => 'These tasks are already linked this way.']);
        }

        $link = TaskLink::create(['source_task_id' => $source->id, 'target_task_id' => $target->id, 'type' => $type, 'created_by' => $by->id]);

        $source->loadMissing('project');
        $target->loadMissing('project');
        [$forward, $back] = TaskLink::WORDING[$type];
        $this->activity->log($source, 'linked', 'link', null, "{$forward} {$target->key_label}", ['type' => $type, 'other' => $target->id], $by->id);
        $this->activity->log($target, 'linked', 'link', null, "{$back} {$source->key_label}", ['type' => $type, 'other' => $source->id], $by->id);

        return $link;
    }

    public function unlink(TaskLink $link, Membership $by): void
    {
        $source = Task::withTrashed()->with('project')->find($link->source_task_id);
        $target = Task::withTrashed()->with('project')->find($link->target_task_id);

        $link->delete();

        if ($source && $target) {
            [$forward, $back] = TaskLink::WORDING[$link->type];
            $this->activity->log($source, 'unlinked', 'link', "{$forward} {$target->key_label}", null, ['type' => $link->type], $by->id);
            $this->activity->log($target, 'unlinked', 'link', "{$back} {$source->key_label}", null, ['type' => $link->type], $by->id);
        }
    }
}
