<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Timesheet;
use App\Services\TimesheetService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Team time: who has submitted their week, and the queue of timesheets waiting for my decision. */
class TeamTimesheetController extends Controller
{
    public function __construct(private CurrentOrganization $current, private TimesheetService $sheets) {}

    public function index(Request $request): Response
    {
        abort_unless(Gate::any(['worklog.view_team', 'timesheet.approve']), 403);

        $me = $this->current->membership;
        $date = (string) $request->query('week', '');
        $week = Timesheet::weekStartOf(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false ? $date : today());

        // People whose time I may see and who are expected to log it (their role can log work).
        $loggerRoles = DB::table('role_permission')->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('permissions.name', 'worklog.create')->pluck('role_permission.role_id');
        $people = Membership::active()->visibleTo($me)->where('user_type', 'internal')->where('id', '!=', $me->id)
            ->whereIn('role_id', $loggerRoles)->with(['user:id,name', 'role:id,name'])->get();

        $sheets = Timesheet::whereIn('membership_id', $people->pluck('id'))->whereDate('week_start', $week->toDateString())->get()->keyBy('membership_id');

        $rows = $people->map(function (Membership $p) use ($sheets) {
            $sheet = $sheets->has($p->id) ? $sheets->get($p->id) : null;

            return [
                'id' => $p->id,
                'name' => $p->user->name,
                'role' => $p->role->name,
                'status' => $sheet ? $sheet->status : 'missing', // missing = nothing logged yet
                'total_min' => $sheet ? $sheet->total_min : 0,
                'note' => $sheet ? $sheet->review_note : null,
            ];
        })->sortBy([['name', 'asc']])->values();

        $pending = Timesheet::where('status', Timesheet::SUBMITTED)->whereIn('membership_id', $people->pluck('id'))
            ->with(['owner.user:id,name', 'logs.task.project'])->orderBy('submitted_at')->get()
            ->filter(fn (Timesheet $s) => $this->sheets->canReview($me, $s))
            ->map(fn (Timesheet $s) => [
                'id' => $s->id,
                'owner' => $s->owner->user->name,
                'week_start' => $s->week_start->toDateString(),
                'total_min' => $s->total_min,
                'submitted_at' => $s->submitted_at?->toIso8601String(),
                'entries' => $s->logs->map(fn ($l) => [
                    'id' => $l->id, 'date' => $l->work_date->toDateString(), 'minutes' => $l->minutes, 'note' => $l->note,
                    'task' => ['key' => $l->task->key_label, 'title' => $l->task->title],
                ])->values(),
            ])->values();

        return Inertia::render('Timesheets/Team', [
            'week' => ['start' => $week->toDateString(), 'end' => $week->copy()->addDays(6)->toDateString(), 'is_current' => $week->isSameDay(Timesheet::weekStartOf(today()))],
            'prev' => $week->copy()->subWeek()->toDateString(),
            'next' => $week->copy()->addWeek()->toDateString(),
            'people' => $rows,
            'pending' => $pending,
            'canApprove' => $me->hasPermission('timesheet.approve'),
            'counts' => $rows->countBy('status'),
        ]);
    }

    public function approve(Timesheet $timesheet): RedirectResponse
    {
        Gate::authorize('timesheet.approve');

        $this->sheets->approve($timesheet, $this->current->membership);

        return back()->with('success', 'Timesheet approved.');
    }

    public function returnSheet(Request $request, Timesheet $timesheet): RedirectResponse
    {
        Gate::authorize('timesheet.approve');

        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        $this->sheets->returnToOwner($timesheet, $this->current->membership, $data['note']);

        return back()->with('success', 'Timesheet returned with your comment.');
    }
}
