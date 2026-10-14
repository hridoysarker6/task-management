<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * How far along a set of tasks is: done / total, ignoring cancelled and triage tasks (WORKFLOW.md section 9).
 * Counted by number of tasks or by story points, per the project's progress mode.
 */
class ProgressService
{
    /** @return array{done:int|float,total:int|float,percent:int,mode:string} */
    public function forEpic(Task $epic, Project $project): array
    {
        $rows = DB::table('tasks')
            ->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->where('tasks.parent_id', $epic->id)->whereNull('tasks.deleted_at')
            ->whereNotIn('project_statuses.category', ['cancelled', 'triage'])
            ->get(['tasks.story_points', 'project_statuses.category']);

        return $this->summarise($rows->map(fn ($r) => [$r->category === 'done', (float) ($r->story_points ?? 0)]), $project->progress_mode ?? 'count');
    }

    /**
     * @param  iterable<int, array{0:bool,1:float}>  $items  [isDone, points]
     * @return array{done:int|float,total:int|float,percent:int,mode:string}
     */
    private function summarise(iterable $items, string $mode): array
    {
        $done = 0;
        $total = 0;

        foreach ($items as [$isDone, $points]) {
            $weight = $mode === 'points' ? $points : 1;
            $total += $weight;
            $done += $isDone ? $weight : 0;
        }

        return ['done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int) round($done / $total * 100) : 0, 'mode' => $mode];
    }
}
