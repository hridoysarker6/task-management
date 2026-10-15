<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Support\Ranking;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dragging: a card between or within board columns (board_position), and a task within the backlog (backlog_rank).
 * Positions are rank numbers, so one drop updates one row; a column is renumbered only when neighbours get too close.
 */
class BoardService
{
    public function __construct(private TaskService $tasks) {}

    /**
     * Drop a card in a column, between two neighbours ($prevId above it, $nextId below it; either may be null).
     * Moving to another column goes through the normal status rules, so a refused move changes nothing.
     * Dropping into another swimlane also changes the person ('assignee_id') or the epic ('parent_id'); the whole drop
     * is one transaction, so a refused part cancels the rest.
     *
     * @param  array{assignee_id?:?int,parent_id?:?int}  $lane  only the keys that changed (a null value clears the field)
     *
     * @throws ValidationException
     */
    public function moveCard(Task $task, ProjectStatus $to, ?int $prevId, ?int $nextId, Membership $actor, bool $closeChildren = false, array $lane = []): Task
    {
        return DB::transaction(function () use ($task, $to, $prevId, $nextId, $actor, $closeChildren, $lane) {
            if (array_key_exists('assignee_id', $lane)) {
                $this->assignLane($task, $lane['assignee_id'], $actor);
            }
            if (array_key_exists('parent_id', $lane)) {
                $this->epicLane($task, $lane['parent_id'], $actor);
            }

            if ($task->status_id !== $to->id) {
                $this->tasks->changeStatus($task, $to, $actor, $closeChildren);
            } elseif ($to->category === 'triage') {
                throw ValidationException::withMessages(['status_id' => 'Requests in triage are ordered by when they arrived.']);
            }

            $scope = $this->inProject($task)->where('status_id', $to->id);
            $this->place($task, 'board_position', $scope, $prevId, $nextId);

            return $task->refresh();
        });
    }

    /** @throws ValidationException */
    private function assignLane(Task $task, ?int $assigneeId, Membership $actor): void
    {
        $assignee = $assigneeId === null ? null : Membership::withoutGlobalScope(OrganizationScope::class)->with('role')
            ->where('organization_id', $task->organization_id)->find($assigneeId)
            ?? throw ValidationException::withMessages(['assignee_id' => 'That person does not exist.']);

        if ($task->assignee_id !== $assignee?->id) {
            $this->tasks->assign($task, $assignee, $actor);
        }
    }

    /**
     * Move a task under another epic (or out of its epic). Only tasks that hang directly from an epic, or from nothing,
     * can change epic this way; sub-tasks and tasks under a story follow their parent.
     *
     * @throws ValidationException
     */
    private function epicLane(Task $task, ?int $epicId, Membership $actor): void
    {
        if ($task->parent_id === $epicId) {
            return;
        }

        $parent = $task->parent_id ? Task::withoutGlobalScope(OrganizationScope::class)->find($task->parent_id) : null;
        if (in_array($task->type, ['epic', 'subtask'], true) || ($parent && $parent->type !== 'epic')) {
            throw ValidationException::withMessages(['parent_id' => 'This task sits under another task, so its epic cannot be changed from the board.']);
        }

        $this->tasks->update($task, $actor, ['parent_id' => $epicId]);
    }

    /** Drop a task in the backlog between two others. */
    public function rank(Task $task, ?int $prevId, ?int $nextId): Task
    {
        $this->place($task, 'backlog_rank', $this->inProject($task), $prevId, $nextId);

        return $task->refresh();
    }

    /** @return Builder<Task> */
    private function inProject(Task $task): Builder
    {
        return Task::withoutGlobalScope(OrganizationScope::class)->where('project_id', $task->project_id);
    }

    /** @param Builder<Task> $scope the tasks being ordered (the same column, or the whole backlog) */
    private function place(Task $task, string $column, Builder $scope, ?int $prevId, ?int $nextId): void
    {
        [$above, $below] = $this->neighbours($scope, $column, $task, $prevId, $nextId);
        $rank = Ranking::between($above, $below);

        if ($rank === null) {
            // The gap is used up: renumber the whole column, then try again with fresh numbers.
            $this->renumber($scope, $column, $task);
            [$above, $below] = $this->neighbours($scope, $column, $task, $prevId, $nextId);
            $rank = Ranking::between($above, $below) ?? ($above ?? 0.0) + Ranking::STEP;
        }

        // A plain update: reordering is not an edit, so it leaves no history line and does not touch updated_at.
        DB::table('tasks')->where('id', $task->id)->update([$column => $rank]);
    }

    /** @return array{0:?float,1:?float} the ranks of the tasks above and below the drop point */
    private function neighbours(Builder $scope, string $column, Task $task, ?int $prevId, ?int $nextId): array
    {
        $rank = fn (?int $id) => $id ? ((clone $scope)->where('id', $id)->where('id', '!=', $task->id)->value($column)) : null;

        $above = $rank($prevId);
        $below = $rank($nextId);
        $above = $above === null ? null : (float) $above;
        $below = $below === null ? null : (float) $below;

        // A stale screen may send neighbours in the wrong order: put them the right way round.
        if ($above !== null && $below !== null && $above > $below) {
            [$above, $below] = [$below, $above];
        }

        return [$above, $below];
    }

    private function renumber(Builder $scope, string $column, Task $except): void
    {
        $ids = (clone $scope)->where('id', '!=', $except->id)->orderBy($column)->orderBy('id')->pluck('id');

        DB::transaction(function () use ($ids, $column) {
            foreach ($ids as $i => $id) {
                DB::table('tasks')->where('id', $id)->update([$column => ($i + 1) * Ranking::STEP]);
            }
        });
    }
}
