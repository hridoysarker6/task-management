<?php

namespace App\Http\Controllers\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Services\BoardService;
use App\Support\TaskFilters;
use App\Support\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The backlog: work that has not started, in the order the team wants to do it. */
class BacklogController extends ProjectViewController
{
    private const LIMIT = 500;

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        Gate::authorize('backlog.view');

        $me = $this->current->membership;
        $filters = $this->cleanFilters($request);

        // Not started (category "todo"); sub-tasks follow their parent and are not ranked on their own.
        // With sprints on, work already planned in a sprint lives on the sprint page instead.
        $query = TaskFilters::apply(
            Task::visibleTo($me)->where('project_id', $project->id)->where('type', '!=', 'subtask')
                ->when($project->uses_sprints, fn ($q) => $q->whereNull('sprint_id'))
                ->whereHas('status', fn ($s) => $s->where('category', 'todo')),
            $filters,
            $me,
        );

        $tasks = $query->with(['project', 'status', 'assignee.user:id,name', 'labels', 'parent.parent'])
            ->orderBy('backlog_rank')->orderBy('id')->limit(self::LIMIT + 1)->get();

        return Inertia::render('Tasks/Backlog', [
            'project' => $this->header($project),
            'tasks' => $tasks->take(self::LIMIT)->map(fn (Task $t) => TaskPresenter::card($t, ! $me->isExternal()))->values(),
            'truncated' => $tasks->count() > self::LIMIT,
            'filters' => $filters,
            'options' => $this->filterOptions($project),
            'canReorder' => ! $project->isArchived() && $me->hasPermission('backlog.reorder'),
            'canCreate' => Gate::allows('createTask', $project),
        ]);
    }

    /** Drop a task in the backlog: send the tasks above and below it. */
    public function rank(Request $request, Task $task, BoardService $board): JsonResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('view', $task);
        Gate::authorize('backlog.reorder');

        $project = Project::withTrashed()->findOrFail($task->project_id);
        abort_if($project->isArchived(), 403, 'This project is archived.');

        $data = $request->validate(['prev_id' => ['nullable', 'integer'], 'next_id' => ['nullable', 'integer']]);

        $task = $board->rank($task, $data['prev_id'] ?? null, $data['next_id'] ?? null);

        return response()->json(['ok' => true, 'rank' => (float) $task->backlog_rank]);
    }
}
