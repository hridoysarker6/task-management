<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Scopes\OrganizationScope;
use App\Models\Sprint;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How far along a set of tasks is: done / total, ignoring cancelled and triage tasks (WORKFLOW.md section 9).
 * Counted by number of tasks or by story points, per the project's progress mode. Epics only group other work,
 * so they are never counted themselves.
 */
class ProgressService
{
    /** @return array{done:int|float,total:int|float,percent:int,mode:string} */
    public function forEpic(Task $epic, Project $project): array
    {
        return $this->forTasks(Task::withoutGlobalScope(OrganizationScope::class)->where('tasks.parent_id', $epic->id), $project->progress_mode ?? 'count', countEpics: true);
    }

    /**
     * Progress of a project. Pass the viewer to count only the tasks they may see.
     *
     * @return array{done:int|float,total:int|float,percent:int,mode:string}
     */
    public function forProject(Project $project, ?Membership $viewer = null): array
    {
        $tasks = $viewer ? Task::visibleTo($viewer) : Task::query();

        return $this->forTasks($tasks->where('tasks.project_id', $project->id), $project->progress_mode ?? 'count');
    }

    /** @return array{done:int|float,total:int|float,percent:int,mode:string} */
    public function forSprint(Sprint $sprint, ?Membership $viewer = null): array
    {
        $tasks = $viewer ? Task::visibleTo($viewer) : Task::query();

        return $this->forTasks($tasks->where('tasks.sprint_id', $sprint->id), $sprint->project->progress_mode ?? 'count');
    }

    /** One person's assigned work, optionally inside one project. @return array{done:int|float,total:int|float,percent:int,mode:string} */
    public function forPerson(Membership $person, ?Project $project = null, ?Membership $viewer = null): array
    {
        $tasks = ($viewer ? Task::visibleTo($viewer) : Task::query())->where('tasks.assignee_id', $person->id);

        return $this->forTasks($project ? $tasks->where('tasks.project_id', $project->id) : $tasks, $project ? $project->progress_mode : 'count');
    }

    /**
     * Everything assigned to a manager's whole reporting line (themself included when asked).
     *
     * @return array{done:int|float,total:int|float,percent:int,mode:string}
     */
    public function forTeam(Membership $manager, bool $includeSelf = false, ?Membership $viewer = null, string $mode = 'count'): array
    {
        $team = DB::table('membership_hierarchy')->where('ancestor_id', $manager->id)->where('depth', $includeSelf ? '>=' : '>', 0)->select('descendant_id');
        $tasks = ($viewer ? Task::visibleTo($viewer) : Task::query())->whereIn('tasks.assignee_id', $team);

        return $this->forTasks($tasks, $mode);
    }

    /**
     * The shared calculation. Counts the tasks of any (already scoped) query, one aggregate query.
     *
     * @param  Builder<Task>  $tasks
     * @return array{done:int|float,total:int|float,percent:int,mode:string}
     */
    public function forTasks(Builder $tasks, string $mode = 'count', bool $countEpics = false): array
    {
        $query = (clone $tasks)->join('project_statuses', 'project_statuses.id', '=', 'tasks.status_id')
            ->whereNotIn('project_statuses.category', ['cancelled', 'triage']);

        if (! $countEpics) {
            $query->where('tasks.type', '!=', 'epic');
        }

        $row = $query->toBase()->selectRaw(
            "count(*) as total, sum(case when project_statuses.category = 'done' then 1 else 0 end) as done, ".
            "coalesce(sum(tasks.story_points), 0) as points, coalesce(sum(case when project_statuses.category = 'done' then tasks.story_points else 0 end), 0) as points_done",
        )->first();

        $total = $mode === 'points' ? (float) $row->points : (int) $row->total;
        $done = $mode === 'points' ? (float) $row->points_done : (int) $row->done;

        return ['done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int) round($done / $total * 100) : 0, 'mode' => $mode];
    }
}
