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

/** The weekly timesheet: the grid a person fills in, submitting it, and its review (WORKFLOW.md section 8). */
class TimesheetService
{
    public function __construct(private WorkLogService $logs) {}

    public function find(Membership $member, Carbon $week): ?Timesheet
    {
        return Timesheet::withoutGlobalScope(OrganizationScope::class)->where('membership_id', $member->id)->whereDate('week_start', $week->toDateString())->first();
    }

    /**
     * The week as rows of tasks and cells per day.
     *
     * @return array{rows:list<array<string,mixed>>,days:list<array<string,mixed>>,total:int}
     */
    public function grid(Membership $member, Carbon $week): array
    {
        $days = collect(range(0, 6))->map(fn ($i) => $week->copy()->addDays($i));

        $logs = WorkLog::withoutGlobalScope(OrganizationScope::class)->where('membership_id', $member->id)
            ->whereBetween('work_date', [$week->toDateString(), $week->copy()->addDays(6)->toDateString()])
            ->with('task.project')->get();

        $rows = $logs->groupBy('task_id')->map(function ($taskLogs) use ($days) {
            $task = $taskLogs->first()->task;
            $cells = [];
            foreach ($days as $day) {
                $mine = $taskLogs->filter(fn (WorkLog $l) => $l->work_date->isSameDay($day));
                $cells[$day->toDateString()] = ['minutes' => (int) $mine->sum('minutes'), 'entries' => $mine->count()];
            }

            return [
                'task' => ['id' => $task->id, 'key' => $task->key_label, 'title' => $task->title],
                'cells' => $cells,
                'total' => (int) $taskLogs->sum('minutes'),
            ];
        })->sortBy(fn ($r) => $r['task']['key'])->values()->all();

        return [
            'rows' => $rows,
            'days' => $days->map(fn (Carbon $d) => [
                'date' => $d->toDateString(),
                'label' => $d->format('D j'),
                'is_today' => $d->isToday(),
                'is_future' => $d->isAfter(today()),
                'total' => (int) $logs->filter(fn (WorkLog $l) => $l->work_date->isSameDay($d))->sum('minutes'),
            ])->all(),
            'total' => (int) $logs->sum('minutes'),
        ];
    }

    /**
     * Set what was spent on a task on one day. One entry per cell is edited in place; a cell made of several
     * entries has to be changed on the task.
     *
     * @throws ValidationException
     */
    public function setCell(Membership $actor, Task $task, string $date, int $minutes): void
    {
        $existing = WorkLog::withoutGlobalScope(OrganizationScope::class)->where('membership_id', $actor->id)
            ->where('task_id', $task->id)->whereDate('work_date', $date)->get();

        if ($existing->count() > 1) {
            throw ValidationException::withMessages(['minutes' => 'This day has several entries for the task. Change them on the task itself.']);
        }

        if ($existing->isEmpty()) {
            if ($minutes > 0) {
                $this->logs->create($task, $actor, ['work_date' => $date, 'minutes' => $minutes]);
            }

            return;
        }

        $minutes > 0 ? $this->logs->update($existing->first(), $actor, ['minutes' => $minutes]) : $this->logs->delete($existing->first(), $actor);
    }

    /** @throws ValidationException */
    public function submit(Membership $member, Carbon $week): Timesheet
    {
        $sheet = $this->find($member, $week);

        if (! $sheet || $sheet->total_min === 0) {
            throw ValidationException::withMessages(['timesheet' => 'There is nothing to submit for this week.']);
        }
        if (! $sheet->isOpen()) {
            throw ValidationException::withMessages(['timesheet' => 'This week is already submitted.']);
        }

        $sheet->forceFill(['status' => Timesheet::SUBMITTED, 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null])->save();

        app(Notifier::class)->timesheetSubmitted($sheet);

        return $sheet;
    }

    /** Someone with timesheet.approve whose data scope reaches the owner, and never for their own week. */
    public function canReview(Membership $by, Timesheet $sheet): bool
    {
        $sheet->loadMissing('owner');

        return $by->hasPermission('timesheet.approve') && $sheet->membership_id !== $by->id && $by->covers($sheet->owner);
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function approve(Timesheet $sheet, Membership $by): Timesheet
    {
        $this->assertReviewable($sheet, $by);

        $sheet->forceFill(['status' => Timesheet::APPROVED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => null])->save();

        return $sheet;
    }

    /**
     * Send it back to its owner to correct and submit again.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function returnToOwner(Timesheet $sheet, Membership $by, string $note): Timesheet
    {
        $this->assertReviewable($sheet, $by);

        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Say what needs to change.']);
        }

        DB::transaction(fn () => $sheet->forceFill(['status' => Timesheet::RETURNED, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => trim($note)])->save());

        app(Notifier::class)->timesheetReturned($sheet, $by, trim($note));

        return $sheet;
    }

    /** @throws ValidationException|AuthorizationException */
    private function assertReviewable(Timesheet $sheet, Membership $by): void
    {
        if (! $this->canReview($by, $sheet)) {
            throw new AuthorizationException('You cannot review this timesheet.');
        }
        if ($sheet->status !== Timesheet::SUBMITTED) {
            throw ValidationException::withMessages(['timesheet' => 'Only a submitted timesheet can be reviewed.']);
        }
    }
}
