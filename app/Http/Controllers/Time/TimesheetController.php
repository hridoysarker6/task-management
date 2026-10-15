<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Timesheet;
use App\Services\TimesheetService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** My Timesheet: the week as a grid of tasks by day, with time typed in place, then submitted. */
class TimesheetController extends Controller
{
    public function __construct(private CurrentOrganization $current, private TimesheetService $sheets) {}

    public function index(Request $request): Response
    {
        Gate::authorize('worklog.create');

        $me = $this->current->membership;
        $week = $this->week($request);
        $sheet = $this->sheets->find($me, $week);
        $grid = $this->sheets->grid($me, $week);

        // Open tasks assigned to me that are not in the grid yet, to add a row from.
        $inGrid = collect($grid['rows'])->pluck('task.id');
        $suggestions = Task::visibleTo($me)->where('assignee_id', $me->id)->whereNotIn('id', $inGrid)
            ->whereHas('status', fn ($s) => $s->whereNotIn('category', ['done', 'cancelled', 'triage']))
            ->with('project')->orderBy('project_id')->orderBy('number')->limit(50)->get()
            ->map(fn (Task $t) => ['id' => $t->id, 'key' => $t->key_label, 'title' => $t->title])->values();

        return Inertia::render('Timesheets/Mine', [
            'week' => ['start' => $week->toDateString(), 'end' => $week->copy()->addDays(6)->toDateString(), 'is_current' => $week->isSameDay(Timesheet::weekStartOf(today()))],
            'prev' => $week->copy()->subWeek()->toDateString(),
            'next' => $week->copy()->addWeek()->toDateString(),
            'days' => $grid['days'],
            'rows' => $grid['rows'],
            'total' => $grid['total'],
            'sheet' => $sheet ? [
                'status' => $sheet->status,
                'submitted_at' => $sheet->submitted_at?->toIso8601String(),
                'review_note' => $sheet->review_note,
                'reviewer' => $sheet->reviewer?->user?->name,
            ] : ['status' => Timesheet::DRAFT, 'submitted_at' => null, 'review_note' => null, 'reviewer' => null],
            'editable' => $sheet === null || $sheet->isOpen(),
            'suggestions' => $suggestions,
        ]);
    }

    /** Set the time on one task for one day (0 clears it). */
    public function cell(Request $request): JsonResponse
    {
        Gate::authorize('worklog.create');

        $data = $request->validate([
            'task_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);

        $me = $this->current->membership;
        $task = Task::visibleTo($me)->findOrFail($data['task_id']);

        $this->sheets->setCell($me, $task, $data['date'], $data['minutes']);

        return response()->json(['ok' => true]);
    }

    public function submit(Request $request): RedirectResponse
    {
        Gate::authorize('worklog.create');

        $data = $request->validate(['week' => ['required', 'date_format:Y-m-d']]);

        $this->sheets->submit($this->current->membership, Timesheet::weekStartOf($data['week']));

        return back()->with('success', 'Timesheet submitted for approval.');
    }

    private function week(Request $request): Carbon
    {
        $date = (string) $request->query('week', '');

        return Timesheet::weekStartOf(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false ? $date : today());
    }
}
