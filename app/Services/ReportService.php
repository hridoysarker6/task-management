<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The reports (WORKFLOW.md section 9). Each returns the same shape - columns and rows - so the page, the CSV / Excel
 * export and the print view all use one result. Who is included follows the viewer's data scope; former employees are
 * left out unless asked for.
 *
 * @phpstan-type Column array{key:string,label:string,type:string}
 */
class ReportService
{
    public const REPORTS = ['sprint', 'workload', 'overdue', 'time'];

    public const TITLES = ['sprint' => 'Sprint report', 'workload' => 'Workload', 'overdue' => 'Overdue tasks', 'time' => 'Time logged'];

    /** Days of time shown when no range is chosen. */
    private const DEFAULT_DAYS = 30;

    public function __construct(private SprintReportService $sprints) {}

    /**
     * Only known filters, correctly typed.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function clean(array $in): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;

        return array_filter([
            'project_id' => ($p = (int) ($in['project_id'] ?? 0)) > 0 ? $p : null,
            'sprint_id' => ($s = (int) ($in['sprint_id'] ?? 0)) > 0 ? $s : null,
            'person_id' => ($m = (int) ($in['person_id'] ?? 0)) > 0 ? $m : null,
            'from' => $date($in['from'] ?? null),
            'to' => $date($in['to'] ?? null),
            'group' => in_array($in['group'] ?? null, ['person', 'project'], true) ? $in['group'] : null,
            'include_former' => filter_var($in['include_former'] ?? false, FILTER_VALIDATE_BOOLEAN) ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $filters  cleaned
     * @return array{title:string,columns:list<array{key:string,label:string,type:string}>,rows:list<array<string,mixed>>,summary:array<string,mixed>,chart:?array<string,mixed>}
     *
     * @throws ValidationException
     */
    public function build(string $report, Membership $me, array $filters): array
    {
        return match ($report) {
            'sprint' => $this->sprint($me, $filters),
            'workload' => $this->workload($me, $filters),
            'overdue' => $this->overdue($me, $filters),
            'time' => $this->time($me, $filters),
            default => throw ValidationException::withMessages(['report' => 'Unknown report.']),
        };
    }

    // --- people in scope -------------------------------------------------------------------------

    /** @return Builder<Membership> */
    private function people(Membership $me, array $f): Builder
    {
        // Whoever the role's data scope reaches, plus everyone below the viewer in the reporting line (a department head
        // must still see a report who has no department set).
        $q = Membership::query()->where('user_type', 'internal');
        if ($me->role->data_scope !== 'organization') {
            $line = DB::table('membership_hierarchy')->where('ancestor_id', $me->id)->select('descendant_id');
            $q->where(fn ($w) => $w->where(fn ($s) => $s->visibleTo($me))->orWhereIn('memberships.id', $line));
        }

        // Former employees are soft-deleted memberships; leaving them out is the default.
        $f['include_former'] ?? false ? $q->withTrashed() : $q->where('status', Membership::ACTIVE);

        return $q->when($f['person_id'] ?? null, fn ($q, $id) => $q->where('memberships.id', $id));
    }

    private function project(Membership $me, array $f): ?Project
    {
        return isset($f['project_id']) ? Project::visibleTo($me)->find($f['project_id']) : null;
    }

