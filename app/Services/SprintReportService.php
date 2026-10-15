<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Sprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Numbers about sprints: the burndown, the velocity trend, and the end-of-sprint report. */
class SprintReportService
{
    /**
     * Points left in the sprint at the end of each day, against the straight line that would finish on time.
     * Scope added or removed along the way moves the actual line. `actual` is null for days still to come.
     *
     * @return array{days:list<array{date:string,ideal:float,actual:?float}>,committed:float,behind:bool}
     */
    public function burndown(Sprint $sprint): array
    {
        $rows = DB::table('sprint_task')->join('tasks', 'tasks.id', '=', 'sprint_task.task_id')
            ->where('sprint_task.sprint_id', $sprint->id)->whereNull('tasks.deleted_at')
            ->get(['sprint_task.added_at', 'sprint_task.removed_at', 'sprint_task.points_at_start', 'tasks.story_points', 'tasks.completed_at'])
            ->map(fn ($r) => [
                'points' => (float) ($r->points_at_start ?? $r->story_points ?? 0),
                'added' => Carbon::parse($r->added_at),
                'removed' => $r->removed_at ? Carbon::parse($r->removed_at) : null,
                'done' => $r->completed_at ? Carbon::parse($r->completed_at) : null,
            ]);

        $start = ($sprint->started_at ?? $sprint->start_date)->copy()->startOfDay();
        $end = $sprint->end_date->copy()->startOfDay();
        if ($end->lt($start)) {
            $end = $start->copy();
        }
        $last = $sprint->isCompleted() ? ($sprint->completed_at ?? $end)->copy()->startOfDay() : today();
        $committed = (float) ($sprint->committed_points ?? $rows->filter(fn ($r) => $r['removed'] === null)->sum('points'));
        $span = max(1, $start->diffInDays($end));

        $days = [];
        for ($d = $start->copy(), $i = 0; $d->lte($end); $d->addDay(), $i++) {
            $dayEnd = $d->copy()->endOfDay();
            $actual = null;

            if ($d->lte($last)) {
                $actual = $rows->filter(fn ($r) => $r['added']->lte($dayEnd) && ($r['removed'] === null || $r['removed']->gt($dayEnd)) && ! ($r['done'] && $r['done']->lte($dayEnd)))
                    ->sum('points');
            }

            $days[] = ['date' => $d->toDateString(), 'ideal' => round($committed * (1 - $i / $span), 1), 'actual' => $actual === null ? null : round($actual, 1)];
        }

        // Behind when today's remaining work is more than a fifth of the commitment above the line.
        $today = collect($days)->last(fn ($d) => $d['actual'] !== null);
        $behind = $sprint->isActive() && $committed > 0 && $today !== null && ($today['actual'] - $today['ideal']) > 0.2 * $committed;

        return ['days' => $days, 'committed' => round($committed, 1), 'behind' => $behind];
    }

    /**
     * Committed against completed points of the last finished sprints, oldest first.
     *
     * @return array{sprints:list<array{id:int,name:string,committed:float,completed:float}>,average:?float}
     */
    public function velocity(Project $project, int $limit = 8): array
    {
        $sprints = Sprint::where('project_id', $project->id)->where('status', Sprint::COMPLETED)->orderByDesc('completed_at')->limit($limit)->get()->reverse()->values();

        $rows = $sprints->map(fn (Sprint $s) => ['id' => $s->id, 'name' => $s->name, 'committed' => (float) $s->committed_points, 'completed' => (float) $s->completed_points])->all();

        return ['sprints' => $rows, 'average' => $rows ? round(collect($rows)->avg('completed'), 1) : null];
    }

    /**
     * What the sprint set out to do and what became of it.
     *
     * @return array<string, mixed>
     */
    public function report(Sprint $sprint): array
    {
        $rows = DB::table('sprint_task')->join('tasks', 'tasks.id', '=', 'sprint_task.task_id')
            ->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->where('sprint_task.sprint_id', $sprint->id)->whereNull('tasks.deleted_at')->where('tasks.type', '!=', 'subtask')
            ->get(['sprint_task.*', 'tasks.id as task_id', 'tasks.number', 'tasks.title', 'tasks.story_points', 'tasks.assignee_id', 'project_statuses.name as status', 'project_statuses.category']);

        $started = $sprint->started_at;
        $points = fn ($r) => (float) ($r->points_at_start ?? $r->story_points ?? 0);
        $addedLater = fn ($r) => $started !== null && Carbon::parse($r->added_at)->gt($started);

        $done = $rows->where('outcome', 'completed')->concat($rows->whereNull('outcome')->where('category', 'done'));

        return [
            'committed' => (float) ($sprint->committed_points ?? 0),
            'completed' => round($done->sum($points), 1),
            'tasks_total' => $rows->count(),
            'tasks_done' => $done->count(),
            'added' => $rows->filter($addedLater)->count(),
            'removed' => $rows->where('outcome', 'removed')->count(),
            'carried' => $rows->where('outcome', 'carried_over')->count(),
            'rate' => ($sprint->committed_points ?? 0) > 0 ? (int) round($done->sum($points) / (float) $sprint->committed_points * 100) : null,
            'rows' => $rows->map(fn ($r) => [
                'task_id' => $r->task_id, 'number' => $r->number, 'title' => $r->title, 'points' => $points($r),
                'status' => $r->status, 'outcome' => $r->outcome ?? ($r->category === 'done' ? 'completed' : 'in sprint'),
                'added_late' => $addedLater($r),
            ])->sortBy('number')->values()->all(),
        ];
    }
}
