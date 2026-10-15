<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Task;
use App\Services\DashboardService;
use App\Support\TaskPresenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drill-down: from yourself, down the reporting line one level at a time (HOD > DGM > AGM > DM > Team Lead > developer),
 * and from any number to the tasks behind it.
 */
class DrilldownController extends Controller
{
    private const SHOWS = ['open', 'overdue', 'blocked', 'done', 'all'];

    /** Tasks listed at most. */
    private const LIMIT = 100;

    public function __construct(private CurrentOrganization $current, private DashboardService $dashboard) {}

    public function __invoke(Request $request, ?Membership $membership = null): Response
    {
        $me = $this->current->membership;
        $target = $membership ?? $me;

        // Yourself is always fine; anyone else must be below you in the reporting line.
        if ($target->id !== $me->id) {
            Gate::authorize('dashboard.team');
            abort_unless(DB::table('membership_hierarchy')->where('ancestor_id', $me->id)->where('descendant_id', $target->id)->exists(), 404);
        }

        $target->loadMissing('user:id,name', 'role:id,name');
        $show = in_array($request->query('show'), self::SHOWS, true) ? $request->query('show') : 'open';
        $line = DB::table('membership_hierarchy')->where('ancestor_id', $target->id)->pluck('descendant_id');

        // The chain from me down to the person shown.
        $trail = DB::table('membership_hierarchy')->where('descendant_id', $target->id)
            ->whereIn('ancestor_id', DB::table('membership_hierarchy')->where('ancestor_id', $me->id)->select('descendant_id'))
            ->orderByDesc('depth')->pluck('ancestor_id');
        $names = Membership::withTrashed()->whereIn('memberships.id', $trail)->with('user:id,name')->get()->keyBy('id');

        $tasks = Task::visibleTo($me)->whereIn('tasks.assignee_id', $line)->where('tasks.type', '!=', 'epic')
            ->when($show === 'open', fn ($q) => $q->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress'])))
            ->when($show === 'overdue', fn ($q) => $q->whereDate('tasks.due_date', '<', today())->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress'])))
            ->when($show === 'blocked', fn ($q) => $q->whereHas('status', fn ($s) => $s->where('name', 'Blocked')))
            ->when($show === 'done', fn ($q) => $q->whereHas('status', fn ($s) => $s->where('category', 'done')))
            ->when($show === 'all', fn ($q) => $q->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress', 'done'])))
            ->with(['project', 'status', 'assignee.user:id,name'])->orderBy('tasks.due_date')->orderByDesc('tasks.priority')->limit(self::LIMIT + 1)->get();

        $reports = Membership::whereIn('id', $this->dashboard->reportIds($target, true))->with(['user:id,name', 'role:id,name'])->get()
            ->map(fn (Membership $d) => ['id' => $d->id, 'name' => $d->user->name, 'role' => $d->role->name] + $this->dashboard->lineStats($d, $me))
            ->sortByDesc('open')->values();

        return Inertia::render('Reports/Drilldown', [
            'person' => ['id' => $target->id, 'name' => $target->user->name, 'role' => $target->role->name, 'is_me' => $target->id === $me->id] + $this->dashboard->lineStats($target, $me),
            'trail' => $trail->map(fn ($id) => ['id' => $id, 'name' => $names[$id]->user->name, 'is_me' => $id === $me->id])->values(),
            'reports' => $reports,
            'show' => $show,
            'tasks' => $tasks->take(self::LIMIT)->map(fn (Task $t) => TaskPresenter::row($t, ! $me->isExternal()))->values(),
            'truncated' => $tasks->count() > self::LIMIT,
        ]);
    }
}
