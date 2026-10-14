<?php

namespace App\Http\Controllers\Tasks;

use App\Models\Project;
use App\Models\Task;
use App\Services\BulkTaskService;
use App\Services\TaskService;
use App\Support\TaskFilters;
use App\Support\TaskPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The list view of a project: sortable, filterable, paginated, with bulk actions. */
class TaskListController extends ProjectViewController
{
    public const PER_PAGE = [25, 50, 100];

    public function index(Request $request, Project $project, TaskService $tasks): Response
    {
        Gate::authorize('view', $project);
        Gate::authorize('task.view');

        $me = $this->current->membership;
        $filters = $this->cleanFilters($request);

        $sort = in_array($request->query('sort'), TaskFilters::SORTS, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $perPage = in_array($request->integer('per_page'), self::PER_PAGE, true) ? $request->integer('per_page') : 25;

        $query = TaskFilters::apply(Task::visibleTo($me)->where('project_id', $project->id), $filters, $me);

        $rows = TaskFilters::sort($query, $sort, $dir)
            ->with(['project', 'status', 'assignee.user:id,name', 'reporter.user:id,name', 'labels'])
            ->paginate($perPage)->withQueryString()
            ->through(fn (Task $t) => TaskPresenter::row($t, ! $me->isExternal()) + [
                'reporter' => ['id' => $t->reporter_id, 'name' => $t->reporter->user->name],
                'labels' => $t->labels->map->only(['id', 'name', 'color'])->values(),
                'created_at' => $t->created_at->toDateString(),
                'updated_at' => $t->updated_at?->toDateString(),
            ]);

        $canBulk = Gate::allows('task.bulk_update');

        return Inertia::render('Tasks/List', [
            'project' => $this->header($project),
            'tasks' => $rows,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE,
            'options' => $this->filterOptions($project),
            'savedFilters' => $this->savedFilters($project, 'list'),
            'canBulk' => $canBulk,
            'bulk' => $canBulk ? ['assignees' => $tasks->assigneeOptions($project, $me)] : null,
            'canCreate' => Gate::allows('createTask', $project),
            'showEffort' => ! $me->isExternal(),
        ]);
    }

    /** Apply one action to the ticked tasks. Per task, anything not allowed is reported and the rest still goes through. */
    public function bulk(Request $request, Project $project, BulkTaskService $bulk): RedirectResponse
    {
        Gate::authorize('view', $project);
        Gate::authorize('task.bulk_update');

        $data = $request->validate([
            'action' => ['required', Rule::in(BulkTaskService::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:'.BulkTaskService::MAX],
            'ids.*' => ['integer'],
            'assignee_id' => ['nullable', 'integer'],
            'status_id' => ['nullable', 'integer'],
            'label_id' => ['nullable', 'integer'],
            'close_children' => ['sometimes', 'boolean'],
            'with_children' => ['sometimes', 'boolean'],
        ]);

        $result = $bulk->apply($project, $this->current->membership, $data['ids'], $data['action'], $data);

        $message = $result['updated'].' task'.($result['updated'] === 1 ? '' : 's').' updated.';

        return back()->with($result['failed'] ? 'warning' : 'success', $result['failed'] ? $message.' '.count($result['failed']).' could not be changed.' : $message)
            ->with('bulk_failed', $result['failed']);
    }
}
