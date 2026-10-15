<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Scopes\OrganizationScope;
use App\Models\Sprint;
use App\Models\Task;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The sprint lifecycle (WORKFLOW.md section 7): create, plan (move tasks in and out), start, complete.
 * Every move of a task is recorded in `sprint_task`, which the reports read later.
 */
class SprintService
{
    // --- the sprint itself -----------------------------------------------------------------------

    /**
     * @param  array{name:string,goal?:?string,start_date:string,end_date:string}  $data
     *
     * @throws ValidationException
     */
    public function create(Project $project, Membership $actor, array $data): Sprint
    {
        $this->assertUsable($project);
        $this->assertDates($data['start_date'], $data['end_date']);

        return Sprint::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => trim($data['name']),
            'goal' => $data['goal'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => Sprint::PLANNED,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * @param  array{name:string,goal?:?string,start_date:string,end_date:string}  $data
     *
     * @throws ValidationException
     */
    public function update(Sprint $sprint, array $data): Sprint
    {
        $this->assertUsable($sprint->project);
        $this->assertNotCompleted($sprint);
        $this->assertDates($data['start_date'], $data['end_date']);

        $sprint->fill(['name' => trim($data['name']), 'goal' => $data['goal'] ?? null, 'start_date' => $data['start_date'], 'end_date' => $data['end_date']])->save();

        return $sprint;
    }

    /**
     * Only a sprint that has not started can be deleted; its tasks go back to the backlog.
     *
     * @throws ValidationException
     */
    public function delete(Sprint $sprint): void
    {
        $this->assertUsable($sprint->project);

        if (! $sprint->isPlanned()) {
            throw ValidationException::withMessages(['sprint' => $sprint->isActive() ? 'Complete the sprint instead of deleting it.' : 'A completed sprint is kept for the reports.']);
        }

        DB::transaction(function () use ($sprint) {
            $this->tasksIn($sprint)->each(fn (Task $t) => $this->leave($t, $sprint, 'removed'));
            $sprint->delete();
        });
    }

    // --- planning --------------------------------------------------------------------------------

    /**
     * Put a task (and its sub-tasks) into a sprint, taking it out of any other sprint it was in.
     *
     * @throws ValidationException
     */
    public function addTask(Sprint $sprint, Task $task): Task
    {
        $this->assertUsable($sprint->project);
        $this->assertNotCompleted($sprint);

        if ($task->project_id !== $sprint->project_id) {
            throw ValidationException::withMessages(['task' => 'That task belongs to another project.']);
        }
        if (in_array($task->type, ['epic', 'subtask'], true)) {
            throw ValidationException::withMessages(['task' => $task->type === 'epic' ? 'An epic is not planned in a sprint; plan its stories and tasks.' : 'A sub-task moves with its parent task.']);
        }
        $task->loadMissing('status');
        if (in_array($task->status->category, ['done', 'cancelled'], true)) {
            throw ValidationException::withMessages(['task' => 'A finished task cannot be planned in a sprint.']);
        }
        if ($task->status->category === 'triage') {
            throw ValidationException::withMessages(['task' => 'Accept the request from the triage queue before planning it.']);
        }

        DB::transaction(function () use ($sprint, $task) {
            foreach ($this->family($task) as $t) {
                if ($t->sprint_id === $sprint->id) {
                    continue;
                }
                if ($t->sprint_id) {
                    $this->leave($t, Sprint::withTrashed()->find($t->sprint_id), 'removed');
                }
                $this->join($t->refresh(), $sprint);
            }
        });

        return $task->refresh();
    }

    /**
     * Take a task (and its sub-tasks) out of its sprint, back to the backlog.
     *
     * @throws ValidationException
     */
    public function removeTask(Task $task): Task
    {
        $sprint = $task->sprint_id ? Sprint::withTrashed()->find($task->sprint_id) : null;
        if (! $sprint) {
            return $task;
        }

        $this->assertUsable($sprint->project);
        $this->assertNotCompleted($sprint);

        DB::transaction(function () use ($task, $sprint) {
            foreach ($this->family($task) as $t) {
                if ($t->sprint_id === $sprint->id) {
                    $this->leave($t, $sprint, 'removed');
                }
            }
        });

        return $task->refresh();
    }

    // --- start / complete ------------------------------------------------------------------------

    /** @throws ValidationException */
    public function start(Sprint $sprint): Sprint
    {
        $this->assertUsable($sprint->project);

        if (! $sprint->isPlanned()) {
            throw ValidationException::withMessages(['sprint' => 'Only a planned sprint can be started.']);
        }
        if (Sprint::where('project_id', $sprint->project_id)->where('status', Sprint::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['sprint' => 'Another sprint is already active. Complete it first.']);
        }

        $tasks = $this->tasksIn($sprint);
        if ($tasks->isEmpty()) {
            throw ValidationException::withMessages(['sprint' => 'Add at least one task before starting the sprint.']);
        }

        DB::transaction(function () use ($sprint, $tasks) {
            // What the team commits to is what is in the sprint now; later additions count as scope added.
            foreach ($tasks as $task) {
                DB::table('sprint_task')->where('sprint_id', $sprint->id)->where('task_id', $task->id)->whereNull('removed_at')
                    ->update(['points_at_start' => $task->story_points]);
            }

            $sprint->forceFill([
                'status' => Sprint::ACTIVE,
                'started_at' => now(),
                'committed_points' => $tasks->sum(fn (Task $t) => (float) $t->story_points),
            ])->save();
        });

        app(Notifier::class)->sprintStarted($sprint);

        return $sprint;
    }

    /**
     * Finish the active sprint. Done tasks stay with it; unfinished ones go to $next (a planned sprint of the project)
     * or back to the backlog.
     *
     * @return array{completed:int,carried:int,dropped:int,points:float}
     *
     * @throws ValidationException
     */
    public function complete(Sprint $sprint, ?Sprint $next = null): array
    {
        $this->assertUsable($sprint->project);

        if (! $sprint->isActive()) {
            throw ValidationException::withMessages(['sprint' => 'Only the active sprint can be completed.']);
        }
        if ($next && ($next->project_id !== $sprint->project_id || ! $next->isPlanned())) {
            throw ValidationException::withMessages(['next_sprint_id' => 'Choose a planned sprint of this project.']);
        }

        $summary = DB::transaction(function () use ($sprint, $next) {
            $summary = ['completed' => 0, 'carried' => 0, 'dropped' => 0, 'points' => 0.0];

            foreach ($this->tasksIn($sprint)->load('status') as $task) {
                $category = $task->status->category;

                if ($category === 'done') {
                    // Finished work stays attached to the sprint it was finished in.
                    $this->closeHistory($task, $sprint, 'completed', false);
                    $summary['completed']++;
                    $summary['points'] += (float) $task->story_points;
                } elseif ($category === 'cancelled') {
                    $this->leave($task, $sprint, 'removed');
                    $summary['dropped']++;
                } else {
                    $this->leave($task, $sprint, 'carried_over');
                    if ($next) {
                        $this->join($task->refresh(), $next);
                    }
                    $summary['carried']++;
                }
            }

            $sprint->forceFill(['status' => Sprint::COMPLETED, 'completed_at' => now(), 'completed_points' => $summary['points']])->save();

            return $summary;
        });

        app(Notifier::class)->sprintCompleted($sprint, $summary);

        return $summary;
    }

    // --- bookkeeping -----------------------------------------------------------------------------

    /** @return EloquentCollection<int, Task> */
    private function tasksIn(Sprint $sprint): EloquentCollection
    {
        return Task::withoutGlobalScope(OrganizationScope::class)->where('sprint_id', $sprint->id)->get();
    }

    /**
     * The task and its sub-tasks: they travel together.
     *
     * @return EloquentCollection<int, Task>
     */
    private function family(Task $task): EloquentCollection
    {
        return Task::withoutGlobalScope(OrganizationScope::class)->where(fn ($q) => $q->where('id', $task->id)->orWhere('parent_id', $task->id))->get();
    }

    private function join(Task $task, Sprint $sprint): void
    {
        $task->sprint_id = $sprint->id;

        // Planned work is "To Do", not "Backlog" (WORKFLOW.md section 5).
        $status = $task->status()->first();
        if ($status && $status->is_initial && $status->category === 'todo'
            && ($todo = $task->project->statuses()->where('category', 'todo')->where('is_initial', false)->first())) {
            $task->status_id = $todo->id;
        }
        $task->save();

        DB::table('sprint_task')->insert([
            'sprint_id' => $sprint->id, 'task_id' => $task->id, 'added_at' => now(),
            'points_at_start' => $sprint->isActive() ? $task->story_points : null,
        ]);
    }

    /** Take the task out of the sprint and record why. */
    private function leave(Task $task, ?Sprint $sprint, string $outcome): void
    {
        $task->sprint_id = null;
        $task->save();

        if ($sprint) {
            $this->closeHistory($task, $sprint, $outcome);
        }
    }

    private function closeHistory(Task $task, Sprint $sprint, string $outcome, bool $removed = true): void
    {
        DB::table('sprint_task')->where('sprint_id', $sprint->id)->where('task_id', $task->id)->whereNull('outcome')
            ->update(['outcome' => $outcome] + ($removed ? ['removed_at' => now()] : []));
    }

    private function assertUsable(Project $project): void
    {
        if ($project->isArchived()) {
            throw ValidationException::withMessages(['sprint' => 'This project is archived and read-only.']);
        }
        if (! $project->uses_sprints) {
            throw ValidationException::withMessages(['sprint' => 'This project works as a continuous board; sprints are switched off.']);
        }
    }

    private function assertNotCompleted(Sprint $sprint): void
    {
        if ($sprint->isCompleted()) {
            throw ValidationException::withMessages(['sprint' => 'A completed sprint cannot be changed.']);
        }
    }

    private function assertDates(string $start, string $end): void
    {
        if ($end < $start) {
            throw ValidationException::withMessages(['end_date' => 'The sprint cannot end before it starts.']);
        }
    }
}
