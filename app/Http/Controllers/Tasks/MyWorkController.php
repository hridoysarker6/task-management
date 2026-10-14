<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Support\TaskPresenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** My Work: what is assigned to me, what I reported, and what I follow. */
class MyWorkController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current): Response
    {
        Gate::authorize('task.view');

        $me = $current->membership;
        $tab = $request->query('tab', 'assigned');
        abort_unless(in_array($tab, ['assigned', 'reported', 'watching'], true), 404);
        $includeDone = $request->boolean('done');

        $base = fn () => Task::visibleTo($me);
        $filter = fn ($q) => match ($tab) {
            'reported' => $q->where('reporter_id', $me->id),
            'watching' => $q->whereHas('watchers', fn ($w) => $w->where('memberships.id', $me->id)),
            default => $q->where('assignee_id', $me->id),
        };
        $open = fn ($q) => $includeDone ? $q : $q->whereHas('status', fn ($s) => $s->whereNotIn('category', ['done', 'cancelled']));

        $count = fn (string $which) => $base()->tap(fn ($q) => match ($which) {
            'reported' => $q->where('reporter_id', $me->id),
            'watching' => $q->whereHas('watchers', fn ($w) => $w->where('memberships.id', $me->id)),
            default => $q->where('assignee_id', $me->id),
        })->tap($open)->count();

        $tasks = $base()->tap($filter)->tap($open)
            ->with(['project', 'status', 'assignee.user:id,name'])
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('priority')->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (Task $t) => TaskPresenter::row($t, ! $me->isExternal()));

        return Inertia::render('Tasks/MyWork', [
            'tab' => $tab,
            'includeDone' => $includeDone,
            'tasks' => $tasks,
            'counts' => ['assigned' => $count('assigned'), 'reported' => $count('reported'), 'watching' => $count('watching')],
        ]);
    }
}
