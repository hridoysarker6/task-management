<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\Timesheet;
use App\Support\TaskPresenter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The widgets of the home dashboard. What a person gets follows what they may do and see, not their role's name:
 * everyone has "my work"; people with reports get the team and the roll-up of each report; people who see several
 * projects get project health and the status mix; clients get their requests; admins get company numbers.
 * The heavy widgets are cached for five minutes per person.
 */
class DashboardService
{
    /** Seconds the heavy widgets stay cached. */
    public const CACHE_SECONDS = 300;

    public function __construct(private ProgressService $progress, private ProjectHealthService $health, private SprintReportService $sprints) {}

    /** @return array<string, mixed> */
    public function build(Membership $me): array
    {
        $external = $me->isExternal();
        $reports = $external ? 0 : $this->reportIds($me)->count();

        $widgets = ['layout' => $this->layout($me, $reports), 'my' => $this->mine($me)];

        if ($external) {
            return $widgets + ['requests' => $this->requests($me)];
        }

        if ($reports > 0 && $me->hasPermission('dashboard.team')) {
            $widgets['team'] = $this->team($me);
            $widgets['rollup'] = $this->cached($me, 'rollup', fn () => $this->rollup($me));
        }

        $widgets['projects'] = $this->cached($me, 'projects', fn () => $this->projects($me));
        $widgets['status'] = $this->cached($me, 'status', fn () => $this->statusMix($me));
        $widgets['velocity'] = $this->cached($me, 'velocity', fn () => $this->velocity($me));

        if ($me->hasPermission('dashboard.organization')) {
            $widgets['company'] = $this->cached($me, 'company', fn () => $this->company($me));
        }

        if ($me->hasPermission('task.triage')) {
            $widgets['triage'] = $this->triage($me);
        }

        return $widgets;
    }

    /** Everyone below a person in the reporting line (any depth), still employed. @return \Illuminate\Support\Collection<int, int> */
    public function reportIds(Membership $me, bool $directOnly = false)
    {
        return DB::table('membership_hierarchy')->join('memberships', 'memberships.id', '=', 'membership_hierarchy.descendant_id')
            ->where('membership_hierarchy.ancestor_id', $me->id)->where('membership_hierarchy.depth', $directOnly ? '=' : '>', $directOnly ? 1 : 0)
            ->where('memberships.status', Membership::ACTIVE)->whereNull('memberships.deleted_at')->pluck('memberships.id');
    }

    /** A name for the arrangement, so the page can greet people sensibly. */
    private function layout(Membership $me, int $reports): string
    {
        return match (true) {
            $me->isExternal() => 'stakeholder',
            $me->hasPermission('dashboard.organization') => 'company',
            $me->hasPermission('dashboard.department') => 'executive',
            $reports > 0 && $this->reportIds($me, true)->contains(fn ($id) => $this->reportIds(Membership::findOrFail($id))->isNotEmpty()) => 'manager',
            $reports > 0 => 'lead',
            default => 'worker',
        };
    }

    /** @param callable():array<string, mixed>|callable():mixed $build */
    private function cached(Membership $me, string $name, callable $build): mixed
    {
        return Cache::remember("dashboard:{$me->organization_id}:{$me->id}:{$name}", self::CACHE_SECONDS, $build);
    }

