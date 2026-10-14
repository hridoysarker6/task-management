<?php

namespace App\Services;

use App\Models\Label;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * One action on many tasks at once (the list view). Every task is checked on its own, exactly as if the person
 * had done it by hand: what they may not do to one task is reported back, and the rest still goes through.
 */
class BulkTaskService
{
    public const ACTIONS = ['assign', 'status', 'label_add', 'label_remove', 'delete'];

    public const MAX = 200;

    public function __construct(private TaskService $tasks) {}

    /**
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $options  assignee_id | status_id (+ close_children) | label_id | with_children
     * @return array{updated:int,failed:list<array{key:string,reason:string}>}
     *
     * @throws ValidationException
     */
    public function apply(Project $project, Membership $actor, array $ids, string $action, array $options = []): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Choose an action.']);
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === [] || count($ids) > self::MAX) {
            throw ValidationException::withMessages(['ids' => 'Select between 1 and '.self::MAX.' tasks.']);
        }

        // Resolve what the action points at once, so a bad choice fails before anything changes.
        $assignee = $status = $label = null;
        if ($action === 'assign' && ! empty($options['assignee_id'])) {
            $assignee = Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
                ->where('organization_id', $project->organization_id)->find($options['assignee_id'])
                ?? throw ValidationException::withMessages(['assignee_id' => 'That person does not exist.']);
        }
        if ($action === 'status') {
            $status = ProjectStatus::where('project_id', $project->id)->find($options['status_id'] ?? 0)
                ?? throw ValidationException::withMessages(['status_id' => 'Choose a status of this project.']);
        }
        if (in_array($action, ['label_add', 'label_remove'], true)) {
            $label = Label::where('project_id', $project->id)->find($options['label_id'] ?? 0)
                ?? throw ValidationException::withMessages(['label_id' => 'Choose a label of this project.']);
        }

        // Only tasks of this project that the person can see; anything else is reported, not touched.
        $found = Task::visibleTo($actor)->where('project_id', $project->id)->whereIn('id', $ids)->with('project')->get()->keyBy('id');

        $updated = 0;
        $failed = [];

        foreach ($ids as $id) {
            $task = $found->get($id);

            if (! $task) {
                $failed[] = ['key' => "#{$id}", 'reason' => 'Not found, or you cannot see it.'];

                continue;
            }

            try {
                $this->one($task, $actor, $action, $assignee, $status, $label, $options);
                $updated++;
            } catch (ValidationException $e) {
                $failed[] = ['key' => $task->key_label, 'reason' => (string) collect($e->errors())->flatten()->first()];
            } catch (AuthorizationException $e) {
                $failed[] = ['key' => $task->key_label, 'reason' => 'You are not allowed to do that to this task.'];
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** @param array<string, mixed> $options */
    private function one(Task $task, Membership $actor, string $action, ?Membership $assignee, ?ProjectStatus $status, ?Label $label, array $options): void
    {
        match ($action) {
            'assign' => $this->assign($task, $actor, $assignee),
            'status' => $this->status($task, $actor, $status, (bool) ($options['close_children'] ?? false)),
            'label_add', 'label_remove' => $this->label($task, $actor, $label, $action === 'label_add'),
            'delete' => $this->delete($task, (bool) ($options['with_children'] ?? false)),
            default => throw new \LogicException("Unknown bulk action {$action}."),
        };
    }

    private function assign(Task $task, Membership $actor, ?Membership $assignee): void
    {
        Gate::authorize('assign', $task);
        $this->tasks->assign($task, $assignee, $actor);
    }

    private function status(Task $task, Membership $actor, ProjectStatus $to, bool $closeChildren): void
    {
        Gate::authorize('changeStatus', $task);
        $this->tasks->changeStatus($task, $to, $actor, $closeChildren);
    }

    private function label(Task $task, Membership $actor, Label $label, bool $add): void
    {
        Gate::authorize('update', $task);

        $ids = $task->labels()->pluck('labels.id')->all();
        $ids = $add ? array_values(array_unique([...$ids, $label->id])) : array_values(array_diff($ids, [$label->id]));

        $this->tasks->update($task, $actor, ['labels' => $ids]);
    }

    private function delete(Task $task, bool $withChildren): void
    {
        Gate::authorize('delete', $task);
        $this->tasks->delete($task, $withChildren);
    }
}
