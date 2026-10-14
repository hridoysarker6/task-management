<?php

namespace App\Services;

use App\Models\Label;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating and changing tasks, with the business rules of WORKFLOW.md section 6:
 * the starting status, who may be assigned, status moves, closing and reopening.
 * Policies decide whether someone may *try*; this class refuses what the rules do not allow.
 */
class TaskService
{
    /** Fields a person may change through update() (status and assignee have their own methods). */
    private const EDITABLE = ['title', 'description', 'type', 'priority', 'parent_id', 'story_points', 'original_estimate_min', 'remaining_estimate_min', 'start_date', 'due_date', 'labels'];

    /** Effort fields that external stakeholders never see or set. */
    private const INTERNAL_ONLY = ['story_points', 'original_estimate_min', 'remaining_estimate_min'];

    public function __construct(
        private TaskNumberService $numbers,
        private WorkflowService $workflow,
        private TaskActivityLogger $activity,
    ) {}

    // --- create ----------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $data  title, type, description, priority, assignee_id, labels, parent_id, story_points,
     *                                      original_estimate_min, start_date, due_date
     *
     * @throws ValidationException
     */
    public function create(Project $project, Membership $actor, array $data): Task
    {
        $this->assertCanWorkIn($project, $actor);

        $data = $this->prepare($project, $actor, $data);
        $type = $data['type'] ?? 'task';
        $parent = $this->parent($project, $data['parent_id'] ?? null);
        $this->assertHierarchy($type, $parent, null);

        $assignee = $this->assignee($project, $data['assignee_id'] ?? null);
        $this->assertCanAssign($project, $actor, $assignee);
        $labels = $this->labelIds($project, $data['labels'] ?? []);
        $this->assertDates($data['start_date'] ?? null, $data['due_date'] ?? null);

        return DB::transaction(function () use ($project, $actor, $data, $type, $parent, $assignee, $labels) {
            $status = $this->initialStatus($project, $actor);

            $task = Task::withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'number' => $this->numbers->next($project),
                'parent_id' => $parent?->id,
                'type' => $type,
                'title' => trim($data['title']),
                'description' => HtmlSanitizer::clean($data['description'] ?? null),
                'status_id' => $status->id,
                'priority' => $data['priority'] ?? 3,
                'reporter_id' => $actor->id,
                'assignee_id' => $assignee?->id,
                'story_points' => $data['story_points'] ?? null,
                'original_estimate_min' => $data['original_estimate_min'] ?? null,
                'remaining_estimate_min' => $data['original_estimate_min'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'backlog_rank' => ((float) Task::withoutGlobalScope(OrganizationScope::class)->where('project_id', $project->id)->max('backlog_rank')) + 1000,
                'board_position' => ((float) Task::withoutGlobalScope(OrganizationScope::class)->where('project_id', $project->id)->where('status_id', $status->id)->max('board_position')) + 1000,
                'completed_at' => $status->category === 'done' ? now() : null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $task->labels()->sync($labels);

            // The reporter and the assignee follow the task from the start.
            $this->watch($task, $actor);
            if ($assignee) {
                $this->watch($task, $assignee);
            }

            return $task->load('project', 'status');
        });
    }

    /** Which status a new task starts in: Triage for requests, Backlog (the starting status) for team work. */
    public function initialStatus(Project $project, Membership $reporter, bool $inSprint = false): ProjectStatus
    {
        $statuses = $project->statuses()->get();

        if ($this->needsTriage($project, $reporter) && ($triage = $statuses->firstWhere('category', 'triage'))) {
            return $triage;
        }

        if ($inSprint && ($todo = $statuses->first(fn ($s) => $s->category === 'todo' && ! $s->is_initial))) {
            return $todo;
        }

        return $statuses->firstWhere('is_initial', true) ?? $statuses->first()
            ?? throw ValidationException::withMessages(['project' => 'This project has no statuses. Add some in its workflow settings.']);
    }

    /** Does a task raised by this person go through triage first? (project triage mode x who they are) */
    public function needsTriage(Project $project, Membership $reporter): bool
    {
        return match ($project->triage_mode) {
            'external_only' => $reporter->isExternal(),
            'all_stakeholders' => $reporter->isExternal() || $this->projectRole($project, $reporter) === 'stakeholder',
            default => false,
        };
    }

    // --- update ----------------------------------------------------------------------------------

    /**
     * Change fields of a task. Only the keys present in $data are touched.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(Task $task, Membership $actor, array $data): Task
    {
        $project = Project::withTrashed()->findOrFail($task->project_id);
        $this->assertProjectOpen($project);

        $data = collect($data)->only(self::EDITABLE)->all();
        if ($actor->isExternal()) {
            $data = collect($data)->except(self::INTERNAL_ONLY)->all();
        }

        return DB::transaction(function () use ($task, $project, $data) {
            if (array_key_exists('title', $data)) {
                $title = trim((string) $data['title']);
                if ($title === '' || mb_strlen($title) > 255) {
                    throw ValidationException::withMessages(['title' => 'Give the task a title of up to 255 characters.']);
                }
                $task->title = $title;
            }

            if (array_key_exists('description', $data)) {
                $task->description = HtmlSanitizer::clean($data['description']);
            }

            if (array_key_exists('priority', $data)) {
                if (! array_key_exists((int) $data['priority'], Task::PRIORITIES)) {
                    throw ValidationException::withMessages(['priority' => 'Priority must be between 1 and 5.']);
                }
                $task->priority = (int) $data['priority'];
            }

            // The type and parent are validated together, since each depends on the other.
            $type = $data['type'] ?? $task->type;
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $task->parent_id;
            if (array_key_exists('type', $data) || array_key_exists('parent_id', $data)) {
                if (! in_array($type, Task::TYPES, true)) {
                    throw ValidationException::withMessages(['type' => 'Choose a valid task type.']);
                }
                $parent = $this->parent($project, $parentId, $task);
                $this->assertHierarchy($type, $parent, $task);
                $task->type = $type;
                $task->parent_id = $parent?->id;
            }

            foreach (['story_points', 'original_estimate_min', 'remaining_estimate_min'] as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field] === '' ? null : $data[$field]; // an emptied input means "none"
                    if ($value !== null && (! is_numeric($value) || $value < 0)) {
                        throw ValidationException::withMessages([$field => 'Enter a number that is zero or more.']);
                    }
                    $task->{$field} = $value;
                }
            }

            if (array_key_exists('start_date', $data) || array_key_exists('due_date', $data)) {
                $start = array_key_exists('start_date', $data) ? $data['start_date'] : $task->start_date?->toDateString();
                $due = array_key_exists('due_date', $data) ? $data['due_date'] : $task->due_date?->toDateString();
                $this->assertDates($start, $due);
                $task->start_date = $start ?: null;
                $task->due_date = $due ?: null;
            }

            if (array_key_exists('labels', $data)) {
                $this->syncLabels($task, $project, $data['labels']);
            }

            $task->save();

            return $task;
        });
    }

    // --- assignment ------------------------------------------------------------------------------

    /**
     * Assign (or unassign with null). Self-assigning needs only the right to work on the task;
     * assigning someone else needs task.assign and the person to be in your data scope (a project manager may pick anyone).
     *
     * @throws ValidationException
     */
    public function assign(Task $task, ?Membership $assignee, Membership $actor): Task
    {
        $project = Project::withTrashed()->findOrFail($task->project_id);
        $this->assertProjectOpen($project);
        $this->assertCanAssign($project, $actor, $assignee, $task);

        $task->assignee_id = $assignee?->id;
        $task->save();

        if ($assignee) {
            $this->watch($task, $assignee);
        }

        return $task;
    }

    // --- status ----------------------------------------------------------------------------------

    /**
     * Move a task to another status, checking the project's allowed moves and the permission each kind of move needs:
     * moving out of Done = task.reopen, into Done = task.close (or self-close when the project allows it),
     * anything else = task.change_status. Closing a parent with open sub-tasks needs $closeChildren = true.
     *
     * @throws ValidationException
     */
    public function changeStatus(Task $task, ProjectStatus $to, Membership $actor, bool $closeChildren = false, ?string $note = null): Task
    {
        $project = Project::withTrashed()->findOrFail($task->project_id);
        $this->assertProjectOpen($project);

        $from = ProjectStatus::findOrFail($task->status_id);

        if ($to->project_id !== $project->id) {
            throw ValidationException::withMessages(['status_id' => 'That status does not belong to this project.']);
        }
        if ($to->id === $from->id) {
            return $task;
        }
        if ($from->category === 'triage') {
            throw ValidationException::withMessages(['status_id' => 'This request is waiting in triage. Accept or reject it in the triage queue.']);
        }
        if ($to->category === 'triage') {
            throw ValidationException::withMessages(['status_id' => 'Tasks cannot be moved back into triage.']);
        }

        $this->assertMayMove($project, $task, $from, $to, $actor);

        $open = $to->category === 'done' ? $this->openChildren($task) : collect();
        if ($open->isNotEmpty()) {
            if (! $closeChildren) {
                throw ValidationException::withMessages(['open_children' => "This task still has {$open->count()} open sub-task(s). Close them as well, or finish them first."]);
            }
            if (! $actor->hasPermission('task.close')) {
                throw ValidationException::withMessages(['open_children' => 'You are not allowed to close the open sub-tasks.']);
            }
        }

        return DB::transaction(function () use ($task, $to, $note, $open) {
            foreach ($open as $child) {
                $this->move($child, $to);
            }

            return $this->move($task, $to, $note);
        });
    }

    /** Put a task in a status without checking who is asking (used for already-validated moves). */
    public function move(Task $task, ProjectStatus $to, ?string $note = null): Task
    {
        $wasDone = ProjectStatus::whereKey($task->status_id)->value('category') === 'done';

        $task->status_id = $to->id;
        $task->completed_at = $to->category === 'done' ? ($wasDone ? $task->completed_at : now()) : null;
        if ($note !== null) {
            $task->resolution_note = $note;
        }
        $task->board_position = ((float) Task::withoutGlobalScope(OrganizationScope::class)->where('project_id', $task->project_id)->where('status_id', $to->id)->max('board_position')) + 1000;
        $task->save();

        return $task;
    }

    /** @throws ValidationException */
    private function assertMayMove(Project $project, Task $task, ProjectStatus $from, ProjectStatus $to, Membership $actor): void
    {
        if ($message = $this->moveDenial($project, $task, $from, $to, $actor)) {
            throw ValidationException::withMessages(['status_id' => $message]);
        }
    }

    /**
     * The statuses this person could move the task to right now (for the status menu).
     *
     * @return list<array{id:int,name:string,category:string,color:string,allowed:bool,reason:?string}>
     */
    public function statusMenu(Task $task, Membership $actor): array
    {
        $project = Project::withTrashed()->findOrFail($task->project_id);
        $from = ProjectStatus::findOrFail($task->status_id);

        return $project->statuses()->get()
            ->reject(fn (ProjectStatus $s) => $s->category === 'triage')
            ->map(function (ProjectStatus $s) use ($project, $task, $from, $actor) {
                $reason = $s->id === $from->id ? null : ($from->category === 'triage'
                    ? 'Accept or reject this request in the triage queue.'
                    : $this->moveDenial($project, $task, $from, $s, $actor));

                return ['id' => $s->id, 'name' => $s->name, 'category' => $s->category, 'color' => $s->color, 'allowed' => $reason === null, 'reason' => $reason];
            })->values()->all();
    }

    /** Why this person cannot make this move, or null when they can. */
    private function moveDenial(Project $project, Task $task, ProjectStatus $from, ProjectStatus $to, Membership $actor): ?string
    {
        $rule = $this->workflow->moveRule($project, $from->id, $to->id);
        if ($rule === false) {
            return "Moving from {$from->name} to {$to->name} is not allowed in this project.";
        }
        if (is_string($rule) && ! $actor->hasPermission($rule)) {
            return "That move needs the permission {$rule}.";
        }

        if ($from->category === 'done' && $to->category !== 'done') {
            $allowed = $actor->hasPermission('task.reopen');
            $why = 'You are not allowed to reopen tasks.';
        } elseif ($to->category === 'done') {
            $selfClose = $project->allow_self_close && $task->assignee_id === $actor->id && $actor->hasPermission('task.change_status');
            $allowed = $actor->hasPermission('task.close') || $selfClose;
            $why = 'You are not allowed to close tasks. A lead or manager reviews and closes them.';
        } else {
            $allowed = $actor->hasPermission('task.change_status');
            $why = 'You are not allowed to change the status of tasks.';
        }

        return $allowed ? null : $why;
    }

    /**
     * People this person may assign the task to: project team members, narrowed to their data scope unless they manage the project.
     *
     * @return list<array{id:int,name:string,role:string}>
     */
    public function assigneeOptions(Project $project, Membership $actor): array
    {
        $isManager = $this->projectRole($project, $actor) === 'manager' || $project->manager_membership_id === $actor->id;
        $canAssign = $actor->hasPermission('task.assign');

        if (! $canAssign && ! $actor->hasPermission('task.change_status')) {
            return [];
        }

        return ProjectMember::where('project_id', $project->id)->whereNull('left_at')->whereIn('project_role', ['manager', 'member'])
            ->with(['membership' => fn ($q) => $q->withoutGlobalScopes()->with(['user:id,name', 'role'])])->get()
            ->map->membership->filter()
            ->filter(fn (Membership $m) => $m->status === Membership::ACTIVE && $m->deleted_at === null && ! $m->isExternal())
            ->filter(fn (Membership $m) => $m->id === $actor->id || ($canAssign && ($isManager || $actor->covers($m))))
            ->sortBy(fn (Membership $m) => $m->user->name)
            ->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name])->values()->all();
    }

    /** Sub-tasks that are not finished (done) or dropped (cancelled). */
    private function openChildren(Task $task)
    {
        return $task->children()->with('status')->get()
            ->filter(fn (Task $c) => ! in_array($c->status->category, ['done', 'cancelled'], true))->values();
    }

    // --- delete / restore ------------------------------------------------------------------------

    /**
     * Soft delete. A task with sub-tasks needs $withChildren = true, which deletes them together.
     *
     * @throws ValidationException
     */
    public function delete(Task $task, bool $withChildren = false): void
    {
        $children = $task->children()->get();

        if ($children->isNotEmpty() && ! $withChildren) {
            throw ValidationException::withMessages(['children' => "This task has {$children->count()} sub-task(s) that would be deleted with it."]);
        }

        DB::transaction(function () use ($task, $children) {
            $at = now();
            foreach ($children as $child) {
                $child->forceFill(['deleted_at' => $at])->saveQuietly();
            }
            $task->forceFill(['deleted_at' => $at])->saveQuietly();
            $this->activity->log($task, 'deleted', meta: ['children' => $children->count()]);
        });
    }

    /** Bring a deleted task back, with the sub-tasks that were deleted along with it. */
    public function restore(Task $task): Task
    {
        $at = $task->deleted_at;

        DB::transaction(function () use ($task, $at) {
            if ($at) {
                Task::withTrashed()->where('parent_id', $task->id)->where('deleted_at', $at)->update(['deleted_at' => null]);
            }
            $task->restore();
        });

        return $task;
    }

    // --- watchers --------------------------------------------------------------------------------

    public function watch(Task $task, Membership $member): void
    {
        $task->watchers()->syncWithoutDetaching([$member->id]);
    }

    public function unwatch(Task $task, Membership $member): void
    {
        $task->watchers()->detach($member->id);
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** The person's role inside the project (manager, member, stakeholder, viewer), or null when not a current member. */
    public function projectRole(Project $project, Membership $member): ?string
    {
        return ProjectMember::where('project_id', $project->id)->where('membership_id', $member->id)->whereNull('left_at')->value('project_role');
    }

    /** @throws ValidationException */
    public function assertCanWorkIn(Project $project, Membership $actor): void
    {
        $this->assertProjectOpen($project);

        $role = $this->projectRole($project, $actor);

        if ($role === null) {
            throw ValidationException::withMessages(['project_id' => 'You are not a member of this project.']);
        }
        if ($role === 'viewer') {
            throw ValidationException::withMessages(['project_id' => 'Viewers can read a project but not add to it.']);
        }
    }

    /** @throws ValidationException */
    private function assertProjectOpen(Project $project): void
    {
        if ($project->isArchived()) {
            throw ValidationException::withMessages(['project' => 'This project is archived. Restore it to make changes.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function prepare(Project $project, Membership $actor, array $data): array
    {
        if (! isset($data['title']) || trim((string) $data['title']) === '' || mb_strlen(trim((string) $data['title'])) > 255) {
            throw ValidationException::withMessages(['title' => 'Give the task a title of up to 255 characters.']);
        }

        $type = $data['type'] ?? 'task';
        if (! in_array($type, Task::TYPES, true) || ($actor->isExternal() && $type === 'epic')) {
            throw ValidationException::withMessages(['type' => 'Choose a valid task type.']);
        }

        if (isset($data['priority']) && ! array_key_exists((int) $data['priority'], Task::PRIORITIES)) {
            throw ValidationException::withMessages(['priority' => 'Priority must be between 1 and 5.']);
        }

        foreach (self::INTERNAL_ONLY as $field) {
            if ($actor->isExternal()) {
                unset($data[$field]);
            } elseif (isset($data[$field]) && $data[$field] !== '' && (! is_numeric($data[$field]) || $data[$field] < 0)) {
                throw ValidationException::withMessages([$field => 'Enter a number that is zero or more.']);
            }
        }

        // External stakeholders raise requests; the team decides who works on them.
        if ($actor->isExternal()) {
            unset($data['assignee_id']);
        }

        return $data;
    }

    /** @throws ValidationException */
    private function parent(Project $project, mixed $parentId, ?Task $self = null): ?Task
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = Task::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $project->organization_id)->where('project_id', $project->id)->find($parentId);

        if (! $parent) {
            throw ValidationException::withMessages(['parent_id' => 'The parent must be a task of the same project.']);
        }

        // No loops: the parent cannot be the task itself or anything below it.
        for ($cursor = $parent, $i = 0; $cursor && $self && $i < 50; $i++) {
            if ($cursor->id === $self->id) {
                throw ValidationException::withMessages(['parent_id' => 'A task cannot sit under itself or one of its own sub-tasks.']);
            }
            $cursor = $cursor->parent_id ? Task::withTrashed()->find($cursor->parent_id) : null;
        }

        return $parent;
    }

    /**
     * epic: no parent. story / task / bug: no parent or an epic. subtask: a story, task or bug.
     * A task that already has children must stay a type that can have them.
     *
     * @throws ValidationException
     */
    private function assertHierarchy(string $type, ?Task $parent, ?Task $self): void
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['parent_id' => $message]);

        match (true) {
            $type === 'epic' && $parent !== null => $fail('An epic cannot sit under another task.'),
            $type === 'subtask' && $parent === null => $fail('A sub-task needs a parent task.'),
            $type === 'subtask' && ! in_array($parent->type, Task::SUBTASK_PARENTS, true) => $fail('A sub-task can only sit under a story, task or bug.'),
            in_array($type, Task::UNDER_EPIC, true) && $parent !== null && $parent->type !== 'epic' => $fail('Stories, tasks and bugs can only sit under an epic. Use a sub-task to break work down.'),
            default => null,
        };

        if ($self) {
            $childTypes = $self->children()->pluck('type')->unique()->all();
            $allowedBelow = match (true) {
                $type === 'epic' => Task::UNDER_EPIC,
                $type === 'subtask' => [],
                default => ['subtask'],
            };

            if (array_diff($childTypes, $allowedBelow)) {
                throw ValidationException::withMessages(['type' => 'This task has children that would no longer fit under a '.$type.'. Move them first.']);
            }
        }
    }

    /** @throws ValidationException */
    private function assignee(Project $project, mixed $id): ?Membership
    {
        if ($id === null || $id === '') {
            return null;
        }

        $member = Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
            ->where('organization_id', $project->organization_id)->find($id);

        if (! $member) {
            throw ValidationException::withMessages(['assignee_id' => 'That person does not exist.']);
        }

        return $member;
    }

    /** @throws ValidationException */
    private function assertCanAssign(Project $project, Membership $actor, ?Membership $assignee, ?Task $task = null): void
    {
        if ($assignee === null) {
            // Taking yourself off a task is always fine; clearing someone else needs task.assign.
            if ($task && $task->assignee_id && $task->assignee_id !== $actor->id && ! $actor->hasPermission('task.assign')) {
                throw ValidationException::withMessages(['assignee_id' => 'You are not allowed to change who a task is assigned to.']);
            }

            return;
        }

        $role = $this->projectRole($project, $assignee);
        if ($assignee->status !== Membership::ACTIVE || $assignee->deleted_at !== null || $assignee->isExternal() || ! in_array($role, ['manager', 'member'], true)) {
            throw ValidationException::withMessages(['assignee_id' => 'Tasks can only be assigned to team members of this project.']);
        }

        if ($assignee->id === $actor->id) {
            if (! $actor->hasPermission('task.change_status') && ! $actor->hasPermission('task.assign')) {
                throw ValidationException::withMessages(['assignee_id' => 'You are not allowed to take on tasks.']);
            }

            return;
        }

        if (! $actor->hasPermission('task.assign')) {
            throw ValidationException::withMessages(['assignee_id' => 'You are not allowed to assign tasks to other people.']);
        }

        $isProjectManager = $this->projectRole($project, $actor) === 'manager' || $project->manager_membership_id === $actor->id;
        if (! $isProjectManager && ! $actor->covers($assignee)) {
            throw ValidationException::withMessages(['assignee_id' => 'That person is outside the people you can assign work to.']);
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     *
     * @throws ValidationException
     */
    private function labelIds(Project $project, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $valid = Label::where('project_id', $project->id)->whereIn('id', $ids)->pluck('id')->all();
        if (count($valid) !== count($ids)) {
            throw ValidationException::withMessages(['labels' => 'Choose labels from this project.']);
        }

        return $ids;
    }

    /** @param list<int|string> $ids */
    private function syncLabels(Task $task, Project $project, array $ids): void
    {
        $new = $this->labelIds($project, $ids);
        $old = $task->labels()->pluck('labels.id')->all();

        if (array_diff($old, $new) || array_diff($new, $old)) {
            $names = fn (array $x) => Label::whereIn('id', $x)->orderBy('name')->pluck('name')->implode(', ');
            $task->labels()->sync($new);
            $this->activity->log($task, 'updated', 'labels', $names($old) ?: null, $names($new) ?: null);
        }
    }

    /** @throws ValidationException */
    private function assertDates(?string $start, ?string $due): void
    {
        foreach (['start_date' => $start, 'due_date' => $due] as $field => $value) {
            if ($value && ! strtotime($value)) {
                throw ValidationException::withMessages([$field => 'Enter a valid date.']);
            }
        }

        if ($start && $due && strtotime($due) < strtotime($start)) {
            throw ValidationException::withMessages(['due_date' => 'The due date cannot be before the start date.']);
        }
    }
}
