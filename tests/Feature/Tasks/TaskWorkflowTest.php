<?php

namespace Tests\Feature\Tasks;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\StatusTransition;
use App\Models\Task;
use App\Services\PermissionService;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Status moves, closing and reopening, sub-tasks, and assignment (WORKFLOW.md sections 6.4 to 6.6). */
class TaskWorkflowTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    private function named(string $name, ?Project $project = null): ProjectStatus
    {
        return ($project ?? $this->ecp)->statuses()->where('name', $name)->firstOrFail();
    }

    private function move(Task $task, string $to, ?Membership $by = null, array $extra = [])
    {
        return $this->actingAsMember($by ?? $this->tlA)->post("/tasks/{$this->key($task)}/status", ['status_id' => $this->named($to, $task->project)->id] + $extra);
    }

    private function key(Task $task): string
    {
        return $task->project->key.'-'.$task->number;
    }

    private function assign(Task $task, ?Membership $to, ?Membership $by = null)
    {
        return $this->actingAsMember($by ?? $this->tlA)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $to?->id]);
    }

    // ---- moving ----------------------------------------------------------------------------

    public function test_a_task_moves_through_its_workflow(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->move($task, 'To Do')->assertSessionHasNoErrors();
        $this->move($task, 'In Progress')->assertSessionHasNoErrors();
        $this->move($task, 'In Review')->assertSessionHasNoErrors();

        $this->assertSame('In Review', $task->fresh()->status->name);
    }

    public function test_status_changes_are_written_to_the_history(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->move($task, 'In Progress');

        $line = $task->activities()->where('event', 'status_changed')->first();
        $this->assertSame(['Backlog', 'In Progress'], [$line->old_value, $line->new_value]);
        $this->assertSame($this->tlA->id, $line->membership_id);
    }

    public function test_the_move_goes_to_the_end_of_the_new_column(): void
    {
        $first = $this->task($this->ecp, $this->tlA, ['status' => 'To Do']);
        $second = $this->task($this->ecp, $this->tlA);

        $this->move($second, 'To Do');

        $this->assertTrue((float) $second->fresh()->board_position > (float) $first->fresh()->board_position);
    }

    public function test_the_status_must_belong_to_the_tasks_project(): void
    {
        $task = $this->task($this->ecp, $this->tlA);
        $foreign = $this->named('In Progress', $this->oth);

        $this->actingAsMember($this->tlA)->post('/tasks/ECP-1/status', ['status_id' => $foreign->id])->assertNotFound();
        $this->assertSame('Backlog', $task->fresh()->status->name);
    }

    public function test_moving_to_the_same_status_changes_nothing(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->move($task, 'Backlog')->assertSessionHasNoErrors();

        $this->assertSame(0, $task->activities()->where('event', 'status_changed')->count());
    }

    public function test_viewers_and_people_who_cannot_see_the_task_cannot_move_it(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->move($task, 'To Do', $this->dev3)->assertForbidden(); // other team
        $this->move($task, 'To Do', $this->stakeholder)->assertForbidden(); // not on the project
        $this->move($task, 'To Do', $this->client)->assertForbidden(); // cannot see it (not theirs)
        $this->assertSame('Backlog', $task->fresh()->status->name);
    }

    public function test_the_change_status_permission_is_required(): void
    {
        $task = $this->task($this->ecp, $this->tlA, ['reporter_id' => $this->client->id]);

        // The client sees their own task but only holds task.reopen, not task.change_status.
        $this->move($task, 'To Do', $this->client)->assertSessionHasErrors('status_id');
    }

    // ---- close and reopen ---------------------------------------------------------------------

    public function test_closing_needs_the_close_permission(): void
    {
        $task = $this->task($this->ecp, $this->dev1, ['assignee_id' => $this->dev1->id, 'status' => 'In Review']);

        $this->move($task, 'Done', $this->dev1)->assertSessionHasErrors('status_id'); // developers cannot close
        $this->assertSame('In Review', $task->fresh()->status->name);

        $this->move($task, 'Done', $this->tlA)->assertSessionHasNoErrors(); // a lead can
        $this->assertSame('Done', $task->fresh()->status->name);
    }

    public function test_a_developer_can_close_their_own_task_when_the_project_allows_it(): void
    {
        $this->ecp->forceFill(['allow_self_close' => true])->save();
        $mine = $this->task($this->ecp, $this->dev1, ['assignee_id' => $this->dev1->id, 'status' => 'In Review']);
        $theirs = $this->task($this->ecp, $this->tlA, ['assignee_id' => $this->tlA->id, 'status' => 'In Review']);

        $this->move($mine, 'Done', $this->dev1)->assertSessionHasNoErrors();
        $this->move($theirs, 'Done', $this->dev1)->assertSessionHasErrors('status_id');
    }

    public function test_completed_at_follows_the_status(): void
    {
        $task = $this->task($this->ecp, $this->tlA, ['status' => 'In Review']);

        $this->move($task, 'Done');
        $stamp = $task->fresh()->completed_at;
        $this->assertNotNull($stamp);

        // Moving between two done statuses keeps the original stamp.
        $this->actingAsMember($this->admin)->post('/projects/'.$this->ecp->id.'/workflow/statuses', ['name' => 'Shipped', 'category' => 'done']);
        $this->travelTo(now()->addHour());
        $this->move($task, 'Shipped');
        $this->assertEquals($stamp, $task->fresh()->completed_at);

        // Reopening clears it.
        $this->move($task, 'In Progress');
        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_reopening_needs_the_reopen_permission_and_stakeholders_have_it(): void
    {
        $task = $this->task($this->ecp, $this->client, ['status' => 'Done', 'completed_at' => now()]);

        $this->move($task, 'In Progress', $this->dev1)->assertSessionHasErrors('status_id'); // devs cannot reopen
        $this->move($task, 'In Progress', $this->client)->assertSessionHasNoErrors(); // "not fixed"
        $this->assertSame('In Progress', $task->fresh()->status->name);
        $this->assertNull($task->fresh()->completed_at);
    }

    // ---- allowed moves -----------------------------------------------------------------------

    public function test_the_projects_allowed_moves_are_enforced(): void
    {
        $task = $this->task($this->ecp, $this->tlA);
        StatusTransition::create(['project_id' => $this->ecp->id, 'from_status_id' => $this->named('Backlog')->id, 'to_status_id' => $this->named('To Do')->id]);

        $this->move($task, 'To Do')->assertSessionHasNoErrors();
        $this->move($task, 'Done')->assertSessionHasErrors('status_id');
        $this->move($task, 'Backlog')->assertSessionHasErrors('status_id'); // one way only
        $this->assertSame('To Do', $task->fresh()->status->name);
    }

    public function test_a_move_can_require_a_permission(): void
    {
        $task = $this->task($this->ecp, $this->tlA);
        StatusTransition::create([
            'project_id' => $this->ecp->id, 'from_status_id' => $this->named('Backlog')->id, 'to_status_id' => $this->named('To Do')->id,
            'required_permission' => 'task.bulk_update',
        ]);

        $this->move($task, 'To Do', $this->dev1)->assertSessionHasErrors('status_id'); // lacks task.bulk_update
        $this->move($task, 'To Do', $this->tlA)->assertSessionHasNoErrors();
    }

    public function test_the_status_menu_explains_what_is_not_possible(): void
    {
        $task = $this->task($this->ecp, $this->dev1, ['assignee_id' => $this->dev1->id]);

        $this->actingAsMember($this->dev1)->get('/tasks/ECP-1')->assertInertia(function ($page) {
            $page->where('options.statuses', function ($menu) {
                $menu = collect($menu)->keyBy('name');

                return $menu['To Do']['allowed'] === true
                    && $menu['Done']['allowed'] === false && str_contains($menu['Done']['reason'], 'close')
                    && ! $menu->has('Triage');
            });
        });
        $this->assertNotNull($task);
    }

    // ---- triage ----------------------------------------------------------------------------

    public function test_a_request_in_triage_cannot_be_moved_by_hand(): void
    {
        $request = $this->task($this->ecp, $this->client, ['status' => 'Triage']);

        $this->move($request, 'To Do')->assertSessionHasErrors('status_id');
        $this->assertSame('Triage', $request->fresh()->status->name);
    }

    public function test_tasks_cannot_be_moved_back_into_triage(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->actingAsMember($this->tlA)->post('/tasks/ECP-1/status', ['status_id' => $this->named('Triage')->id])->assertSessionHasErrors('status_id');
    }

    // ---- sub-tasks and closing a parent ---------------------------------------------------------

    private function parentWithChildren(): array
    {
        $parent = $this->task($this->ecp, $this->tlA, ['status' => 'In Review']);
        $open = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $parent->id, 'status' => 'In Progress']);
        $done = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $parent->id, 'status' => 'Done', 'completed_at' => now()]);
        $dropped = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $parent->id, 'status' => 'Cancelled']);

        return [$parent, $open, $done, $dropped];
    }

    public function test_a_parent_cannot_be_closed_while_sub_tasks_are_open(): void
    {
        [$parent, $open] = $this->parentWithChildren();

        $this->move($parent, 'Done')->assertSessionHasErrors('open_children');

        $this->assertSame('In Review', $parent->fresh()->status->name);
        $this->assertSame('In Progress', $open->fresh()->status->name);
    }

    public function test_the_warning_names_how_many_are_open(): void
    {
        [$parent] = $this->parentWithChildren();

        $this->move($parent, 'Done')->assertSessionHasErrors(['open_children' => 'This task still has 1 open sub-task(s). Close them as well, or finish them first.']);
    }

    public function test_open_sub_tasks_can_be_closed_together_with_the_parent(): void
    {
        [$parent, $open, $done, $dropped] = $this->parentWithChildren();

        $this->move($parent, 'Done', null, ['close_children' => true])->assertSessionHasNoErrors();

        $this->assertSame('Done', $parent->fresh()->status->name);
        $this->assertSame('Done', $open->fresh()->status->name);
        $this->assertNotNull($open->fresh()->completed_at);
        $this->assertSame('Cancelled', $dropped->fresh()->status->name, 'dropped sub-tasks are left alone');
    }

    public function test_closing_everything_needs_the_close_permission(): void
    {
        [$parent] = $this->parentWithChildren();
        $this->ecp->forceFill(['allow_self_close' => true])->save();
        $parent->forceFill(['assignee_id' => $this->dev1->id])->save();

        $this->move($parent, 'Done', $this->dev1, ['close_children' => true])->assertSessionHasErrors('open_children');
    }

    public function test_a_parent_with_only_finished_sub_tasks_closes_normally(): void
    {
        $parent = $this->task($this->ecp, $this->tlA, ['status' => 'In Review']);
        $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $parent->id, 'status' => 'Done', 'completed_at' => now()]);

        $this->move($parent, 'Done')->assertSessionHasNoErrors();
    }

    // ---- assignment ------------------------------------------------------------------------

    public function test_a_team_lead_assigns_work_to_their_developer(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->assign($task, $this->dev1)->assertSessionHasNoErrors();

        $this->assertSame($this->dev1->id, $task->fresh()->assignee_id);
        $this->assertContains($this->dev1->id, $task->watchers()->pluck('memberships.id')->all(), 'the assignee follows the task');
    }

    public function test_reassigning_is_logged_with_both_names(): void
    {
        $task = $this->task($this->ecp, $this->tlA, ['assignee_id' => $this->dev1->id]);

        $this->assign($task, $this->tlA);

        $line = $task->activities()->where('event', 'assigned')->first();
        $this->assertSame([$this->dev1->user->name, $this->tlA->user->name], [$line->old_value, $line->new_value]);
    }

    public function test_assignment_is_limited_to_the_assigners_scope_unless_they_manage_the_project(): void
    {
        // TL-A (team scope) can assign Dev1 (below them) but not TL-B's people or someone outside the team.
        app(ProjectService::class)->addMember($this->ecp, $this->dev3, 'member');
        $task = $this->task($this->ecp, $this->tlA);

        $this->assign($task, $this->dev3)->assertSessionHasErrors('assignee_id'); // Dev3 is TL-B's
        $this->assign($task, $this->dev1)->assertSessionHasNoErrors();

        // The project manager may pick any project member.
        $this->assign($task, $this->dev3, $this->pm)->assertSessionHasNoErrors();
        $this->assertSame($this->dev3->id, $task->fresh()->assignee_id);
    }

    public function test_only_project_team_members_can_be_assigned(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->assign($task, $this->dev2, $this->pm)->assertSessionHasErrors('assignee_id'); // not on the project
        $this->assign($task, $this->client, $this->pm)->assertSessionHasErrors('assignee_id'); // external
        $viewer = $this->makeMember($this->org, 'developer');
        app(ProjectService::class)->addMember($this->ecp, $viewer, 'viewer');
        $this->assign($task, $viewer, $this->pm)->assertSessionHasErrors('assignee_id'); // a viewer
        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_anyone_on_the_project_can_take_a_task_for_themselves(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->assign($task, $this->dev1, $this->dev1)->assertSessionHasNoErrors();
        $this->assertSame($this->dev1->id, $task->fresh()->assignee_id);
    }

    public function test_a_developer_cannot_hand_work_to_someone_else(): void
    {
        $task = $this->task($this->ecp, $this->tlA);

        $this->assign($task, $this->tlA, $this->dev1)->assertSessionHasErrors('assignee_id');
        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_unassigning(): void
    {
        $task = $this->task($this->ecp, $this->tlA, ['assignee_id' => $this->dev1->id]);

        $this->assign($task, null, $this->dev1)->assertSessionHasNoErrors(); // taking yourself off
        $this->assertNull($task->fresh()->assignee_id);

        $task->forceFill(['assignee_id' => $this->tlA->id])->save();
        $this->assign($task, null, $this->dev1)->assertSessionHasErrors('assignee_id'); // clearing someone else's task
        $this->assign($task, null, $this->tlA)->assertSessionHasNoErrors();
    }

    public function test_the_assignee_list_matches_what_the_person_may_do(): void
    {
        $task = $this->task($this->ecp, $this->tlA);
        app(ProjectService::class)->addMember($this->ecp, $this->dev3, 'member');

        $names = fn (Membership $by) => collect($this->actingAsMember($by)->get('/tasks/ECP-1')->viewData('page')['props']['options']['assignees'])->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$this->pm->id, $this->tlA->id, $this->dev1->id, $this->dev3->id], $names($this->pm)); // manager: everyone
        $this->assertEqualsCanonicalizing([$this->tlA->id, $this->dev1->id], $names($this->tlA)); // team scope
        $this->assertSame([$this->dev1->id], $names($this->dev1)); // only themself
        $this->assertNotNull($task);
    }

    public function test_assigning_when_the_project_is_archived_is_refused(): void
    {
        $task = $this->task($this->ecp, $this->tlA);
        $this->ecp->forceFill(['status' => Project::ARCHIVED])->save();

        $this->assign($task, $this->dev1)->assertForbidden();
    }

    public function test_the_assign_permission_is_needed_to_assign_others(): void
    {
        $task = $this->task($this->ecp, $this->tlA);
        app(PermissionService::class)->override($this->tlA, 'task.assign', 'revoke');

        $this->assign($task, $this->dev1)->assertSessionHasErrors('assignee_id');
    }
}