    // --- widgets ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function mine(Membership $me): array
    {
        $open = fn () => Task::visibleTo($me)->where('tasks.assignee_id', $me->id)->where('tasks.type', '!=', 'epic')
            ->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress']));

        $tasks = $open()->with(['project', 'status', 'assignee.user:id,name'])->orderByRaw('tasks.due_date is null')->orderBy('tasks.due_date')->orderByDesc('tasks.priority')->limit(8)->get();

        $weekStart = Timesheet::weekStartOf(today());
        $minutes = (int) DB::table('work_logs')->where('organization_id', $me->organization_id)->where('membership_id', $me->id)->whereNull('deleted_at')
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])->sum('minutes');

        $sprints = Sprint::where('status', Sprint::ACTIVE)->whereIn('project_id', DB::table('project_members')->where('membership_id', $me->id)->whereNull('left_at')->select('project_id'))
            ->with('project')->get()->map(fn (Sprint $s) => [
                'id' => $s->id, 'name' => $s->name, 'project' => ['id' => $s->project->id, 'key' => $s->project->key],
                'percent' => $this->progress->forSprint($s, $me)['percent'], 'days_left' => (int) today()->diffInDays($s->end_date, false),
            ])->values();

        return [
            'open' => $open()->count(),
            'due_soon' => $open()->whereBetween('tasks.due_date', [today()->toDateString(), today()->addDays(7)->toDateString()])->count(),
            'overdue' => $open()->whereDate('tasks.due_date', '<', today())->count(),
            'tasks' => $tasks->map(fn (Task $t) => TaskPresenter::row($t, ! $me->isExternal()))->values(),
            'week_minutes' => $minutes,
            'sprints' => $sprints,
        ];
    }

    /** @return array<string, mixed> */
    private function team(Membership $me): array
    {
        $ids = $this->reportIds($me);

        $rows = DB::table('tasks')->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->where('tasks.organization_id', $me->organization_id)->whereNull('tasks.deleted_at')->whereIn('tasks.assignee_id', $ids)->where('tasks.type', '!=', 'epic')
            ->whereIn('project_statuses.category', ['todo', 'in_progress', 'done'])
            ->groupBy('tasks.assignee_id')->selectRaw(
                "tasks.assignee_id as id, sum(case when project_statuses.category = 'todo' then 1 else 0 end) as todo, ".
                "sum(case when project_statuses.category = 'in_progress' then 1 else 0 end) as in_progress, ".
                "sum(case when project_statuses.category = 'done' then 1 else 0 end) as done, ".
                "sum(case when project_statuses.category != 'done' and tasks.due_date < ? then 1 else 0 end) as overdue",
                [today()->toDateString()],
            )->get()->keyBy('id');

        $people = Membership::whereIn('id', $ids)->with('user:id,name')->get()->map(fn (Membership $p) => [
            'id' => $p->id, 'name' => $p->user->name,
            'todo' => (int) ($rows[$p->id]->todo ?? 0), 'in_progress' => (int) ($rows[$p->id]->in_progress ?? 0),
            'done' => (int) ($rows[$p->id]->done ?? 0), 'overdue' => (int) ($rows[$p->id]->overdue ?? 0),
        ])->sortByDesc(fn ($r) => $r['todo'] + $r['in_progress'])->values();

        // What needs a lead's eye: overdue or blocked work of the team.
        $attention = Task::visibleTo($me)->whereIn('tasks.assignee_id', $ids)->where('tasks.type', '!=', 'epic')
            ->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress']))
            ->where(fn ($q) => $q->whereDate('tasks.due_date', '<', today())->orWhereHas('status', fn ($s) => $s->where('name', 'Blocked')))
            ->with(['project', 'status', 'assignee.user:id,name'])->orderBy('tasks.due_date')->limit(10)->get()
            ->map(fn (Task $t) => TaskPresenter::row($t))->values();

        return [
            'people' => $people,
            'attention' => $attention,
            'pending_timesheets' => $me->hasPermission('timesheet.approve')
                ? Timesheet::where('status', Timesheet::SUBMITTED)->whereIn('membership_id', $ids)->count() : null,
        ];
    }

    /** One line per direct report: how their whole line is doing. @return list<array<string, mixed>> */
    private function rollup(Membership $me): array
    {
        return Membership::whereIn('id', $this->reportIds($me, true))->with(['user:id,name', 'role:id,name'])->get()
            ->map(fn (Membership $d) => ['id' => $d->id, 'name' => $d->user->name, 'role' => $d->role->name] + $this->lineStats($d, $me))
            ->sortByDesc('open')->values()->all();
    }

    /**
     * How a person and everyone below them are doing: tasks assigned anywhere in that line.
     *
     * @return array{people:int,percent:int,open:int,overdue:int,blocked:int,done:int}
     */
    public function lineStats(Membership $person, Membership $viewer): array
    {
        $line = DB::table('membership_hierarchy')->where('ancestor_id', $person->id)->pluck('descendant_id'); // includes themself

        $row = Task::visibleTo($viewer)->toBase()->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->whereIn('tasks.assignee_id', $line)->where('tasks.type', '!=', 'epic')->whereIn('project_statuses.category', ['todo', 'in_progress', 'done'])
            ->selectRaw(
                "sum(case when project_statuses.category != 'done' then 1 else 0 end) as open, ".
                "sum(case when project_statuses.category = 'done' then 1 else 0 end) as done, ".
                "sum(case when project_statuses.category != 'done' and tasks.due_date < ? then 1 else 0 end) as overdue, ".
                "sum(case when project_statuses.category != 'done' and project_statuses.name = 'Blocked' then 1 else 0 end) as blocked",
                [today()->toDateString()],
            )->first();

        return [
            'people' => max(0, $line->count() - 1),
            'percent' => $this->progress->forTeam($person, true, $viewer)['percent'],
            'open' => (int) $row->open, 'overdue' => (int) $row->overdue, 'blocked' => (int) $row->blocked, 'done' => (int) $row->done,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function projects(Membership $me): array
    {
        return Project::visibleTo($me)->where('status', Project::ACTIVE)->with('manager.user:id,name')->orderBy('name')->limit(12)->get()
            ->map(fn (Project $p) => ['id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'manager' => $p->manager?->user?->name, 'health' => $this->health->assess($p, $me)])
            ->values()->all();
    }

    /** Tasks of the projects the viewer sees, by status category. @return list<array{category:string,label:string,count:int}> */
    private function statusMix(Membership $me): array
    {
        $labels = ['todo' => 'To do', 'in_progress' => 'In progress', 'done' => 'Done'];

        $counts = Task::visibleTo($me)->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->whereIn('tasks.project_id', Project::visibleTo($me)->where('status', Project::ACTIVE)->select('projects.id'))
            ->where('tasks.type', '!=', 'epic')->whereIn('project_statuses.category', array_keys($labels))
            ->toBase()->groupBy('project_statuses.category')->selectRaw('project_statuses.category as category, count(*) as n')->pluck('n', 'category');

        return collect($labels)->map(fn ($label, $key) => ['category' => $key, 'label' => $label, 'count' => (int) ($counts[$key] ?? 0)])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function velocity(Membership $me): array
    {
        return Project::visibleTo($me)->where('status', Project::ACTIVE)->where('uses_sprints', true)->orderBy('name')->limit(3)->get()
            ->map(fn (Project $p) => ['id' => $p->id, 'key' => $p->key, 'name' => $p->name] + $this->sprints->velocity($p, 6))
            ->filter(fn ($v) => $v['sprints'] !== [])->values()->all();
    }

    /** @return array<string, mixed> */
    private function company(Membership $me): array
    {
        $health = collect($this->cachedProjectsFor($me))->countBy(fn ($p) => $p['health']['status']);

        return [
            'people' => Membership::where('status', Membership::ACTIVE)->count(),
            'projects' => Project::where('status', Project::ACTIVE)->count(),
            'open_tasks' => Task::whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress']))->where('type', '!=', 'epic')->count(),
            'health' => ['on_track' => (int) ($health['on_track'] ?? 0), 'at_risk' => (int) ($health['at_risk'] ?? 0), 'delayed' => (int) ($health['delayed'] ?? 0)],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function cachedProjectsFor(Membership $me): array
    {
        return $this->cached($me, 'projects', fn () => $this->projects($me));
    }

    /** @return array<string, mixed> */
    private function triage(Membership $me): array
    {
        return ['count' => Task::visibleTo($me)->whereHas('status', fn ($s) => $s->where('category', 'triage'))->count()];
    }

    /** What a client raised and where their projects stand. @return array<string, mixed> */
    private function requests(Membership $me): array
    {
        $byStatus = Task::visibleTo($me)->where('tasks.reporter_id', $me->id)->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->toBase()->groupBy('project_statuses.name', 'project_statuses.color', 'project_statuses.category')
            ->selectRaw('project_statuses.name as name, project_statuses.color as color, project_statuses.category as category, count(*) as n')->get();

        $projects = Project::visibleTo($me)->where('status', '!=', Project::ARCHIVED)->orderBy('name')->get()
            ->map(fn (Project $p) => ['id' => $p->id, 'key' => $p->key, 'name' => $p->name, 'percent' => $this->progress->forProject($p, $me)['percent']])->values();

        return [
            'by_status' => $byStatus->map(fn ($r) => ['name' => $r->name, 'color' => $r->color, 'category' => $r->category, 'count' => (int) $r->n])->values(),
            'projects' => $projects,
        ];
    }
}
