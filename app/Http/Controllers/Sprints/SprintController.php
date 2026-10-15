<?php

namespace App\Http\Controllers\Sprints;

use App\Http\Controllers\Projects\ProjectBaseController;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Services\BoardService;
use App\Services\SprintService;
use App\Support\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Sprint planning of a project: create sprints, move tasks between the backlog and a sprint, start and complete. */
class SprintController extends ProjectBaseController
{
    /** Tasks listed in the backlog panel at most. */
    private const BACKLOG_LIMIT = 300;

    public function index(Project $project): Response
    {
        $me = $this->current->membership;
        $this->authorizeProject($project, 'sprint.view');

        $sprints = Sprint::where('project_id', $project->id)->open()
            ->orderByRaw("status = 'active' desc")->orderBy('start_date')->orderBy('id')->get();

        $cards = Task::visibleTo($me)->where('project_id', $project->id)
            ->where(fn ($q) => $q->whereIn('sprint_id', $sprints->pluck('id'))->orWhere(fn ($b) => $b->whereNull('sprint_id')
                ->whereNotIn('type', ['epic', 'subtask'])->whereHas('status', fn ($s) => $s->where('category', 'todo'))))
            ->with(['project', 'status', 'assignee.user:id,name', 'labels', 'parent.parent'])
            ->orderBy('backlog_rank')->orderBy('id')->get();

        $inSprint = $cards->whereNotNull('sprint_id')->where('type', '!=', 'subtask')->groupBy('sprint_id');
        $backlog = $cards->whereNull('sprint_id');

        $completed = Sprint::where('project_id', $project->id)->where('status', Sprint::COMPLETED)->orderByDesc('completed_at')->limit(5)->get();
        $velocity = $completed->take(3)->avg(fn (Sprint $s) => (float) $s->completed_points);

        return Inertia::render('Sprints/Index', [
            'project' => $this->header($project),
            'sprints' => $sprints->map(fn (Sprint $s) => $this->present($s, $inSprint->get($s->id, collect())))->values(),
            'backlog' => $backlog->take(self::BACKLOG_LIMIT)->map(fn (Task $t) => TaskPresenter::card($t))->values(),
            'backlogTotal' => $backlog->count(),
            'completed' => $completed->map(fn (Sprint $s) => [
                'id' => $s->id, 'name' => $s->name, 'end_date' => $s->end_date->toDateString(),
                'committed_points' => (float) $s->committed_points, 'completed_points' => (float) $s->completed_points,
            ])->values(),
            'velocity' => $velocity === null ? null : round($velocity, 1),
            'can' => [
                'create' => $me->hasPermission('sprint.create'),
                'edit' => $me->hasPermission('sprint.edit'),
                'start' => $me->hasPermission('sprint.start'),
                'complete' => $me->hasPermission('sprint.complete'),
                'delete' => $me->hasPermission('sprint.delete'),
            ],
            'canCreateTask' => Gate::allows('createTask', $project),
        ]);
    }

    public function store(Request $request, Project $project, SprintService $sprints): RedirectResponse
    {
        $this->authorizeProject($project, 'sprint.create');

        $sprints->create($project, $this->current->membership, $this->validated($request));

        return back()->with('success', 'Sprint created.');
    }

    public function update(Request $request, Sprint $sprint, SprintService $sprints): RedirectResponse
    {
        $this->authorizeProject($sprint->project, 'sprint.edit');

        $sprints->update($sprint, $this->validated($request));

        return back()->with('success', 'Sprint saved.');
    }

    public function destroy(Sprint $sprint, SprintService $sprints): RedirectResponse
    {
        $this->authorizeProject($sprint->project, 'sprint.delete');

        $sprints->delete($sprint);

        return back()->with('success', 'Sprint deleted. Its tasks are back in the backlog.');
    }

    public function start(Sprint $sprint, SprintService $sprints): RedirectResponse
    {
        $this->authorizeProject($sprint->project, 'sprint.start');

        $sprints->start($sprint);

        return back()->with('success', "{$sprint->name} has started.");
    }