    // --- reports ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function workload(Membership $me, array $f): array
    {
        $people = $this->people($me, $f)->with(['user:id,name', 'role:id,name'])->get()->keyBy('id');
        $project = $this->project($me, $f);

        $rows = Task::visibleTo($me)->toBase()->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->whereIn('tasks.assignee_id', $people->keys())->where('tasks.type', '!=', 'epic')
            ->whereIn('project_statuses.category', ['todo', 'in_progress'])
            ->when($project, fn ($q) => $q->where('tasks.project_id', $project->id))
            ->groupBy('tasks.assignee_id')->selectRaw(
                'tasks.assignee_id as id, count(*) as open, '.
                "sum(case when project_statuses.category = 'in_progress' then 1 else 0 end) as in_progress, ".
                "sum(case when project_statuses.name = 'Blocked' then 1 else 0 end) as blocked, ".
                'sum(case when tasks.due_date < ? then 1 else 0 end) as overdue, '.
                'coalesce(sum(tasks.story_points), 0) as points, coalesce(sum(tasks.remaining_estimate_min), 0) as remaining',
                [today()->toDateString()],
            )->get()->keyBy('id');

        $out = $people->map(fn (Membership $p) => [
            'person' => $p->user->name.($p->deleted_at ? ' (Former)' : ''),
            'role' => $p->role->name,
            'open' => (int) ($rows[$p->id]->open ?? 0),
            'in_progress' => (int) ($rows[$p->id]->in_progress ?? 0),
            'blocked' => (int) ($rows[$p->id]->blocked ?? 0),
            'overdue' => (int) ($rows[$p->id]->overdue ?? 0),
            'points' => (float) ($rows[$p->id]->points ?? 0),
            'hours' => round(((int) ($rows[$p->id]->remaining ?? 0)) / 60, 1),
        ])->sortByDesc('open')->values();

        return [
            'title' => self::TITLES['workload'].($project ? " · {$project->key}" : ''),
            'columns' => [
                $this->col('person', 'Person'), $this->col('role', 'Role'), $this->col('open', 'Open tasks', 'number'), $this->col('in_progress', 'In progress', 'number'),
                $this->col('blocked', 'Blocked', 'number'), $this->col('overdue', 'Overdue', 'number'), $this->col('points', 'Open points', 'number'), $this->col('hours', 'Hours left', 'number'),
            ],
            'rows' => $out->all(),
            'summary' => ['People' => $out->count(), 'Open tasks' => $out->sum('open'), 'Overdue' => $out->sum('overdue'), 'Blocked' => $out->sum('blocked')],
            'chart' => ['type' => 'bars', 'items' => $out->take(15)->map(fn ($r) => ['label' => $r['person'], 'value' => $r['open'], 'extra' => $r['overdue']])->values()->all()],
        ];
    }

