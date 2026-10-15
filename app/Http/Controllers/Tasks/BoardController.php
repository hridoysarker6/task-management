<?php

namespace App\Http\Controllers\Tasks;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Sprint;
use App\Models\Task;
use App\Services\BoardService;
use App\Support\TaskFilters;
use App\Support\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** The Kanban board of a project: columns are statuses, cards are tasks, dragging a card moves it. */
class BoardController extends ProjectViewController
{
    /** Cards shown at most; beyond this the person is asked to filter. */
    private const LIMIT = 500;

    public function index(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);
        Gate::authorize('task.view');

        $me = $this->current->membership;
        $filters = $this->cleanFilters($request);
        $swimlane = in_array($request->query('swimlane'), ['assignee', 'epic'], true) ? $request->query('swimlane') : 'none';

        $columns = $project->statuses()->where('show_on_board', true)->get(['id', 'name', 'color', 'category']);

        $base = Task::visibleTo($me)->where('project_id', $project->id)->whereIn('status_id', $columns->pluck('id'));

        // With sprints on, the board is the active sprint (nothing when none is running); Kanban projects show everything.
        $sprint = $project->uses_sprints ? Sprint::where('project_id', $project->id)->where('status', Sprint::ACTIVE)->first() : null;
        if ($project->uses_sprints) {
            $sprint ? $base->where('sprint_id', $sprint->id) : $base->whereRaw('1 = 0');
        }

        $query = TaskFilters::apply($base, $filters, $me);

        $cards = $query->with(['project', 'status', 'assignee.user:id,name', 'labels', 'parent.parent'])
            ->orderBy('board_position')->orderBy('id')->limit(self::LIMIT + 1)->get();

        $truncated = $cards->count() > self::LIMIT;
        $cards = $cards->take(self::LIMIT)->map(fn (Task $t) => TaskPresenter::card($t, ! $me->isExternal()))->values();

        return Inertia::render('Tasks/Board', [
            'project' => $this->header($project),
            'columns' => $columns,
            'tasks' => $cards,
            'lanes' => $this->lanes($swimlane, $cards),
            'swimlane' => $swimlane,
            'sprint' => $sprint ? [
                'id' => $sprint->id, 'name' => $sprint->name, 'goal' => $sprint->goal,
                'end_date' => $sprint->end_date->toDateString(), 'days_left' => (int) today()->diffInDays($sprint->end_date, false),
            ] : null,
            'filters' => $filters,
            'truncated' => $truncated,
            'options' => $this->filterOptions($project),
            'savedFilters' => $this->savedFilters($project, 'board'),
            'canMove' => $me->hasPermission('task.change_status') || $me->hasPermission('task.close') || $me->hasPermission('task.reopen'),
            'canCreate' => Gate::allows('createTask', $project),
            'showEffort' => ! $me->isExternal(),
        ]);
    }

    /** Drop a card: send the column it landed in and the cards above and below it there. */
    public function move(Request $request, Task $task, BoardService $board): JsonResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('changeStatus', $task);

        $data = $request->validate([
            'status_id' => ['required', 'integer'],
            'prev_id' => ['nullable', 'integer'],
            'next_id' => ['nullable', 'integer'],
            'close_children' => ['sometimes', 'boolean'],
        ]);

        $to = ProjectStatus::where('project_id', $task->project_id)->findOrFail($data['status_id']);

        $task = $board->moveCard($task, $to, $data['prev_id'] ?? null, $data['next_id'] ?? null, $this->current->membership, (bool) ($data['close_children'] ?? false));

        return response()->json(['ok' => true, 'status_id' => $task->status_id, 'position' => (float) $task->board_position]);
    }

    /**
     * The rows of the board when it is split into swimlanes.
     *
     * @param  Collection<int, array<string, mixed>>  $cards
     * @return list<array{key:string|int,label:string,sub:?string}>
     */
    private function lanes(string $swimlane, $cards): array
    {
        if ($swimlane === 'none') {
            return [];
        }

        $lanes = $swimlane === 'assignee'
            ? $cards->filter(fn ($c) => $c['assignee'])->unique(fn ($c) => $c['assignee']['id'])->sortBy(fn ($c) => $c['assignee']['name'])
                ->map(fn ($c) => ['key' => $c['assignee']['id'], 'label' => $c['assignee']['name'], 'sub' => null])
            : $cards->filter(fn ($c) => $c['epic'])->unique(fn ($c) => $c['epic']['id'])->sortBy(fn ($c) => $c['epic']['key'])
                ->map(fn ($c) => ['key' => $c['epic']['id'], 'label' => $c['epic']['title'], 'sub' => $c['epic']['key']]);

        // Tasks with nobody assigned / no epic share the last lane.
        return [...$lanes->values()->all(), ['key' => 'none', 'label' => $swimlane === 'assignee' ? 'Unassigned' : 'No epic', 'sub' => null]];
    }
}