    public function complete(Request $request, Sprint $sprint, SprintService $sprints): RedirectResponse
    {
        $this->authorizeProject($sprint->project, 'sprint.complete');

        $data = $request->validate(['next_sprint_id' => ['nullable', 'integer']]);
        $next = ! empty($data['next_sprint_id']) ? Sprint::where('project_id', $sprint->project_id)->where('id', $data['next_sprint_id'])->first() : null;
        if (! empty($data['next_sprint_id']) && ! $next) {
            return back()->withErrors(['next_sprint_id' => 'Choose a planned sprint of this project.']);
        }

        $summary = $sprints->complete($sprint, $next);

        $where = $summary['carried'] === 0 ? '' : ($next ? " {$summary['carried']} unfinished moved to {$next->name}." : " {$summary['carried']} unfinished back in the backlog.");

        return back()->with('success', "{$sprint->name} is complete: {$summary['completed']} done.{$where}");
    }

    /**
     * Drop a task in a sprint (or send sprint_id null to put it back in the backlog), optionally between two tasks.
     * Used by drag and drop on the planning page.
     */
    public function assign(Request $request, Task $task, SprintService $sprints, BoardService $board): JsonResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('view', $task);
        $project = Project::withTrashed()->findOrFail($task->project_id);
        $this->authorizeProject($project, 'sprint.edit');

        $data = $request->validate(['sprint_id' => ['nullable', 'integer'], 'prev_id' => ['nullable', 'integer'], 'next_id' => ['nullable', 'integer']]);

        if (! empty($data['sprint_id'])) {
            $sprint = Sprint::where('project_id', $project->id)->where('id', $data['sprint_id'])->first();
            abort_if($sprint === null, 404);
            $sprints->addTask($sprint, $task);
        } else {
            $sprints->removeTask($task);
        }

        if (array_key_exists('prev_id', $data) || array_key_exists('next_id', $data)) {
            $board->rank($task, $data['prev_id'] ?? null, $data['next_id'] ?? null);
        }

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'goal' => ['nullable', 'string', 'max:2000'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ]);
    }

    /** The viewer must see the project, hold the permission, and the project must run sprints (and not be an external's view). */
    private function authorizeProject(Project $project, string $permission): void
    {
        Gate::authorize('view', $project);
        Gate::authorize($permission);
        abort_if($this->current->membership->isExternal(), 403);
        abort_unless($project->uses_sprints, 404, 'This project works as a continuous board.');
    }

    /**
     * One sprint with its tasks and the load per person.
     *
     * @param  Collection<int, Task>  $tasks
     * @return array<string, mixed>
     */
    private function present(Sprint $sprint, Collection $tasks): array
    {
        $isDone = fn (Task $t) => $t->status->category === 'done';

        $people = $tasks->groupBy(fn (Task $t) => $t->assignee_id ?? 0)->map(function (Collection $mine, $id) {
            $first = $mine->first();

            return [
                'id' => (int) $id ?: null,
                'name' => $id ? $first->assignee->user->name : 'Unassigned',
                'tasks' => $mine->count(),
                'points' => round($mine->sum(fn (Task $t) => (float) $t->story_points), 1),
                'hours' => round($mine->sum(fn (Task $t) => (int) ($t->remaining_estimate_min ?? $t->original_estimate_min ?? 0)) / 60, 1),
            ];
        })->sortBy([['id', 'asc']])->sortBy(fn ($p) => $p['id'] === null ? 1 : 0)->values();

        return [
            'id' => $sprint->id,
            'name' => $sprint->name,
            'goal' => $sprint->goal,
            'status' => $sprint->status,
            'start_date' => $sprint->start_date->toDateString(),
            'end_date' => $sprint->end_date->toDateString(),
            'committed_points' => $sprint->committed_points === null ? null : (float) $sprint->committed_points,
            'points' => round($tasks->sum(fn (Task $t) => (float) $t->story_points), 1),
            'done_points' => round($tasks->filter($isDone)->sum(fn (Task $t) => (float) $t->story_points), 1),
            'hours' => round($tasks->sum(fn (Task $t) => (int) ($t->remaining_estimate_min ?? $t->original_estimate_min ?? 0)) / 60, 1),
            'task_count' => $tasks->count(),
            'done_count' => $tasks->filter($isDone)->count(),
            'people' => $people,
            'tasks' => $tasks->map(fn (Task $t) => TaskPresenter::card($t))->values(),
        ];
    }
}
