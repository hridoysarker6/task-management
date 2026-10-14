<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Task;
use App\Support\TaskPresenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Team view: the open work of everyone below me in the reporting line, grouped by person. */
class TeamController extends Controller
{
    /** Tasks listed per person; the rest is a count. */
    private const PER_PERSON = 30;

    public function __invoke(Request $request, CurrentOrganization $current): Response
    {
        Gate::authorize('dashboard.team');

        $me = $current->membership;
        $includeMe = $request->boolean('include_me');
        $projectId = $request->integer('project_id') ?: null;

        // Everyone below me, at any depth, who still works here.
        $people = Membership::active()->where('user_type', 'internal')
            ->whereIn('id', DB::table('membership_hierarchy')->where('ancestor_id', $me->id)->where('depth', $includeMe ? '>=' : '>', 0)->select('descendant_id'))
            ->with(['user:id,name', 'role:id,name,level'])->get();

        $projects = Project::visibleTo($me)->where('status', '!=', Project::ARCHIVED)->orderBy('name')->get(['id', 'key', 'name']);

        // Only what the viewer is allowed to see, whatever project it is in.
        $tasks = Task::visibleTo($me)->whereIn('assignee_id', $people->pluck('id'))
            ->when($projectId && $projects->contains('id', $projectId), fn ($q) => $q->where('project_id', $projectId))
            ->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress']))
            ->with(['project', 'status', 'assignee.user:id,name'])
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('priority')->limit(2000)->get()
            ->groupBy('assignee_id');

        $rows = $people->map(function (Membership $p) use ($tasks) {
            $mine = $tasks->get($p->id, collect());

            return [
                'id' => $p->id,
                'name' => $p->user->name,
                'role' => $p->role->name,
                'level' => $p->role->level,
                'open' => $mine->count(),
                'in_progress' => $mine->filter(fn (Task $t) => $t->status->category === 'in_progress')->count(),
                'overdue' => $mine->filter(fn (Task $t) => $t->due_date !== null && $t->due_date->isPast() && ! $t->due_date->isToday())->count(),
                'tasks' => $mine->take(self::PER_PERSON)->map(fn (Task $t) => TaskPresenter::row($t))->values(),
                'more' => max(0, $mine->count() - self::PER_PERSON),
            ];
        })->sortBy([['open', 'desc'], ['name', 'asc']])->values();

        return Inertia::render('Team/Index', [
            'people' => $rows,
            'projects' => $projects,
            'filters' => ['project_id' => $projectId, 'include_me' => $includeMe],
            'totals' => ['people' => $rows->count(), 'open' => $rows->sum('open'), 'overdue' => $rows->sum('overdue')],
        ]);
    }
}
