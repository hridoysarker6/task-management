<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;

/**
 * Is a project on track? (WORKFLOW.md section 9)
 *   Delayed: past its end date and not finished.
 *   At risk: more than a fifth of its open tasks are overdue, or the running sprint's burndown is behind.
 *   Otherwise on track.
 */
class ProjectHealthService
{
    public const ON_TRACK = 'on_track';

    public const AT_RISK = 'at_risk';

    public const DELAYED = 'delayed';

    /** Share of open tasks that may be overdue before the project is "at risk". */
    public const OVERDUE_LIMIT = 0.2;

    public function __construct(private ProgressService $progress, private SprintReportService $sprints) {}

    /**
     * @return array{status:string,label:string,reasons:list<string>,overdue:int,open:int,overdue_percent:int,progress:int}
     */
    public function assess(Project $project, ?Membership $viewer = null): array
    {
        $progress = $this->progress->forProject($project, $viewer);

        $open = ($viewer ? Task::visibleTo($viewer) : Task::query())->where('tasks.project_id', $project->id)->where('tasks.type', '!=', 'epic')
            ->whereHas('status', fn ($s) => $s->whereNotIn('category', ['done', 'cancelled', 'triage']));
        $openCount = (clone $open)->count();
        $overdue = (clone $open)->whereNotNull('tasks.due_date')->whereDate('tasks.due_date', '<', today())->count();
        $overduePercent = $openCount > 0 ? (int) round($overdue / $openCount * 100) : 0;

        $status = self::ON_TRACK;
        $reasons = [];

        if ($project->status === Project::ACTIVE) {
            if ($project->end_date !== null && $project->end_date->lt(today()) && $openCount > 0) {
                $status = self::DELAYED;
                $reasons[] = 'Past its end date ('.$project->end_date->toFormattedDateString().') with '.$openCount.' open task'.($openCount === 1 ? '' : 's').'.';
            }

            if ($status !== self::DELAYED && $openCount > 0 && $overdue / $openCount > self::OVERDUE_LIMIT) {
                $status = self::AT_RISK;
                $reasons[] = "{$overduePercent}% of open tasks are overdue.";
            }

            if ($status !== self::DELAYED && $project->uses_sprints && ($sprint = Sprint::where('project_id', $project->id)->where('status', Sprint::ACTIVE)->first()) && $this->sprints->burndown($sprint)['behind']) {
                $status = self::AT_RISK;
                $reasons[] = "{$sprint->name} is behind its burndown.";
            }
        }

        return [
            'status' => $status,
            'label' => ['on_track' => 'On track', 'at_risk' => 'At risk', 'delayed' => 'Delayed'][$status],
            'reasons' => $reasons,
            'overdue' => $overdue,
            'open' => $openCount,
            'overdue_percent' => $overduePercent,
            'progress' => $progress['percent'],
        ];
    }
}
