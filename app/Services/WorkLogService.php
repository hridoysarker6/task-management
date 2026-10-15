<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\WorkLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Logging time on tasks (WORKFLOW.md sections 6.5 and 8). Every entry lives in the week's timesheet; once that is
 * submitted or approved the entries are locked, and only someone with worklog.edit_any may still change them.
 * The task's `time_spent_min` and remaining estimate follow every change.
 */
class WorkLogService
{
    /** Nobody works more than a day in a day. */
    public const MAX_DAY_MIN = 1440;

    public function __construct(private TaskService $tasks, private TaskActivityLogger $activity) {}

    /**
     * @param  array{work_date:string,minutes:int,note?:?string}  $data
     *
     * @throws ValidationException
     */
    public function create(Task $task, Membership $actor, array $data): WorkLog
    {
        $task->loadMissing('project', 'status');
        $this->tasks->assertCanWorkIn($task->project, $actor);

        if (in_array($task->status->category, ['triage', 'cancelled'], true)) {
            throw ValidationException::withMessages(['task' => 'Time cannot be logged on a request in triage or on a cancelled task.']);
        }

        $date = Carbon::parse($data['work_date'])->startOfDay();
        $minutes = $this->minutes($data['minutes']);
        $this->assertNotFuture($date);
        $this->assertRoomInDay($actor, $date, $minutes);

        $sheet = $this->sheetFor($actor, $date);
        if (! $sheet->isOpen()) {
            throw ValidationException::withMessages(['work_date' => 'That week is already submitted. Ask your approver to return it first.']);
        }

        return DB::transaction(function () use ($task, $actor, $date, $minutes, $sheet, $data) {
            $log = WorkLog::withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $task->organization_id,
                'task_id' => $task->id,
                'membership_id' => $actor->id,
                'timesheet_id' => $sheet->id,
                'work_date' => $date,
                'minutes' => $minutes,
                'note' => $this->note($data['note'] ?? null),
            ]);

            $this->recalculate($sheet);
            $this->adjustTask($task, $minutes);
            $this->activity->log($task, 'work_logged', null, null, (string) $minutes, ['date' => $date->toDateString(), 'note' => $log->note], $actor->id);

            return $log;
        });
    }

    /**
     * @param  array{work_date?:string,minutes?:int,note?:?string}  $data
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function update(WorkLog $log, Membership $actor, array $data): WorkLog
    {
        $this->assertCanEdit($log, $actor);

        $date = isset($data['work_date']) ? Carbon::parse($data['work_date'])->startOfDay() : $log->work_date->copy();
        $minutes = isset($data['minutes']) ? $this->minutes($data['minutes']) : $log->minutes;
        $this->assertNotFuture($date);
        $this->assertRoomInDay($log->author, $date, $minutes, $log);

        $from = $log->timesheet_id ? Timesheet::find($log->timesheet_id) : null;
        $to = $from;
        if (! Timesheet::weekStartOf($date)->isSameDay(Timesheet::weekStartOf($log->work_date))) {
            $to = $this->sheetFor($log->author, $date);
            if (! $to->isOpen() && ! $actor->hasPermission('worklog.edit_any')) {
                throw ValidationException::withMessages(['work_date' => 'That week is already submitted.']);
            }
        }

        return DB::transaction(function () use ($log, $actor, $date, $minutes, $data, $from, $to) {
            $delta = $minutes - $log->minutes;
            $log->fill([
                'work_date' => $date,
                'minutes' => $minutes,
                'note' => array_key_exists('note', $data) ? $this->note($data['note']) : $log->note,
                'timesheet_id' => $to?->id,
            ])->save();

            foreach (array_unique(array_filter([$from?->id, $to?->id])) as $id) {
                $this->recalculate(Timesheet::findOrFail($id));
            }

            $task = Task::withoutGlobalScope(OrganizationScope::class)->findOrFail($log->task_id);
            $this->adjustTask($task, $delta);
            $this->activity->log($task, 'work_updated', null, null, (string) $minutes, ['date' => $date->toDateString()], $actor->id);

            return $log;
        });
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function delete(WorkLog $log, Membership $actor): void
    {
        $this->assertCanEdit($log, $actor);

        DB::transaction(function () use ($log, $actor) {
            $sheet = $log->timesheet_id ? Timesheet::find($log->timesheet_id) : null;
            $log->delete();
            if ($sheet) {
                $this->recalculate($sheet);
            }

            $task = Task::withoutGlobalScope(OrganizationScope::class)->findOrFail($log->task_id);
            $this->adjustTask($task, -$log->minutes);
            $this->activity->log($task, 'work_deleted', null, (string) $log->minutes, null, ['date' => $log->work_date->toDateString()], $actor->id);
        });
    }

    /** May this person change this entry? Their own while the week is open; anyone's with worklog.edit_any. */
    public function canEdit(WorkLog $log, Membership $actor): bool
    {
        $log->loadMissing('author');

        if ($actor->hasPermission('worklog.edit_any') && $actor->covers($log->author)) {
            return true;
        }

        if ($log->membership_id !== $actor->id || ! $actor->hasPermission('worklog.edit_own')) {
            return false;
        }

        $sheet = $log->timesheet_id ? Timesheet::find($log->timesheet_id) : null;

        return $sheet === null || $sheet->isOpen();
    }

    /** The timesheet of the week a date falls in, created on first use. */
    public function sheetFor(Membership $member, Carbon $date): Timesheet
    {
        $week = Timesheet::weekStartOf($date);

        // whereDate: the week start is stored as a date, but some databases keep a time part with it.
        return Timesheet::withoutGlobalScope(OrganizationScope::class)->where('membership_id', $member->id)->whereDate('week_start', $week->toDateString())->first()
            ?? Timesheet::withoutGlobalScope(OrganizationScope::class)->create([
                'organization_id' => $member->organization_id, 'membership_id' => $member->id, 'week_start' => $week->toDateString(), 'status' => Timesheet::DRAFT, 'total_min' => 0,
            ]);
    }

    public function recalculate(Timesheet $sheet): void
    {
        $sheet->forceFill(['total_min' => (int) WorkLog::withoutGlobalScope(OrganizationScope::class)->where('timesheet_id', $sheet->id)->sum('minutes')])->save();
    }

    // --- rules -----------------------------------------------------------------------------------

    /** @throws ValidationException|AuthorizationException */
    private function assertCanEdit(WorkLog $log, Membership $actor): void
    {
        $log->loadMissing('author');

        if (! $this->canEdit($log, $actor)) {
            if ($log->membership_id === $actor->id && $actor->hasPermission('worklog.edit_own')) {
                throw ValidationException::withMessages(['work_log' => 'This week is submitted, so its entries are locked.']);
            }

            throw new AuthorizationException('You cannot change this entry.');
        }
    }

    private function minutes(mixed $value): int
    {
        $minutes = (int) $value;
        if ($minutes < 1 || $minutes > self::MAX_DAY_MIN) {
            throw ValidationException::withMessages(['minutes' => 'Log between 1 minute and 24 hours.']);
        }

        return $minutes;
    }

    private function note(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : mb_substr($note, 0, 500);
    }

    private function assertNotFuture(Carbon $date): void
    {
        if ($date->isAfter(today())) {
            throw ValidationException::withMessages(['work_date' => 'You cannot log time in the future.']);
        }
    }

    private function assertRoomInDay(Membership $member, Carbon $date, int $minutes, ?WorkLog $except = null): void
    {
        $already = (int) WorkLog::withoutGlobalScope(OrganizationScope::class)->where('membership_id', $member->id)
            ->whereDate('work_date', $date->toDateString())->when($except, fn ($q) => $q->where('id', '!=', $except->id))->sum('minutes');

        if ($already + $minutes > self::MAX_DAY_MIN) {
            throw ValidationException::withMessages(['minutes' => 'That would be more than 24 hours on one day.']);
        }
    }

    /** Spent time goes up by what was logged; the remaining estimate comes down by it (and back up when an entry is removed). */
    private function adjustTask(Task $task, int $delta): void
    {
        $task->time_spent_min = max(0, (int) $task->time_spent_min + $delta);

        if ($task->remaining_estimate_min !== null) {
            $remaining = max(0, $task->remaining_estimate_min - $delta);
            $task->remaining_estimate_min = $task->original_estimate_min !== null ? min($remaining, $task->original_estimate_min) : $remaining;
        }

        $task->save();
    }
}
