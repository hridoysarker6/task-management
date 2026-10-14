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
     *
     * @throws ValidationException
     */
    public function moveCard(Task $task, ProjectStatus $to, ?int $prevId, ?int $nextId, Membership $actor, bool $closeChildren = false): Task
    {
        if ($task->status_id !== $to->id) {
            $this->tasks->changeStatus($task, $to, $actor, $closeChildren);
        } elseif ($to->category === 'triage') {
            throw ValidationException::withMessages(['status_id' => 'Requests in triage are ordered by when they arrived.']);
        }

        $scope = $this->inProject($task)->where('status_id', $to->id);
        $this->place($task, 'board_position', $scope, $prevId, $nextId);

        return $task->refresh();
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
