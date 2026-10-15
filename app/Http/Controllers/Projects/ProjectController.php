<?php

namespace App\Http\Controllers\Projects;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\ProgressService;
use App\Services\ProjectHealthService;
use App\Services\ProjectService;
use App\Services\SprintReportService;
use App\Support\TaskPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends ProjectBaseController
{
    public function index(Request $request): Response
    {
        Gate::authorize('project.view');

        $me = $this->current->membership;
        $status = (string) $request->query('status', 'open'); // open = everything not archived
        abort_unless(in_array($status, ['open', 'all', ...Project::STATUSES], true), 404);

        $q = trim((string) $request->query('q', ''));
        $mine = $request->boolean('mine');
        $departmentId = $request->integer('department_id') ?: null;

        $projects = Project::visibleTo($me)
            ->with(['manager.user:id,name', 'department:id,name'])
            ->withCount('activeMembers')
            ->when($status === 'open', fn ($x) => $x->where('status', '!=', Project::ARCHIVED))
            ->when(in_array($status, Project::STATUSES, true), fn ($x) => $x->where('status', $status))
            ->when($departmentId, fn ($x) => $x->where('department_id', $departmentId))
            ->when($mine, fn ($x) => $x->where(fn ($x) => $x->where('manager_membership_id', $me->id)
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('project_members')
                    ->whereColumn('project_members.project_id', 'projects.id')
                    ->where('project_members.membership_id', $me->id)->whereNull('project_members.left_at'))))
            ->when($q !== '', fn ($x) => $x->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('key', 'like', "%{$q}%")))
            ->orderByRaw("case status when 'active' then 0 when 'on_hold' then 1 when 'completed' then 2 else 3 end")
            ->orderBy('name')
            ->paginate(12)->withQueryString()
            ->through(fn (Project $p) => [
                'id' => $p->id,
                'key' => $p->key,
                'name' => $p->name,
                'description' => $p->description ? str($p->description)->limit(140)->toString() : null,
                'status' => $p->status,
                'department' => $p->department?->name,
                'manager' => $p->manager?->user?->name,
                'members' => $p->active_members_count,
                'start_date' => $p->start_date?->toDateString(),
                'end_date' => $p->end_date?->toDateString(),
            ]);

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => ['status' => $status, 'q' => $q, 'mine' => $mine, 'department_id' => $departmentId],
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'canCreate' => Gate::allows('create', Project::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Project::class);

        return Inertia::render('Projects/Form', ['project' => null] + $this->formOptions());
    }

    public function store(Request $request, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('create', Project::class);

        $project = $projects->create($this->current->organization, $this->current->membership, $this->validated($request, true));

        return redirect()->route('projects.show', $project)->with('success', "Project {$project->key} created.");
    }

    public function show(Project $project, ProgressService $progress, ProjectHealthService $health, SprintReportService $sprints): Response
    {
        Gate::authorize('view', $project);

        $project->load(['manager.user:id,name', 'department:id,name']);
        $me = $this->current->membership;
        $internal = ! $me->isExternal();
        $active = $internal && $project->uses_sprints ? Sprint::where('project_id', $project->id)->where('status', Sprint::ACTIVE)->first() : null;

        return Inertia::render('Projects/Show', [
            'progress' => $progress->forProject($project, $me),
            'health' => $internal ? $health->assess($project, $me) : null,
            'burndown' => $active ? ['sprint' => ['id' => $active->id, 'name' => $active->name]] + $sprints->burndown($active) : null,
            'velocity' => $internal && $project->uses_sprints ? $sprints->velocity($project, 6) : null,
            'activity' => $this->activity($project, $me),
            'project' => $this->header($project) + [
                'description' => $project->description,
                'department' => $project->department?->name,
                'manager' => $project->manager?->user?->name,
                'manager_id' => $project->manager_membership_id,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'uses_sprints' => $project->uses_sprints,
                'triage_mode' => $project->triage_mode,
                'progress_mode' => $project->progress_mode,
            ],
            'statuses' => $project->statuses()->get(['id', 'name', 'category', 'color', 'show_on_board']),
            'members' => $project->activeMembers()->with('membership.user:id,name')->get()
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->membership->user->name, 'project_role' => $m->project_role])->take(12)->values(),
            'memberCount' => $project->activeMembers()->count(),
            'labelCount' => $project->labels()->count(),
            'recentTasks' => Task::visibleTo($this->current->membership)->where('project_id', $project->id)
                ->with(['project', 'status', 'assignee.user:id,name'])->latest('id')->limit(10)->get()
                ->map(fn (Task $t) => TaskPresenter::row($t, ! $this->current->membership->isExternal()))->values(),
            'taskCount' => Task::visibleTo($this->current->membership)->where('project_id', $project->id)->count(),
            'triageCount' => Gate::allows('task.triage')
                ? Task::visibleTo($this->current->membership)->where('project_id', $project->id)->whereHas('status', fn ($q) => $q->where('category', 'triage'))->count() : null,
            'canCreateTask' => Gate::allows('createTask', $project),
            'canSeeTrash' => $this->current->membership->hasPermission('task.delete_any') || Gate::allows('manageMembers', $project),
        ]);
    }

    /**
     * The latest happenings on the tasks the viewer can see, newest first. Internal-only fields are hidden from clients.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(Project $project, Membership $me): array
    {
        $external = $me->isExternal();

        return TaskActivity::whereIn('task_id', Task::visibleTo($me)->where('tasks.project_id', $project->id)->select('tasks.id'))
            ->with(['actor.user:id,name', 'task.project'])->latest('id')->limit($external ? 40 : 10)->get()
            ->reject(fn (TaskActivity $a) => $external && ($a->isInternal() || $a->event === 'commented'))->take(10)
            ->map(fn (TaskActivity $a) => [
                'id' => $a->id, 'event' => $a->event, 'field' => $a->field, 'old' => $a->old_value, 'new' => $a->new_value,
                'actor' => $a->actor?->user?->name, 'task' => $a->task->key_label, 'task_title' => $a->task->title,
                'created_at' => $a->created_at->toIso8601String(),
            ])->all();
    }

    public function edit(Project $project): Response
    {
        Gate::authorize('update', $project);

        return Inertia::render('Projects/Form', [
            'project' => $this->header($project) + [
                'description' => $project->description,
                'department_id' => $project->department_id,
                'manager_membership_id' => $project->manager_membership_id,
                'start_date' => $project->start_date?->toDateString(),
                'end_date' => $project->end_date?->toDateString(),
                'key_locked' => $project->task_counter > 0,
            ],
        ] + $this->formOptions());
    }

    public function update(Request $request, Project $project, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('update', $project);

        $data = $this->validated($request, false);

        // Changing the project manager is a bigger step than editing a name, so it needs its own permission,
        // checked before anything is saved.
        $newManager = array_key_exists('manager_membership_id', $data) && $data['manager_membership_id'] != $project->manager_membership_id;
        if ($newManager) {
            Gate::authorize('manageMembers', $project);
        }

        $projects->update($project, collect($data)->except('manager_membership_id')->all());

        if ($newManager) {
            $projects->changeManager($project, $data['manager_membership_id'], $this->current->membership);
        }

        return redirect()->route('projects.show', $project)->with('success', 'Project updated.');
    }

    public function changeStatus(Request $request, Project $project, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $data = $request->validate(['status' => ['required', Rule::in(Project::STATUSES)]]);

        $projects->changeStatus($project, $data['status']);

        return back()->with('success', match ($data['status']) {
            Project::ARCHIVED => 'Project archived.',
            Project::ON_HOLD => 'Project put on hold.',
            Project::COMPLETED => 'Project marked as completed.',
            default => 'Project is active again.',
        });
    }

    public function destroy(Project $project, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $projects->delete($project);

        return redirect()->route('projects.index')->with('success', "Project {$project->key} deleted.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $request->merge(['key' => strtoupper((string) $request->input('key'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'key' => [$creating ? 'required' : 'sometimes', 'string', 'regex:/^[A-Z][A-Z0-9]{1,9}$/'],
            'description' => ['nullable', 'string', 'max:5000'],
            'department_id' => ['nullable', 'integer'],
            'manager_membership_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ], ['key.regex' => 'Use 2 to 10 capital letters or digits, starting with a letter (for example ECP).']);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'people' => Membership::active()->where('user_type', 'internal')->with(['user:id,name', 'role:id,name'])->get()
                ->sortBy(fn ($m) => $m->user->name)
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name])->values(),
        ];
    }
}
