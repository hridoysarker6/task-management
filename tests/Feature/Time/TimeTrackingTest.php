<?php

namespace Tests\Feature\Time;

use App\Models\Membership;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\Timesheet;
use App\Models\WorkLog;
use App\Services\TimesheetService;
use App\Services\WorkLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Logging work, timesheets, locking and approval (Phase 8). */
class TimeTrackingTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    private function props(TestResponse $response): array
    {
        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    private function key(Task $t): string
    {
        return $t->project->key.'-'.$t->number;
    }

    private function log(Membership $by, Task $task, int $minutes = 60, ?Carbon $date = null, ?string $note = null)
    {
        return $this->actingAsMember($by)->post("/tasks/{$this->key($task)}/worklogs", [
            'work_date' => ($date ?? today())->toDateString(), 'minutes' => $minutes, 'note' => $note,
        ]);
    }

    private function sheet(Membership $m, ?Carbon $date = null): Timesheet
    {
        return Timesheet::withoutGlobalScopes()->where('membership_id', $m->id)->whereDate('week_start', Timesheet::weekStartOf($date ?? today())->toDateString())->firstOrFail();
    }

    private function submit(Membership $by, ?Carbon $date = null)
    {
        return $this->actingAsMember($by)->post('/timesheet/submit', ['week' => Timesheet::weekStartOf($date ?? today())->toDateString()]);
    }

    private function dev1Task(array $attributes = []): Task
    {
        return $this->task($this->ecp, $this->pm, $attributes + ['assignee_id' => $this->dev1->id]);
    }

    // ---- logging -------------------------------------------------------------------------------

    public function test_logging_work_adds_to_time_spent_and_lowers_the_remaining_estimate(): void
    {
        $task = $this->dev1Task(['original_estimate_min' => 240, 'remaining_estimate_min' => 240]);

        $this->log($this->dev1, $task, 90, note: 'Wired the form')->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame(90, $task->time_spent_min);
        $this->assertSame(150, $task->remaining_estimate_min);
        $this->assertSame('Wired the form', WorkLog::withoutGlobalScopes()->firstOrFail()->note);
        $this->assertSame(1, TaskActivity::where('task_id', $task->id)->where('event', 'work_logged')->count());
    }

    public function test_the_entry_lands_in_that_weeks_timesheet(): void
    {
        $task = $this->dev1Task();
        $lastWeek = today()->subWeek();

        $this->log($this->dev1, $task, 60);
        $this->log($this->dev1, $task, 30, $lastWeek);

        $this->assertSame(60, $this->sheet($this->dev1)->total_min);
        $this->assertSame(30, $this->sheet($this->dev1, $lastWeek)->total_min);
        $this->assertTrue($this->sheet($this->dev1)->week_start->isMonday());
    }

    public function test_the_remaining_estimate_never_goes_below_zero_and_is_given_back_on_delete(): void
    {
        $task = $this->dev1Task(['original_estimate_min' => 60, 'remaining_estimate_min' => 60]);

        $this->log($this->dev1, $task, 90);
        $this->assertSame(0, $task->fresh()->remaining_estimate_min);

        $this->actingAsMember($this->dev1)->delete('/worklogs/'.WorkLog::withoutGlobalScopes()->firstOrFail()->id);

        $task->refresh();
        $this->assertSame([0, 60], [$task->time_spent_min, $task->remaining_estimate_min]); // capped at the original estimate
        $this->assertSame(0, $this->sheet($this->dev1)->total_min);
    }

    public function test_editing_an_entry_adjusts_the_task_by_the_difference(): void
    {
        $task = $this->dev1Task(['original_estimate_min' => 480, 'remaining_estimate_min' => 480]);
        $this->log($this->dev1, $task, 60);
        $entry = WorkLog::withoutGlobalScopes()->firstOrFail();

        $this->actingAsMember($this->dev1)->put("/worklogs/{$entry->id}", ['work_date' => today()->toDateString(), 'minutes' => 120, 'note' => 'More than I thought'])->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame([120, 360], [$task->time_spent_min, $task->remaining_estimate_min]);
        $this->assertSame(120, $this->sheet($this->dev1)->total_min);
    }

    public function test_bad_entries_are_refused(): void
    {
        $task = $this->dev1Task();

        $this->log($this->dev1, $task, 0)->assertSessionHasErrors('minutes');
        $this->log($this->dev1, $task, 1500)->assertSessionHasErrors('minutes');
        $this->log($this->dev1, $task, 60, today()->addDay())->assertSessionHasErrors('work_date');

        $this->log($this->dev1, $task, 1000);
        $this->log($this->dev1, $task, 500)->assertSessionHasErrors('minutes'); // more than 24 hours in one day
        $this->assertSame(1000, $task->fresh()->time_spent_min);
    }

    public function test_only_project_members_with_the_permission_can_log(): void
    {
        $task = $this->dev1Task();

        $this->log($this->dev2, $task)->assertForbidden(); // cannot even see a task of a project they are not on
        $this->log($this->client, $task)->assertForbidden(); // externals never log time
        $this->assertSame(0, WorkLog::withoutGlobalScopes()->count());
    }

    public function test_time_cannot_be_logged_on_a_cancelled_task(): void
    {
        $task = $this->dev1Task(['status' => 'Cancelled']);

        $this->log($this->dev1, $task)->assertSessionHasErrors('task');
    }

    public function test_nobody_edits_someone_elses_entry(): void
    {
        $task = $this->dev1Task();
        $this->task($this->ecp, $this->pm);
        $this->log($this->dev1, $task, 60);
        $entry = WorkLog::withoutGlobalScopes()->firstOrFail();

        $this->actingAsMember($this->tlA)->put("/worklogs/{$entry->id}", ['work_date' => today()->toDateString(), 'minutes' => 5])->assertForbidden();
        $this->actingAsMember($this->tlA)->delete("/worklogs/{$entry->id}")->assertForbidden();
        $this->assertSame(60, $entry->fresh()->minutes);
    }

    // ---- locking -------------------------------------------------------------------------------

    public function test_a_submitted_week_is_locked(): void
    {
        $task = $this->dev1Task();
        $this->log($this->dev1, $task, 60);
        $entry = WorkLog::withoutGlobalScopes()->firstOrFail();

        $this->submit($this->dev1)->assertSessionHasNoErrors();

        $this->assertSame('submitted', $this->sheet($this->dev1)->status);
        $this->log($this->dev1, $task, 30)->assertSessionHasErrors('work_date');
        $this->actingAsMember($this->dev1)->put("/worklogs/{$entry->id}", ['work_date' => today()->toDateString(), 'minutes' => 5])->assertSessionHasErrors('work_log');
        $this->actingAsMember($this->dev1)->delete("/worklogs/{$entry->id}")->assertSessionHasErrors('work_log');
        $this->assertSame(60, $task->fresh()->time_spent_min);
    }

    public function test_an_empty_week_cannot_be_submitted_and_a_submitted_one_not_twice(): void
    {
        $this->submit($this->dev1)->assertSessionHasErrors('timesheet');

        $this->log($this->dev1, $this->dev1Task());
        $this->submit($this->dev1)->assertSessionHasNoErrors();
        $this->submit($this->dev1)->assertSessionHasErrors('timesheet');
    }

    public function test_someone_with_edit_any_may_still_correct_a_locked_entry(): void
    {
        $task = $this->dev1Task();
        $this->log($this->dev1, $task, 60);
        $this->submit($this->dev1);
        $entry = WorkLog::withoutGlobalScopes()->firstOrFail();

        $this->actingAsMember($this->admin)->put("/worklogs/{$entry->id}", ['work_date' => today()->toDateString(), 'minutes' => 45])->assertSessionHasNoErrors();

        $this->assertSame(45, $entry->fresh()->minutes);
        $this->assertSame(45, $this->sheet($this->dev1)->total_min);
    }

    // ---- approval ------------------------------------------------------------------------------

    private function submitted(?Membership $who = null): Timesheet
    {
        $who ??= $this->dev1;
        $this->log($who, $this->task($this->ecp, $this->pm, ['assignee_id' => $who->id]), 120);
        $this->submit($who);

        return $this->sheet($who);
    }

    public function test_the_team_lead_approves_a_report_s_timesheet(): void
    {
        $sheet = $this->submitted();

        $this->actingAsMember($this->tlA)->post("/timesheets/{$sheet->id}/approve")->assertSessionHasNoErrors();

        $sheet->refresh();
        $this->assertSame(['approved', $this->tlA->id], [$sheet->status, $sheet->reviewed_by]);
        $this->assertNotNull($sheet->reviewed_at);
    }

    public function test_only_an_approver_who_covers_the_person_may_review(): void
    {
        $sheet = $this->submitted();

        $this->actingAsMember($this->dev2)->post("/timesheets/{$sheet->id}/approve")->assertForbidden(); // no permission
        $this->actingAsMember($this->tlB)->post("/timesheets/{$sheet->id}/approve")->assertForbidden(); // not their team
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_nobody_approves_their_own_week(): void
    {
        $sheet = $this->submitted($this->tlA);

        $this->actingAsMember($this->tlA)->post("/timesheets/{$sheet->id}/approve")->assertForbidden();
        $this->actingAsMember($this->dm)->post("/timesheets/{$sheet->id}/approve")->assertSessionHasNoErrors(); // the manager above can
        $this->assertSame('approved', $sheet->fresh()->status);
    }

    public function test_only_a_submitted_timesheet_can_be_reviewed(): void
    {
        $this->log($this->dev1, $this->dev1Task());
        $draft = $this->sheet($this->dev1);

        $this->actingAsMember($this->tlA)->post("/timesheets/{$draft->id}/approve")->assertSessionHasErrors('timesheet');
    }

    public function test_returning_needs_a_comment_and_unlocks_the_week_for_correction(): void
    {
        $sheet = $this->submitted();

        $this->actingAsMember($this->tlA)->post("/timesheets/{$sheet->id}/return", ['note' => ''])->assertSessionHasErrors('note');
        $this->actingAsMember($this->tlA)->post("/timesheets/{$sheet->id}/return", ['note' => 'Tuesday looks too long'])->assertSessionHasNoErrors();

        $this->assertSame(['returned', 'Tuesday looks too long'], [$sheet->fresh()->status, $sheet->fresh()->review_note]);

        $entry = WorkLog::withoutGlobalScopes()->firstOrFail();
        $this->actingAsMember($this->dev1)->put("/worklogs/{$entry->id}", ['work_date' => today()->toDateString(), 'minutes' => 90])->assertSessionHasNoErrors();
        $this->submit($this->dev1)->assertSessionHasNoErrors();
        $this->assertSame('submitted', $sheet->fresh()->status);
    }

    public function test_an_approved_week_stays_locked_for_its_owner(): void
    {
        $sheet = $this->submitted();
        $this->actingAsMember($this->tlA)->post("/timesheets/{$sheet->id}/approve");

        $this->log($this->dev1, $this->dev1Task(), 10)->assertSessionHasErrors('work_date');
    }

    // ---- the grid ------------------------------------------------------------------------------

    private function cell(Membership $by, Task $task, Carbon $date, int $minutes)
    {
        return $this->actingAsMember($by)->postJson('/timesheet/cell', ['task_id' => $task->id, 'date' => $date->toDateString(), 'minutes' => $minutes]);
    }

    public function test_typing_in_a_cell_creates_changes_and_clears_the_entry(): void
    {
        $task = $this->dev1Task();

        $this->cell($this->dev1, $task, today(), 90)->assertOk();
        $this->assertSame(90, $task->fresh()->time_spent_min);

        $this->cell($this->dev1, $task, today(), 45)->assertOk();
        $this->assertSame([1, 45], [WorkLog::withoutGlobalScopes()->count(), $task->fresh()->time_spent_min]);

        $this->cell($this->dev1, $task, today(), 0)->assertOk();
        $this->assertSame([0, 0], [WorkLog::withoutGlobalScopes()->whereNull('deleted_at')->count(), $task->fresh()->time_spent_min]);
    }

    public function test_a_cell_made_of_several_entries_has_to_be_changed_on_the_task(): void
    {
        $task = $this->dev1Task();
        $this->log($this->dev1, $task, 30);
        $this->log($this->dev1, $task, 30);

        $this->cell($this->dev1, $task, today(), 10)->assertStatus(422);
        $this->assertSame(60, $task->fresh()->time_spent_min);
    }

    public function test_the_grid_shows_the_week_and_suggests_my_open_tasks(): void
    {
        $logged = $this->dev1Task();
        $other = $this->dev1Task(['title' => 'Not yet logged']);
        $this->log($this->dev1, $logged, 90);

        $props = $this->props($this->actingAsMember($this->dev1)->get('/timesheet'));

        $this->assertSame([$this->key($logged)], array_column(array_column($props['rows'], 'task'), 'key'));
        $this->assertSame(90, $props['total']);
        $this->assertCount(7, $props['days']);
        $this->assertSame([$this->key($other)], array_column($props['suggestions'], 'key'));
        $this->assertTrue($props['editable']);
        $this->assertSame('Mon', substr($props['days'][0]['label'], 0, 3));
    }

    public function test_the_week_parameter_is_normalised_to_a_monday_and_junk_is_ignored(): void
    {
        $props = $this->props($this->actingAsMember($this->dev1)->get('/timesheet?week=2026-03-04'));
        $this->assertSame('2026-03-02', $props['week']['start']);

        $props = $this->props($this->actingAsMember($this->dev1)->get('/timesheet?week=nonsense'));
        $this->assertTrue($props['week']['is_current']);
    }

    public function test_a_returned_week_shows_the_comment_and_a_submitted_one_is_not_editable(): void
    {
        $sheet = $this->submitted();
        $this->assertFalse($this->props($this->actingAsMember($this->dev1)->get('/timesheet'))['editable']);

        $this->actingAsMember($this->tlA)->post("/timesheets/{$sheet->id}/return", ['note' => 'Please split the meeting time']);

        $props = $this->props($this->actingAsMember($this->dev1)->get('/timesheet'));
        $this->assertTrue($props['editable']);
        $this->assertSame('Please split the meeting time', $props['sheet']['review_note']);
    }

    public function test_externals_have_no_timesheet(): void
    {
        $this->actingAsMember($this->client)->get('/timesheet')->assertForbidden();
    }

    // ---- team view -----------------------------------------------------------------------------

    public function test_the_team_view_shows_who_submitted_and_who_is_missing(): void
    {
        $this->submitted($this->dev1);
        $this->log($this->dev2, $this->task($this->ecp, $this->pm), 10); // dev2 is not on the project: nothing logged

        $props = $this->props($this->actingAsMember($this->tlA)->get('/timesheets/team'));

        $status = collect($props['people'])->pluck('status', 'id');
        $this->assertSame('submitted', $status[$this->dev1->id]);
        $this->assertSame('missing', $status[$this->dev2->id]);
        $this->assertArrayNotHasKey($this->dev3->id, $status->all()); // not on this lead's team
        $this->assertCount(1, $props['pending']);
        $this->assertSame(120, $props['pending'][0]['total_min']);
    }

    public function test_the_approval_queue_only_lists_what_the_viewer_may_decide(): void
    {
        $this->log($this->dev3, $this->task($this->oth, $this->admin, ['assignee_id' => $this->dev3->id]), 60);
        $this->submit($this->dev3);

        $this->assertCount(0, $this->props($this->actingAsMember($this->tlA)->get('/timesheets/team'))['pending']);
        $this->assertCount(1, $this->props($this->actingAsMember($this->tlB)->get('/timesheets/team'))['pending']);
        $this->assertCount(1, $this->props($this->actingAsMember($this->admin)->get('/timesheets/team'))['pending']);
    }

    public function test_developers_have_no_team_time_page(): void
    {
        $this->actingAsMember($this->dev1)->get('/timesheets/team')->assertForbidden();
    }

    // ---- on the task ---------------------------------------------------------------------------

    public function test_the_task_lists_my_entries_and_my_team_s_for_a_lead(): void
    {
        $task = $this->dev1Task();
        $this->log($this->dev1, $task, 60);
        $this->log($this->tlA, $task, 30);

        $own = $this->props($this->actingAsMember($this->dev1)->get('/tasks/'.$this->key($task)));
        $lead = $this->props($this->actingAsMember($this->tlA)->get('/tasks/'.$this->key($task)));

        $this->assertSame([60], array_column($own['worklogs'], 'minutes'));
        $this->assertEqualsCanonicalizing([60, 30], array_column($lead['worklogs'], 'minutes'));
        $this->assertTrue($own['abilities']['log_work']);
        $this->assertFalse($own['worklogs'][0]['locked']);
    }

    public function test_the_task_marks_locked_entries_and_hides_time_from_externals(): void
    {
        $task = $this->dev1Task();
        $this->log($this->dev1, $task, 60);
        $this->submit($this->dev1);

        $own = $this->props($this->actingAsMember($this->dev1)->get('/tasks/'.$this->key($task)));
        $this->assertTrue($own['worklogs'][0]['locked']);
        $this->assertFalse($own['worklogs'][0]['can_edit']);

        $external = $this->props($this->actingAsMember($this->client)->get('/tasks/'.$this->key($this->task($this->ecp, $this->client))));
        $this->assertSame([], $external['worklogs']);
        $this->assertFalse($external['abilities']['log_work']);
    }

    public function test_an_epic_adds_up_the_estimates_and_time_of_everything_under_it(): void
    {
        $epic = $this->task($this->ecp, $this->pm, ['type' => 'epic']);
        $a = $this->task($this->ecp, $this->pm, ['parent_id' => $epic->id, 'original_estimate_min' => 120, 'remaining_estimate_min' => 120, 'assignee_id' => $this->dev1->id]);
        $b = $this->task($this->ecp, $this->pm, ['parent_id' => $epic->id, 'original_estimate_min' => 60, 'remaining_estimate_min' => 60, 'assignee_id' => $this->dev1->id]);
        $sub = $this->task($this->ecp, $this->pm, ['type' => 'subtask', 'parent_id' => $a->id, 'original_estimate_min' => 30, 'remaining_estimate_min' => 30]);
        $this->log($this->dev1, $a, 45);
        $this->log($this->dev1, $b, 15);

        $rollup = $this->props($this->actingAsMember($this->pm)->get('/tasks/'.$this->key($epic)))['rollup'];

        $this->assertSame(['original' => 210, 'spent' => 60, 'remaining' => 150, 'tasks' => 3], $rollup);
        $this->assertNull($this->props($this->actingAsMember($this->pm)->get('/tasks/'.$this->key($a)))['rollup']);
        $this->assertNotNull($sub);
    }

    // ---- service-level -------------------------------------------------------------------------

    public function test_the_service_recalculates_totals_after_every_change(): void
    {
        $task = $this->dev1Task();
        $this->enterCompany($this->dev1);
        $logs = app(WorkLogService::class);

        $a = $logs->create($task, $this->dev1, ['work_date' => today()->toDateString(), 'minutes' => 30]);
        $logs->create($task, $this->dev1, ['work_date' => today()->toDateString(), 'minutes' => 20]);
        $this->assertSame(50, $this->sheet($this->dev1)->total_min);

        $logs->delete($a, $this->dev1);
        $this->assertSame(20, $this->sheet($this->dev1)->total_min);
        $this->assertSame(20, app(TimesheetService::class)->grid($this->dev1, Timesheet::weekStartOf(today()))['total']);
    }
}
