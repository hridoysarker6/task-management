<?php

namespace Tests\Feature\Sprints;

use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Sprint lifecycle, planning, carry-over, the board and Kanban mode (Phase 7). */
class SprintTest extends TestCase
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

    private function sprint(?Project $project = null, array $attributes = []): Sprint
    {
        return Sprint::withoutGlobalScopes()->create($attributes + [
            'organization_id' => $this->org->id,
            'project_id' => ($project ?? $this->ecp)->id,
            'name' => 'Sprint '.(Sprint::withoutGlobalScopes()->count() + 1),
            'start_date' => today(),
            'end_date' => today()->addDays(13),
            'status' => Sprint::PLANNED,
            'created_by' => $this->pm->id,
        ]);
    }

    private function service(): SprintService
    {
        return app(SprintService::class);
    }

    private function key(Task $t): string
    {
        return $t->project->key.'-'.$t->number;
    }

    private function inSprint(Sprint $sprint, int $count = 1, array $attributes = []): array
    {
        $tasks = [];
        for ($i = 0; $i < $count; $i++) {
            $tasks[] = $this->service()->addTask($sprint, $this->task($this->ecp, $this->pm, $attributes));
        }

        return $tasks;
    }

    private function started(array $attributes = [], int $count = 2): array
    {
        $sprint = $this->sprint();
        $tasks = $this->inSprint($sprint, $count, $attributes);
        $this->service()->start($sprint);

        return [$sprint->fresh(), $tasks];
    }

    // ---- create / edit / delete ----------------------------------------------------------------

    public function test_a_project_manager_creates_edits_and_deletes_a_sprint(): void
    {
        $this->actingAsMember($this->pm)->post("/projects/{$this->ecp->id}/sprints", [
            'name' => 'Sprint 1', 'goal' => 'Ship checkout', 'start_date' => '2026-03-02', 'end_date' => '2026-03-15',
        ])->assertSessionHasNoErrors();

        $sprint = Sprint::firstOrFail();
        $this->assertSame(['Sprint 1', 'planned'], [$sprint->name, $sprint->status]);

        $this->actingAsMember($this->pm)->put("/sprints/{$sprint->id}", ['name' => 'Sprint One', 'goal' => null, 'start_date' => '2026-03-02', 'end_date' => '2026-03-20'])->assertSessionHasNoErrors();
        $this->assertSame('Sprint One', $sprint->fresh()->name);

        $this->actingAsMember($this->pm)->delete("/sprints/{$sprint->id}");
        $this->assertSoftDeleted('sprints', ['id' => $sprint->id]);
    }

    public function test_a_sprint_cannot_end_before_it_starts(): void
    {
        $this->actingAsMember($this->pm)->post("/projects/{$this->ecp->id}/sprints", ['name' => 'Bad', 'start_date' => '2026-03-10', 'end_date' => '2026-03-01'])
            ->assertSessionHasErrors('end_date');
        $this->assertSame(0, Sprint::count());
    }

    public function test_only_people_with_the_permissions_manage_sprints(): void
    {
        $sprint = $this->sprint();
        $data = ['name' => 'X', 'start_date' => '2026-03-02', 'end_date' => '2026-03-15'];

        $this->actingAsMember($this->dev1)->post("/projects/{$this->ecp->id}/sprints", $data)->assertForbidden();
        $this->actingAsMember($this->dev1)->post("/sprints/{$sprint->id}/start")->assertForbidden();
        $this->actingAsMember($this->dev1)->delete("/sprints/{$sprint->id}")->assertForbidden();
        $this->actingAsMember($this->dev3)->get("/projects/{$this->ecp->id}/sprints")->assertForbidden(); // not on the project
        $this->actingAsMember($this->client)->get("/projects/{$this->ecp->id}/sprints")->assertForbidden(); // externals never see sprints
    }

    public function test_a_sprint_of_another_company_is_not_found(): void
    {
        [$org2] = $this->makeCompany();
        $foreign = Sprint::withoutGlobalScopes()->create([
            'organization_id' => $org2->id, 'project_id' => $this->ecp->id, 'name' => 'Theirs', 'start_date' => today(), 'end_date' => today()->addDay(), 'status' => 'planned',
        ]);

        $this->actingAsMember($this->pm)->post("/sprints/{$foreign->id}/start")->assertNotFound();
    }

    public function test_only_a_planned_sprint_can_be_deleted_and_its_tasks_return_to_the_backlog(): void
    {
        $sprint = $this->sprint();
        [$task] = $this->inSprint($sprint);

        $this->actingAsMember($this->pm)->delete("/sprints/{$sprint->id}");

        $this->assertNull($task->fresh()->sprint_id);
        $this->assertSame('removed', DB::table('sprint_task')->where('task_id', $task->id)->value('outcome'));

        [$active] = $this->started();
        $this->actingAsMember($this->pm)->delete("/sprints/{$active->id}")->assertSessionHasErrors('sprint');
        $this->assertNotSoftDeleted('sprints', ['id' => $active->id]);
    }

    // ---- planning ------------------------------------------------------------------------------

    public function test_planning_a_task_moves_it_from_backlog_to_to_do_and_takes_its_sub_tasks(): void
    {
        $sprint = $this->sprint();
        $task = $this->task($this->ecp, $this->pm);
        $child = $this->task($this->ecp, $this->pm, ['type' => 'subtask', 'parent_id' => $task->id]);

        $this->service()->addTask($sprint, $task);

        $this->assertSame($sprint->id, $task->fresh()->sprint_id);
        $this->assertSame($sprint->id, $child->fresh()->sprint_id);
        $this->assertSame('To Do', $task->fresh()->status->name);
        $this->assertSame(2, DB::table('sprint_task')->where('sprint_id', $sprint->id)->count());
    }

    public function test_the_sprint_change_shows_in_the_task_history_by_name(): void
    {
        $sprint = $this->sprint(attributes: ['name' => 'Autumn run']);
        [$task] = $this->inSprint($sprint);

        $line = TaskActivity::where('task_id', $task->id)->where('field', 'sprint')->latest('id')->firstOrFail();

        $this->assertSame('Autumn run', $line->new_value);
    }

    public function test_epics_finished_tasks_triage_requests_and_foreign_tasks_cannot_be_planned(): void
    {
        $sprint = $this->sprint();

        foreach ([
            $this->task($this->ecp, $this->pm, ['type' => 'epic']),
            $this->task($this->ecp, $this->pm, ['status' => 'Done']),
            $this->task($this->ecp, $this->pm, ['status' => 'Triage']),
            $this->task($this->oth, $this->admin),
        ] as $task) {
            try {
                $this->service()->addTask($sprint, $task);
                $this->fail("{$this->key($task)} should not be plannable");
            } catch (ValidationException) {
                $this->assertNull($task->fresh()->sprint_id);
            }
        }
    }

    public function test_dragging_a_task_into_a_sprint_and_back_through_the_endpoint(): void
    {
        $sprint = $this->sprint();
        $task = $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->pm)->postJson("/tasks/{$this->key($task)}/sprint", ['sprint_id' => $sprint->id])->assertOk();
        $this->assertSame($sprint->id, $task->fresh()->sprint_id);

        $this->actingAsMember($this->pm)->postJson("/tasks/{$this->key($task)}/sprint", ['sprint_id' => null])->assertOk();
        $this->assertNull($task->fresh()->sprint_id);
    }

    public function test_dropping_between_two_tasks_sets_the_rank(): void
    {
        $sprint = $this->sprint();
        $a = $this->task($this->ecp, $this->pm, ['backlog_rank' => 1000]);
        $b = $this->task($this->ecp, $this->pm, ['backlog_rank' => 2000]);
        $c = $this->task($this->ecp, $this->pm, ['backlog_rank' => 9000]);
        $this->service()->addTask($sprint, $a);
        $this->service()->addTask($sprint, $b);

        $this->actingAsMember($this->pm)->postJson("/tasks/{$this->key($c)}/sprint", ['sprint_id' => $sprint->id, 'prev_id' => $a->id, 'next_id' => $b->id])->assertOk();

        $this->assertEquals(1500.0, (float) $c->fresh()->backlog_rank);
    }

    public function test_moving_a_task_to_another_sprint_keeps_the_history(): void
    {
        $one = $this->sprint();
        $two = $this->sprint();
        [$task] = $this->inSprint($one);

        $this->service()->addTask($two, $task);

        $this->assertSame($two->id, $task->fresh()->sprint_id);
        $this->assertSame(['removed', null], DB::table('sprint_task')->where('task_id', $task->id)->orderBy('id')->pluck('outcome')->all());
    }

    public function test_the_planning_page_shows_load_per_person_and_the_backlog(): void
    {
        $sprint = $this->sprint();
        $this->service()->addTask($sprint, $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'story_points' => 5, 'original_estimate_min' => 120]));
        $this->service()->addTask($sprint, $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'story_points' => 3, 'remaining_estimate_min' => 60]));
        $this->service()->addTask($sprint, $this->task($this->ecp, $this->pm, ['story_points' => 2]));
        $waiting = $this->task($this->ecp, $this->pm);

        $props = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/sprints"));

        $this->assertSame([$this->key($waiting)], array_column($props['backlog'], 'key'));
        $s = $props['sprints'][0];
        $this->assertEquals([3, 10.0], [$s['task_count'], $s['points']]);
        $dev = collect($s['people'])->firstWhere('id', $this->dev1->id);
        $this->assertEquals([2, 8.0, 3.0], [$dev['tasks'], $dev['points'], $dev['hours']]);
        $this->assertSame('Unassigned', end($s['people'])['name']);
    }

    // ---- start ---------------------------------------------------------------------------------

    public function test_starting_records_what_was_committed(): void
    {
        [$sprint, $tasks] = $this->started(['story_points' => 3]);

        $this->assertSame('active', $sprint->status);
        $this->assertNotNull($sprint->started_at);
        $this->assertEquals(6.0, (float) $sprint->committed_points);
        $this->assertEquals(3.0, (float) DB::table('sprint_task')->where('task_id', $tasks[0]->id)->value('points_at_start'));
    }

    public function test_only_one_sprint_is_active_and_an_empty_sprint_does_not_start(): void
    {
        $this->started();
        $second = $this->sprint();
        $empty = $this->sprint();
        $this->inSprint($second);

        $this->actingAsMember($this->pm)->post("/sprints/{$second->id}/start")->assertSessionHasErrors('sprint');
        $this->assertSame('planned', $second->fresh()->status);

        Sprint::where('status', 'active')->update(['status' => 'completed']);
        $this->actingAsMember($this->pm)->post("/sprints/{$empty->id}/start")->assertSessionHasErrors('sprint');
    }

    // ---- board ---------------------------------------------------------------------------------

    public function test_the_board_shows_only_the_active_sprint(): void
    {
        [$sprint, $tasks] = $this->started();
        $outside = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $props = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/board"));

        $this->assertEqualsCanonicalizing(array_map($this->key(...), $tasks), array_column($props['tasks'], 'key'));
        $this->assertNotContains($this->key($outside), array_column($props['tasks'], 'key'));
        $this->assertSame($sprint->name, $props['sprint']['name']);
    }

    public function test_the_board_is_empty_when_no_sprint_is_running(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $props = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/board"));

        $this->assertSame([], $props['tasks']);
        $this->assertNull($props['sprint']);
    }

    // ---- complete ------------------------------------------------------------------------------

    private function finish(Task $task, string $status): void
    {
        $task->update(['status_id' => $this->ecp->statuses()->where('name', $status)->firstOrFail()->id]);
    }

    public function test_completing_keeps_done_work_and_sends_the_rest_to_the_backlog(): void
    {
        [$sprint, [$done, $open]] = $this->started(['story_points' => 3]);
        $this->finish($done, 'Done');
        $this->finish($open, 'In Progress');

        $this->actingAsMember($this->pm)->post("/sprints/{$sprint->id}/complete")->assertSessionHasNoErrors();

        $sprint->refresh();
        $this->assertSame('completed', $sprint->status);
        $this->assertEquals(3.0, (float) $sprint->completed_points);
        $this->assertSame($sprint->id, $done->fresh()->sprint_id);
        $this->assertNull($open->fresh()->sprint_id);
        $this->assertSame('completed', DB::table('sprint_task')->where('task_id', $done->id)->value('outcome'));
        $this->assertSame('carried_over', DB::table('sprint_task')->where('task_id', $open->id)->value('outcome'));
    }

    public function test_unfinished_tasks_can_be_carried_into_the_next_sprint(): void
    {
        [$sprint, [$done, $open]] = $this->started();
        $next = $this->sprint();
        $this->finish($done, 'Done');

        $this->actingAsMember($this->pm)->post("/sprints/{$sprint->id}/complete", ['next_sprint_id' => $next->id])->assertSessionHasNoErrors();

        $this->assertSame($next->id, $open->fresh()->sprint_id);
        $this->assertSame(['carried_over', null], DB::table('sprint_task')->where('task_id', $open->id)->orderBy('id')->pluck('outcome')->all());
        $this->assertSame('planned', $next->fresh()->status);
    }

    public function test_cancelled_work_is_dropped_from_the_sprint(): void
    {
        [$sprint, [$gone]] = $this->started();
        $this->finish($gone, 'Cancelled');

        $summary = $this->service()->complete($sprint);

        $this->assertSame(1, $summary['dropped']);
        $this->assertNull($gone->fresh()->sprint_id);
    }

    public function test_the_next_sprint_must_be_a_planned_sprint_of_the_same_project(): void
    {
        [$sprint] = $this->started();
        $other = $this->sprint($this->oth);
        $done = $this->sprint(attributes: ['status' => 'completed']);

        $this->actingAsMember($this->pm)->post("/sprints/{$sprint->id}/complete", ['next_sprint_id' => $other->id])->assertSessionHasErrors('next_sprint_id');
        $this->actingAsMember($this->pm)->post("/sprints/{$sprint->id}/complete", ['next_sprint_id' => $done->id])->assertSessionHasErrors('next_sprint_id');
        $this->assertSame('active', $sprint->fresh()->status);
    }

    public function test_only_an_active_sprint_can_be_completed_and_a_completed_one_is_frozen(): void
    {
        $planned = $this->sprint();
        $this->actingAsMember($this->pm)->post("/sprints/{$planned->id}/complete")->assertSessionHasErrors('sprint');

        [$sprint] = $this->started();
        $this->service()->complete($sprint);

        $this->actingAsMember($this->pm)->put("/sprints/{$sprint->id}", ['name' => 'Edited', 'start_date' => '2026-03-02', 'end_date' => '2026-03-20'])->assertSessionHasErrors('sprint');
        $this->actingAsMember($this->pm)->postJson('/tasks/'.$this->key($this->task($this->ecp, $this->pm)).'/sprint', ['sprint_id' => $sprint->id])->assertStatus(422);
    }

    // ---- Kanban mode ---------------------------------------------------------------------------

    public function test_a_kanban_project_hides_every_sprint_screen(): void
    {
        $this->ecp->update(['uses_sprints' => false]);
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/sprints")->assertNotFound();
        $this->actingAsMember($this->pm)->post("/projects/{$this->ecp->id}/sprints", ['name' => 'X', 'start_date' => '2026-03-02', 'end_date' => '2026-03-15'])->assertNotFound();

        $board = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/board"));
        $this->assertSame([$this->key($task)], array_column($board['tasks'], 'key'));
        $this->assertFalse($board['project']['show_sprints']);
        $this->assertSame([], $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/tasks"))['options']['sprints']);
    }

    public function test_sprints_cannot_be_switched_off_while_one_is_active(): void
    {
        $this->started();

        $this->actingAsMember($this->pm)->put("/projects/{$this->ecp->id}/settings", [
            'uses_sprints' => false, 'triage_mode' => 'off', 'client_can_see_all' => false, 'allow_self_close' => false, 'progress_mode' => 'count',
        ])->assertSessionHasErrors('uses_sprints');

        $this->assertTrue($this->ecp->fresh()->uses_sprints);
    }

    // ---- backlog, list and bulk ----------------------------------------------------------------

    public function test_planned_tasks_leave_the_backlog_page(): void
    {
        $sprint = $this->sprint();
        $planned = $this->task($this->ecp, $this->pm);
        $waiting = $this->task($this->ecp, $this->pm);
        $this->service()->addTask($sprint, $planned);

        $props = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/backlog"));

        $this->assertSame([$this->key($waiting)], array_column($props['tasks'], 'key'));
    }

    public function test_the_list_filters_by_sprint_and_shows_its_name(): void
    {
        $sprint = $this->sprint();
        $in = $this->task($this->ecp, $this->pm);
        $out = $this->task($this->ecp, $this->pm);
        $this->service()->addTask($sprint, $in);

        $rows = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/tasks?sprint[]={$sprint->id}"))['tasks']['data'];
        $none = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/tasks?sprint[]=none"))['tasks']['data'];

        $this->assertSame([$this->key($in)], array_column($rows, 'key'));
        $this->assertSame($sprint->name, $rows[0]['sprint']['name']);
        $this->assertSame([$this->key($out)], array_column($none, 'key'));
    }

    public function test_bulk_move_to_a_sprint_and_back(): void
    {
        $sprint = $this->sprint();
        $a = $this->task($this->ecp, $this->pm);
        $b = $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->pm)->post("/projects/{$this->ecp->id}/tasks/bulk", ['action' => 'sprint', 'ids' => [$a->id, $b->id], 'sprint_id' => $sprint->id])->assertSessionHas('success');
        $this->assertSame([$sprint->id, $sprint->id], [$a->fresh()->sprint_id, $b->fresh()->sprint_id]);

        $this->actingAsMember($this->pm)->post("/projects/{$this->ecp->id}/tasks/bulk", ['action' => 'sprint', 'ids' => [$a->id]])->assertSessionHas('success');
        $this->assertNull($a->fresh()->sprint_id);
    }

    public function test_bulk_sprint_reports_tasks_that_cannot_be_planned(): void
    {
        $sprint = $this->sprint();
        $epic = $this->task($this->ecp, $this->pm, ['type' => 'epic']);

        $this->actingAsMember($this->pm)->post("/projects/{$this->ecp->id}/tasks/bulk", ['action' => 'sprint', 'ids' => [$epic->id], 'sprint_id' => $sprint->id])->assertSessionHas('bulk_failed');

        $this->assertNull($epic->fresh()->sprint_id);
    }

    public function test_a_member_without_sprint_permission_cannot_plan_through_bulk(): void
    {
        $sprint = $this->sprint();
        $task = $this->task($this->ecp, $this->dev1, ['assignee_id' => $this->dev1->id]);

        $this->actingAsMember($this->dev1)->post("/projects/{$this->ecp->id}/tasks/bulk", ['action' => 'sprint', 'ids' => [$task->id], 'sprint_id' => $sprint->id])
            ->assertForbidden(); // bulk changes need task.bulk_update

        $this->assertNull($task->fresh()->sprint_id);
    }
}