    /** @return array<string, mixed> */
    private function overdue(Membership $me, array $f): array
    {
        $project = $this->project($me, $f);
        $active = Membership::where('status', Membership::ACTIVE)->select('memberships.id');

        $tasks = Task::visibleTo($me)->where('tasks.type', '!=', 'epic')
            ->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', today())
            ->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress']))
            ->when($project, fn ($q) => $q->where('tasks.project_id', $project->id))
            ->when($f['person_id'] ?? null, fn ($q, $id) => $q->where('tasks.assignee_id', $id))
            ->when(! ($f['include_former'] ?? false), fn ($q) => $q->where(fn ($w) => $w->whereNull('tasks.assignee_id')->orWhereIn('tasks.assignee_id', $active)))
            ->with(['project', 'status', 'assignee.user:id,name'])->orderBy('tasks.due_date')->limit(1000)->get();

        $rows = $tasks->map(fn (Task $t) => [
            'key' => $t->key_label, 'title' => $t->title, 'project' => $t->project->name, 'assignee' => $t->assignee ? $t->assignee->user->name : 'Unassigned',
            'status' => $t->status->name, 'priority' => Task::PRIORITIES[$t->priority], 'due' => $t->due_date->toDateString(),
            'days_late' => (int) $t->due_date->diffInDays(today()),
        ])->values();

        return [
            'title' => self::TITLES['overdue'].($project ? " · {$project->key}" : ''),
            'columns' => [
                $this->col('key', 'Task'), $this->col('title', 'Title'), $this->col('project', 'Project'), $this->col('assignee', 'Assignee'), $this->col('status', 'Status'),
                $this->col('priority', 'Priority'), $this->col('due', 'Due', 'date'), $this->col('days_late', 'Days late', 'number'),
            ],
            'rows' => $rows->all(),
            'summary' => ['Overdue tasks' => $rows->count(), 'Longest overdue (days)' => (int) $rows->max('days_late')],
            'chart' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function time(Membership $me, array $f): array
    {
        $from = Carbon::parse($f['from'] ?? today()->subDays(self::DEFAULT_DAYS))->startOfDay();
        $to = Carbon::parse($f['to'] ?? today())->startOfDay();
        $group = $f['group'] ?? 'person';
        $project = $this->project($me, $f);
        $people = $this->people($me, $f)->pluck('memberships.id');

        $query = DB::table('work_logs')->join('tasks', 'tasks.id', '=', 'work_logs.task_id')->leftJoin('timesheets', 'timesheets.id', '=', 'work_logs.timesheet_id')
            ->where('work_logs.organization_id', $me->organization_id)->whereNull('work_logs.deleted_at')
            ->whereIn('work_logs.membership_id', $people)->whereBetween('work_logs.work_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->when($project, fn ($q) => $q->where('tasks.project_id', $project->id));

        $key = $group === 'project' ? 'tasks.project_id' : 'work_logs.membership_id';
        $sums = $query->groupBy($key)->selectRaw(
            "{$key} as id, sum(work_logs.minutes) as total, ".
            "sum(case when timesheets.status = 'approved' then work_logs.minutes else 0 end) as approved, ".
            "sum(case when timesheets.status = 'submitted' then work_logs.minutes else 0 end) as submitted, ".
            'count(distinct work_logs.task_id) as tasks',
        )->get();

        $names = $group === 'project'
            ? Project::withTrashed()->whereIn('id', $sums->pluck('id'))->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->key} · {$p->name}"])
            : Membership::withTrashed()->whereIn('memberships.id', $sums->pluck('id'))->with('user:id,name')->get()->mapWithKeys(fn ($m) => [$m->id => $m->user->name.($m->deleted_at ? ' (Former)' : '')]);

        $hours = fn ($m) => round(((int) $m) / 60, 1);
        $rows = $sums->map(fn ($r) => [
            'name' => $names[$r->id] ?? '—', 'total' => $hours($r->total), 'approved' => $hours($r->approved), 'submitted' => $hours($r->submitted),
            'draft' => $hours($r->total - $r->approved - $r->submitted), 'tasks' => (int) $r->tasks,
        ])->sortByDesc('total')->values();

        return [
            'title' => self::TITLES['time'].' · '.$from->format('j M').' – '.$to->format('j M Y'),
            'columns' => [
                $this->col('name', $group === 'project' ? 'Project' : 'Person'), $this->col('total', 'Hours logged', 'number'), $this->col('approved', 'Approved', 'number'),
                $this->col('submitted', 'Waiting for approval', 'number'), $this->col('draft', 'Not submitted', 'number'), $this->col('tasks', 'Tasks', 'number'),
            ],
            'rows' => $rows->all(),
            'summary' => ['Hours logged' => round($rows->sum('total'), 1), 'Approved' => round($rows->sum('approved'), 1), 'Waiting' => round($rows->sum('submitted'), 1)],
            'chart' => ['type' => 'bars', 'items' => $rows->take(15)->map(fn ($r) => ['label' => $r['name'], 'value' => $r['total'], 'extra' => 0])->values()->all()],
        ];
    }

    /** @return array<string, mixed> */
    private function sprint(Membership $me, array $f): array
    {
        $project = $this->project($me, $f);
        $sprint = null;

        if ($project) {
            $sprint = isset($f['sprint_id'])
                ? Sprint::where('project_id', $project->id)->where('id', $f['sprint_id'])->first()
                : (Sprint::where('project_id', $project->id)->where('status', Sprint::ACTIVE)->first()
                    ?? Sprint::where('project_id', $project->id)->where('status', Sprint::COMPLETED)->latest('completed_at')->first());
        }

        $columns = [$this->col('key', 'Task'), $this->col('title', 'Title'), $this->col('status', 'Status'), $this->col('points', 'Points', 'number'), $this->col('outcome', 'Outcome'), $this->col('late', 'Added mid-sprint')];

        if (! $project || ! $sprint) {
            return ['title' => self::TITLES['sprint'], 'columns' => $columns, 'rows' => [], 'summary' => [], 'chart' => null];
        }

        $report = $this->sprints->report($sprint);
        $visible = Task::visibleTo($me)->where('tasks.project_id', $project->id)->pluck('tasks.id')->flip();

        $rows = collect($report['rows'])->filter(fn ($r) => $visible->has($r['task_id']))->map(fn ($r) => [
            'key' => $project->key.'-'.$r['number'], 'title' => $r['title'], 'status' => $r['status'], 'points' => $r['points'],
            'outcome' => ucfirst(str_replace('_', ' ', $r['outcome'])), 'late' => $r['added_late'] ? 'Yes' : '',
        ])->values();

        return [
            'title' => self::TITLES['sprint']." · {$sprint->name}",
            'columns' => $columns,
            'rows' => $rows->all(),
            'summary' => [
                'Committed points' => $report['committed'], 'Completed points' => $report['completed'], 'Completion' => $report['rate'] === null ? '—' : $report['rate'].'%',
                'Added mid-sprint' => $report['added'], 'Carried over' => $report['carried'], 'Removed' => $report['removed'],
            ],
            'chart' => [
                'type' => 'sprint', 'sprint' => ['id' => $sprint->id, 'name' => $sprint->name, 'status' => $sprint->status],
                'burndown' => $this->sprints->burndown($sprint), 'velocity' => $this->sprints->velocity($project),
            ],
        ];
    }

    /** @return array{key:string,label:string,type:string} */
    private function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
