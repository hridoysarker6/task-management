<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskLink;
use App\Models\WorkLog;
use App\Services\AttachmentService;
use App\Services\ProgressService;
use App\Services\TaskService;
use App\Services\WorkLogService;
use App\Support\TaskPresenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function __construct(private CurrentOrganization $current, private TaskService $tasks, private WorkLogService $logs) {}

    // --- the task page ---------------------------------------------------------------------------

    public function show(Task $task, ProgressService $progress): Response
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('view', $task);

        $me = $this->current->membership;
        $external = $me->isExternal();

        $task->load(['project', 'status', 'reporter.user:id,name', 'assignee.user:id,name', 'labels', 'parent']);
        $project = $task->project;

        // Related tasks are only listed when the viewer may see them, too.
        $visible = fn ($query) => $query->visibleTo($me);

        $children = $task->children()->tap($visible)->with(['project', 'status', 'assignee.user:id,name'])->orderBy('number')->get();

        $links = $this->links($task, $me);

        return Inertia::render('Tasks/Show', [
            'task' => [
                'id' => $task->id,
                'key' => $task->key_label,
                'number' => $task->number,
                'title' => $task->title,
                'description' => $task->description,
                'type' => $task->type,
                'priority' => $task->priority,
                'status' => ['id' => $task->status->id, 'name' => $task->status->name, 'category' => $task->status->category, 'color' => $task->status->color],
                'reporter' => ['id' => $task->reporter_id, 'name' => $task->reporter->user->name],
                'assignee' => $task->assignee ? ['id' => $task->assignee->id, 'name' => $task->assignee->user->name] : null,
                'labels' => $task->labels->map->only(['id', 'name', 'color'])->values(),
                'parent_id' => $task->parent_id,
                'start_date' => $task->start_date?->toDateString(),
                'due_date' => $task->due_date?->toDateString(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'created_at' => $task->created_at->toIso8601String(),
                'resolution_note' => $task->resolution_note,
                // Effort figures are for the team: external stakeholders never receive them.
                'story_points' => $external ? null : ($task->story_points !== null ? (float) $task->story_points : null),
                'original_estimate_min' => $external ? null : $task->original_estimate_min,
                'remaining_estimate_min' => $external ? null : $task->remaining_estimate_min,
                'time_spent_min' => $external ? null : $task->time_spent_min,
            ],
            'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name, 'status' => $project->status, 'progress_mode' => $project->progress_mode],
            'breadcrumbs' => $this->breadcrumbs($task, $me),
            'sprint' => $project->uses_sprints && ! $external && $task->sprint_id && ($sprint = $task->sprint)
                ? ['id' => $sprint->id, 'name' => $sprint->name, 'status' => $sprint->status, 'project_id' => $project->id] : null,
            'parent' => $task->parent && Task::visibleTo($me)->whereKey($task->parent_id)->exists()
                ? ['key' => $task->parent->project->key.'-'.$task->parent->number, 'title' => $task->parent->title, 'type' => $task->parent->type] : null,
            'children' => $children->map(fn (Task $c) => TaskPresenter::row($c, ! $external))->values(),
            'progress' => $task->type === 'epic' ? $progress->forEpic($task, $project) : null,
            'comments' => $this->comments($task, $me),
            'attachments' => $task->attachments()->with('uploader.user:id,name')->get()->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'size' => $a->size_bytes,
                'is_image' => $a->isImage(),
                'url' => route('attachments.download', $a),
                'preview_url' => $a->isImage() ? route('attachments.download', [$a, 'inline' => 1]) : null,
                'uploader' => $a->uploader?->user?->name,
                'created_at' => $a->created_at->toIso8601String(),
                'can_delete' => Gate::allows('deleteAttachment', $a),
            ]),
            'links' => $links,
            'watchers' => $external ? [] : $task->watchers()->with('user:id,name')->get()->map(fn ($w) => ['id' => $w->id, 'name' => $w->user->name])->values(),
            'isWatching' => $task->watchers()->where('memberships.id', $me->id)->exists(),
            'activities' => $this->activities($task, $external),
            'worklogs' => $external ? [] : $this->worklogs($task, $me),
            'rollup' => $task->type === 'epic' && ! $external ? $this->effortRollup($task, $me) : null,
            'abilities' => [
                'update' => Gate::allows('update', $task),
                'change_status' => Gate::allows('changeStatus', $task),
                'assign' => Gate::allows('assign', $task),
                'delete' => Gate::allows('delete', $task),
                'comment' => Gate::allows('comment', $task),
                'attach' => Gate::allows('attach', $task),
                'link' => Gate::allows('link', $task),
                'triage' => Gate::allows('triage', $task),
                'create_child' => Gate::allows('createTask', $project),
                'create_subtask' => Gate::allows('createTask', $project) && in_array($task->type, Task::SUBTASK_PARENTS, true),
                'internal_comments' => ! $external,
                'effort' => ! $external,
                'log_work' => ! $external && Gate::allows('worklog.create') && $this->canLogWork($task, $me),
            ],
            'options' => [
                'statuses' => $this->tasks->statusMenu($task, $me),
                'assignees' => $this->tasks->assigneeOptions($project, $me),
                'labels' => Label::where('project_id', $project->id)->get(['id', 'name', 'color']),
                'types' => $external ? array_values(array_diff(Task::TYPES, ['epic'])) : Task::TYPES,
                'priorities' => collect(Task::PRIORITIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'mentionable' => $external ? [] : $this->mentionable($project),
            ],
            'isInTriage' => $task->status->category === 'triage',
        ]);
    }

    // --- create ----------------------------------------------------------------------------------

    /** Can this person log time here? Same rule the service enforces: an open project they work in, and a live task. */
    private function canLogWork(Task $task, Membership $me): bool
    {
        return ! $task->project->isArchived()
            && ! in_array($task->status->category, ['triage', 'cancelled'], true)
            && in_array($this->tasks->projectRole($task->project, $me), ['manager', 'member'], true);
    }

    /**
     * The time entries on a task the viewer may see: their own, and their team's with worklog.view_team.
     *
     * @return list<array<string, mixed>>
     */
    private function worklogs(Task $task, Membership $me): array
    {
        // Other people's entries: only for those in the viewer's data scope, and only with worklog.view_team.
        $inScope = $me->hasPermission('worklog.view_team') ? Membership::visibleTo($me)->pluck('id')->flip() : collect();

        return WorkLog::where('task_id', $task->id)->with(['author.user:id,name', 'timesheet:id,status'])
            ->orderByDesc('work_date')->orderByDesc('id')->limit(200)->get()
            ->filter(fn (WorkLog $l) => $l->membership_id === $me->id || $inScope->has($l->membership_id))
            ->map(fn (WorkLog $l) => [
                'id' => $l->id,
                'who' => ['id' => $l->membership_id, 'name' => $l->author->user->name],
                'date' => $l->work_date->toDateString(),
                'minutes' => $l->minutes,
                'note' => $l->note,
                'locked' => $l->timesheet !== null && ! $l->timesheet->isOpen(),
                'can_edit' => $this->logs->canEdit($l, $me),
            ])->values()->all();
    }

    /**
     * An epic's estimate against the time actually spent, over everything beneath it.
     *
     * @return array{original:int,spent:int,remaining:int,tasks:int}
     */
    private function effortRollup(Task $epic, Membership $me): array
    {
        $children = Task::visibleTo($me)->where('parent_id', $epic->id)->get(['id', 'original_estimate_min', 'remaining_estimate_min', 'time_spent_min']);
        $grandchildren = Task::visibleTo($me)->whereIn('parent_id', $children->pluck('id'))->get(['id', 'original_estimate_min', 'remaining_estimate_min', 'time_spent_min']);
        $all = $children->concat($grandchildren);

        return [
            'original' => (int) $all->sum('original_estimate_min'),
            'spent' => (int) $all->sum('time_spent_min'),
            'remaining' => (int) $all->sum('remaining_estimate_min'),
            'tasks' => $all->count(),
        ];
    }

    /** Data for the create-task dialog: the projects they can add to, and the choices of the selected project. */
    public function createOptions(Request $request): JsonResponse
    {
        Gate::authorize('task.create');

        $me = $this->current->membership;

        $projects = Project::visibleTo($me)->where('status', '!=', Project::ARCHIVED)->orderBy('name')->get()
            ->filter(fn (Project $p) => Gate::allows('createTask', $p))->values();

        $project = $request->filled('project_id') ? $projects->firstWhere('id', $request->integer('project_id')) : null;

        return response()->json([
            'projects' => $projects->map->only(['id', 'key', 'name'])->values(),
            'project' => $project ? [
                'id' => $project->id,
                'key' => $project->key,
                'assignees' => $this->tasks->assigneeOptions($project, $me),
                'labels' => Label::where('project_id', $project->id)->get(['id', 'name', 'color']),
                'triage' => $this->tasks->needsTriage($project, $me),
                'uses_sprints' => $project->uses_sprints,
            ] : null,
            'types' => $me->isExternal() ? array_values(array_diff(Task::TYPES, ['epic'])) : Task::TYPES,
            'priorities' => collect(Task::PRIORITIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'effort' => ! $me->isExternal(),
            'maxFileMb' => AttachmentService::MAX_KB / 1024,
            'extensions' => AttachmentService::EXTENSIONS,
        ]);
    }

    public function store(Request $request, AttachmentService $attachments): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(Task::TYPES)],
            'description' => ['nullable', 'string', 'max:100000'],
            'priority' => ['nullable', 'integer', 'between:1,5'],
            'assignee_id' => ['nullable', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['integer'],
            'story_points' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'original_estimate_min' => ['nullable', 'integer', 'min:0', 'max:6000000'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'files' => ['nullable', 'array', 'max:'.AttachmentService::MAX_PER_TASK],
            'files.*' => ['file'],
        ]);

        $project = Project::findOrFail($data['project_id']);
        Gate::authorize('view', $project);
        Gate::authorize('createTask', $project);

        $me = $this->current->membership;
        $task = $this->tasks->create($project, $me, $data);

        // Files ride along with the new task. A rejected file fails the whole request, so nothing is half-saved.
        foreach ($request->file('files', []) as $file) {
            if (Gate::allows('attach', $task)) {
                $attachments->add($task, $me, $file);
            }
        }

        $triage = $task->status->category === 'triage';

        return redirect()->route('tasks.show', $task->key_label)
            ->with('success', $triage ? "{$task->key_label} was sent for triage. The team will review it." : "{$task->key_label} created.");
    }

    // --- change ----------------------------------------------------------------------------------

    /** Inline editing: send any of title, description, type, priority, parent_id, labels, story_points, estimates, dates. */
    public function update(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('update', $task);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'type' => ['sometimes', Rule::in(Task::TYPES)],
            'priority' => ['sometimes', 'integer', 'between:1,5'],
            'parent_id' => ['sometimes', 'nullable', 'integer'],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['integer'],
            'story_points' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999'],
            'original_estimate_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6000000'],
            'remaining_estimate_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:6000000'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $this->tasks->update($task, $this->current->membership, $data);

        return back()->with('success', 'Saved.');
    }

    public function status(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('changeStatus', $task);

        $data = $request->validate([
            'status_id' => ['required', 'integer'],
            'close_children' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $to = ProjectStatus::where('project_id', $task->project_id)->findOrFail($data['status_id']);

        $this->tasks->changeStatus($task, $to, $this->current->membership, (bool) ($data['close_children'] ?? false), $data['note'] ?? null);

        return back()->with('success', "Moved to {$to->name}.");
    }

    public function assign(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('assign', $task);

        $data = $request->validate(['assignee_id' => ['present', 'nullable', 'integer']]);

        $assignee = $data['assignee_id']
            ? Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
                ->where('organization_id', $task->organization_id)->findOrFail($data['assignee_id'])
            : null;

        $this->tasks->assign($task, $assignee, $this->current->membership);

        return back()->with('success', $assignee ? 'Assigned.' : 'Unassigned.');
    }

    public function watch(Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('watch', $task);

        $this->tasks->watch($task, $this->current->membership);

        return back()->with('success', 'You are watching this task.');
    }

    public function unwatch(Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('watch', $task);

        $this->tasks->unwatch($task, $this->current->membership);

        return back()->with('success', 'You stopped watching this task.');
    }

    // --- delete / restore ------------------------------------------------------------------------

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('delete', $task);

        $data = $request->validate(['with_children' => ['sometimes', 'boolean']]);
        $project = $task->project()->first();

        $this->tasks->delete($task, (bool) ($data['with_children'] ?? false));

        return redirect()->route('projects.show', $project)->with('success', "{$task->key_label} deleted.");
    }

    public function restore(Task $task): RedirectResponse
    {
        abort_if($task->deleted_at === null, 404);
        Gate::authorize('restore', $task);

        $this->tasks->restore($task);

        return redirect()->route('tasks.show', $task->key_label)->with('success', "{$task->key_label} restored.");
    }

    // --- search (pickers) ------------------------------------------------------------------------

    /** Tasks the viewer can see, by key ("ECP-4") or words from the title. Used by the link and parent pickers. */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('task.view');

        $q = trim((string) $request->query('q', ''));
        $me = $this->current->membership;

        $query = Task::visibleTo($me)->with(['project', 'status'])->orderByDesc('id')->limit(12);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }
        if ($request->filled('types')) {
            $query->whereIn('type', array_intersect(explode(',', (string) $request->query('types')), Task::TYPES));
        }
        if ($request->filled('exclude')) {
            $query->where('id', '!=', $request->integer('exclude'));
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9]{1,9})-(\d*)$/', $q, $m)) {
            $query->whereHas('project', fn ($p) => $p->where('key', strtoupper($m[1])))
                ->when($m[2] !== '', fn ($x) => $x->where('number', (int) $m[2]));
        } elseif ($q !== '') {
            $query->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%');
        }

        return response()->json($query->get()->map(fn (Task $t) => [
            'id' => $t->id,
            'key' => $t->key_label,
            'title' => $t->title,
            'type' => $t->type,
            'status' => $t->status->name,
        ])->values());
    }

    // --- pieces of the page ----------------------------------------------------------------------

    /** @return list<array{key:string,title:string,type:string}> epic > parent chain above the task, only what the viewer can see */
    private function breadcrumbs(Task $task, Membership $me): array
    {
        $chain = [];
        $cursor = $task->parent;

        for ($i = 0; $cursor && $i < 5; $i++) {
            if (Task::visibleTo($me)->whereKey($cursor->id)->exists()) {
                array_unshift($chain, ['key' => $cursor->project->key.'-'.$cursor->number, 'title' => $cursor->title, 'type' => $cursor->type]);
            }
            $cursor = $cursor->parent_id ? Task::withTrashed()->with('project')->find($cursor->parent_id) : null;
        }

        return $chain;
    }

    /** @return list<array<string, mixed>> */
    private function links(Task $task, Membership $me): array
    {
        $out = $task->outgoingLinks()->with(['target.project', 'target.status'])->get()
            ->map(fn (TaskLink $l) => [$l, $l->target, TaskLink::WORDING[$l->type][0]])
            ->concat($task->incomingLinks()->with(['source.project', 'source.status'])->get()
                ->map(fn (TaskLink $l) => [$l, $l->source, TaskLink::WORDING[$l->type][1]]));

        $visibleIds = Task::visibleTo($me)->whereIn('id', $out->map(fn ($x) => $x[1]->id))->pluck('id')->all();
        $canLink = Gate::allows('link', $task);

        return $out->filter(fn ($x) => in_array($x[1]->id, $visibleIds, true))->map(fn ($x) => [
            'id' => $x[0]->id,
            'wording' => $x[2],
            'type' => $x[0]->type,
            'task' => ['key' => $x[1]->key_label, 'title' => $x[1]->title, 'status' => $x[1]->status->name, 'category' => $x[1]->status->category],
            'can_delete' => $canLink,
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function comments(Task $task, Membership $me): array
    {
        return $task->comments()->with(['author.user:id,name'])
            ->when($me->isExternal(), fn ($q) => $q->where('is_internal', false))
            ->get()->map(fn ($c) => [
                'id' => $c->id,
                'body' => $c->body,
                'is_internal' => $c->is_internal,
                'author' => ['id' => $c->membership_id, 'name' => $c->author?->user->name ?? 'Unknown', 'former' => $c->author?->deleted_at !== null],
                'created_at' => $c->created_at->toIso8601String(),
                'edited_at' => $c->edited_at?->toIso8601String(),
                'can_edit' => Gate::allows('editComment', $c),
                'can_delete' => Gate::allows('deleteComment', $c),
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function activities(Task $task, bool $external): array
    {
        return $task->activities()->with('actor.user:id,name')->limit(150)->get()
            ->reject(fn (TaskActivity $a) => $external && (
                $a->isInternal()
                || ($a->event === 'commented' && ($a->meta['is_internal'] ?? false))
            ))
            ->map(fn (TaskActivity $a) => [
                'id' => $a->id,
                'event' => $a->event,
                'field' => $a->field,
                'old' => $a->old_value,
                'new' => $a->new_value,
                'actor' => $a->actor?->user?->name,
                'created_at' => $a->created_at->toIso8601String(),
            ])->values()->all();
    }

    /** Project members who can be @mentioned. */
    private function mentionable(Project $project): array
    {
        return $project->activeMembers()->with(['membership' => fn ($q) => $q->withoutGlobalScopes()->with('user:id,name')])->get()
            ->filter(fn ($m) => $m->membership && ! $m->membership->isExternal() && $m->membership->deleted_at === null)
            ->map(fn ($m) => ['id' => $m->membership_id, 'name' => $m->membership->user->name])
            ->sortBy('name')->values()->all();
    }
}
