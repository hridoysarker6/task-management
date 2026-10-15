<?php

namespace Tests\Feature\Tasks;

use App\Models\Label;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\StatusTransition;
use App\Models\Task;
use App\Support\QuickAddParser;
use App\Support\TaskAbilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Inline editing, the task side panel, dragging across swimlanes and quick add. */
class DailyProductivityTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
        $this->ecp->update(['uses_sprints' => false]);
    }

    private function props(TestResponse $response): array
    {
        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    private function key(Task $t): string
    {
        return $t->project->key.'-'.$t->number;
    }

    private function statusId(string $name, ?Project $project = null): int
    {
        return ($project ?? $this->ecp)->statuses()->where('name', $name)->firstOrFail()->id;
    }

    private function move(Membership $by, Task $task, string $status, array $extra = [])
    {
        return $this->actingAsMember($by)->postJson("/tasks/{$this->key($task)}/move", ['status_id' => $this->statusId($status)] + $extra);
    }

    // ---- what each person may edit inline ------------------------------------------------------

    public function test_the_batched_abilities_agree_with_the_policy_for_every_role(): void
    {
        $tasks = [
            $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]),
            $this->task($this->ecp, $this->dev1),
            $this->task($this->ecp, $this->client),
            $this->task($this->ecp, $this->tlA, ['assignee_id' => $this->tlA->id]),
        ];

        foreach (['pm', 'tlA', 'dev1', 'client', 'hod', 'admin'] as $who) {
            $me = $this->{$who};
            $this->actingAs($me->user);
            $this->enterCompany($me->fresh());
            $abilities = new TaskAbilities($me->fresh(), $this->ecp->fresh());

            foreach (Task::visibleTo($me->fresh())->whereIn('id', collect($tasks)->pluck('id'))->get() as $task) {
                $can = $abilities->of($task);
                $gate = Gate::forUser($me->user);

                $this->assertSame($gate->allows('update', $task), $can['update'], "{$who} update on {$this->key($task)}");
                $this->assertSame($gate->allows('changeStatus', $task), $can['status'], "{$who} status on {$this->key($task)}");
                $this->assertSame($gate->allows('assign', $task), $can['assign'], "{$who} assign on {$this->key($task)}");
            }
        }
    }

    public function test_nothing_is_editable_in_an_archived_project(): void
    {
        $task = $this->task($this->ecp, $this->pm);
        $this->ecp->update(['status' => Project::ARCHIVED]);
        $this->enterCompany($this->pm);

        $this->assertSame(['update' => false, 'status' => false, 'assign' => false], (new TaskAbilities($this->pm->fresh(), $this->ecp->fresh()))->of($task));
    }

    public function test_the_list_and_board_carry_abilities_and_the_people_that_can_be_assigned(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);

        $list = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/tasks"));
        $board = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/board"));

        $this->assertSame(['update' => true, 'status' => true, 'assign' => true], $list['tasks']['data'][0]['can']);
        $this->assertSame(['update' => true, 'status' => true, 'assign' => true], collect($board['tasks'])->firstWhere('key', $this->key($task))['can']);
        $this->assertContains($this->dev1->id, array_column($list['assignees'], 'id'));
        $this->assertSame($list['assignees'], $board['assignees']);
    }

    public function test_a_developer_may_not_edit_someone_else_s_task_inline_and_the_page_says_so(): void
    {
        $theirs = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $row = $this->props($this->actingAsMember($this->dev1)->get("/projects/{$this->ecp->id}/tasks"))['tasks']['data'][0];

        $this->assertFalse($row['can']['update']);
        $this->actingAsMember($this->dev1)->patch("/tasks/{$this->key($theirs)}", ['title' => 'Hijacked'])->assertForbidden();
        $this->assertNotSame('Hijacked', $theirs->fresh()->title);
    }

    public function test_each_inline_field_saves_through_the_existing_endpoints(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'Backlog']);

        $this->actingAsMember($this->pm)->patch("/tasks/{$this->key($task)}", ['title' => 'New title', 'priority' => 5, 'due_date' => today()->addDays(3)->toDateString(), 'story_points' => 8])->assertSessionHasNoErrors();
        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->dev1->id])->assertSessionHasNoErrors();
        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($task)}/status", ['status_id' => $this->statusId('To Do')])->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame(['New title', 5, $this->dev1->id, 'To Do'], [$task->title, $task->priority, $task->assignee_id, $task->status->name]);
        $this->assertEquals(8.0, (float) $task->story_points);
    }

    public function test_a_refused_inline_save_returns_the_server_s_message_and_changes_nothing(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $this->actingAsMember($this->pm)->patch("/tasks/{$this->key($task)}", ['title' => str_repeat('x', 300)])->assertSessionHasErrors('title');
        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->dev3->id])->assertSessionHasErrors();
        $this->assertNull($task->fresh()->assignee_id);
    }

    // ---- the side panel ------------------------------------------------------------------------

    public function test_the_panel_gets_the_same_data_as_the_task_page(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);

        $json = $this->actingAsMember($this->pm)->getJson("/tasks/{$this->key($task)}?panel=1")->assertOk()->json();
        $page = $this->props($this->actingAsMember($this->pm)->get("/tasks/{$this->key($task)}"));

        foreach (['task', 'project', 'options', 'abilities', 'comments', 'activities', 'worklogs', 'children'] as $key) {
            $this->assertArrayHasKey($key, $json);
            $this->assertSame($page[$key], $json[$key], "panel and page differ on {$key}");
        }
    }

    public function test_the_panel_respects_visibility_and_missing_tasks(): void
    {
        $task = $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->dev3)->getJson("/tasks/{$this->key($task)}?panel=1")->assertForbidden(); // not on the project
        $this->actingAsMember($this->pm)->getJson('/tasks/ECP-999?panel=1')->assertNotFound();
        $this->actingAsMember($this->client)->getJson("/tasks/{$this->key($task)}?panel=1")->assertForbidden(); // a client sees only their own
    }

    public function test_the_panel_hides_effort_from_clients_like_the_page_does(): void
    {
        $task = $this->task($this->ecp, $this->client, ['story_points' => 8, 'original_estimate_min' => 120]);

        $json = $this->actingAsMember($this->client)->getJson("/tasks/{$this->key($task)}?panel=1")->assertOk()->json();

        $this->assertNull($json['task']['story_points']);
        $this->assertFalse($json['abilities']['effort']);
    }

    public function test_the_panel_never_crosses_companies(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $this->task($this->ecp, $this->pm);

        $this->actingAsMember($otherAdmin)->getJson('/tasks/ECP-1?panel=1')->assertNotFound();
    }

    // ---- dragging across swimlanes ---------------------------------------------------------------

    public function test_dropping_into_a_person_s_lane_assigns_them_and_changes_the_status(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $this->move($this->tlA, $task, 'In Progress', ['assignee_id' => $this->dev1->id])->assertOk();

        $task->refresh();
        $this->assertSame([$this->dev1->id, 'In Progress'], [$task->assignee_id, $task->status->name]);
    }

    public function test_dropping_into_the_unassigned_lane_clears_the_assignee(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);

        $this->move($this->tlA, $task, 'To Do', ['assignee_id' => null])->assertOk();

        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_a_lane_drop_is_all_or_nothing(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        StatusTransition::create(['project_id' => $this->ecp->id, 'from_status_id' => $this->statusId('Backlog'), 'to_status_id' => $this->statusId('To Do')]);

        // The person may be assigned but the move to Done is not allowed: nothing changes.
        $this->move($this->tlA, $task, 'Done', ['assignee_id' => $this->dev1->id])->assertStatus(422);

        $task->refresh();
        $this->assertSame([null, 'To Do'], [$task->assignee_id, $task->status->name]);
    }

    public function test_a_lane_drop_needs_the_right_to_assign_and_stays_inside_the_assign_scope(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $this->move($this->client, $task, 'In Progress', ['assignee_id' => $this->dev1->id])->assertForbidden();
        $this->move($this->tlA, $task, 'In Progress', ['assignee_id' => $this->dev3->id])->assertStatus(422); // not in the lead's reach
        $this->assertNull($task->fresh()->assignee_id);
    }

    public function test_dropping_into_an_epic_lane_moves_the_task_under_that_epic(): void
    {
        $epic = $this->task($this->ecp, $this->pm, ['type' => 'epic']);
        $other = $this->task($this->ecp, $this->pm, ['type' => 'epic']);
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'parent_id' => $epic->id]);

        $this->move($this->pm, $task, 'To Do', ['parent_id' => $other->id])->assertOk();
        $this->assertSame($other->id, $task->fresh()->parent_id);

        $this->move($this->pm, $task, 'To Do', ['parent_id' => null])->assertOk();
        $this->assertNull($task->fresh()->parent_id);
    }

    public function test_sub_tasks_and_tasks_under_a_story_cannot_change_epic_from_the_board(): void
    {
        $epic = $this->task($this->ecp, $this->pm, ['type' => 'epic']);
        $story = $this->task($this->ecp, $this->pm, ['type' => 'story', 'parent_id' => $epic->id]);
        $sub = $this->task($this->ecp, $this->pm, ['type' => 'subtask', 'parent_id' => $story->id, 'status' => 'To Do']);

        $this->move($this->pm, $sub, 'To Do', ['parent_id' => null])->assertStatus(422);
        $this->assertSame($story->id, $sub->fresh()->parent_id);
    }

    public function test_a_plain_drop_without_lane_fields_behaves_as_before(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);

        $this->move($this->tlA, $task, 'In Progress')->assertOk();

        $task->refresh();
        $this->assertSame([$this->dev1->id, 'In Progress'], [$task->assignee_id, $task->status->name]);
    }

    // ---- quick add -----------------------------------------------------------------------------

    private function quick(Membership $by, array $data)
    {
        return $this->actingAsMember($by)->postJson('/tasks/quick', $data + ['project_id' => $this->ecp->id]);
    }

    public function test_quick_add_creates_a_task_in_the_column_it_was_typed_in(): void
    {
        $this->quick($this->pm, ['text' => 'Fix the footer', 'status_id' => $this->statusId('In Progress')])->assertOk()->assertJson(['ok' => true, 'title' => 'Fix the footer']);

        $task = Task::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['Fix the footer', 'In Progress'], [$task->title, $task->status->name]);
    }

    public function test_quick_add_without_a_column_starts_in_the_usual_status(): void
    {
        $this->quick($this->pm, ['text' => 'Plain one'])->assertOk();

        $this->assertSame('Backlog', Task::withoutGlobalScopes()->latest('id')->firstOrFail()->status->name);
    }

    public function test_quick_add_understands_person_priority_and_label_shortcuts(): void
    {
        $label = Label::create(['project_id' => $this->ecp->id, 'name' => 'Urgent', 'color' => '#ef4444']);

        $this->quick($this->pm, ['text' => "Fix login @{$this->dev1->user->name}"])->assertOk(); // full name has a space: matched by first word only below
        $this->quick($this->pm, ['text' => 'Fix login again @'.strtok($this->dev1->user->name, ' ').' !5 #urgent'])->assertOk();

        $task = Task::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame(['Fix login again', $this->dev1->id, 5], [$task->title, $task->assignee_id, $task->priority]);
        $this->assertSame([$label->id], $task->labels()->pluck('labels.id')->all());
    }

    public function test_quick_add_into_a_sprint_plans_it_there_and_needs_the_sprint_right(): void
    {
        $this->ecp->update(['uses_sprints' => true]);
        $sprint = Sprint::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'project_id' => $this->ecp->id, 'name' => 'S1', 'start_date' => today(), 'end_date' => today()->addDays(13), 'status' => 'planned', 'created_by' => $this->pm->id]);

        $this->quick($this->dev1, ['text' => 'Sneaky', 'sprint_id' => $sprint->id])->assertForbidden(); // developers do not plan sprints
        $this->quick($this->pm, ['text' => 'Planned work', 'sprint_id' => $sprint->id])->assertOk();

        $task = Task::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame([$sprint->id, 'To Do'], [$task->sprint_id, $task->status->name]);
    }

    public function test_quick_add_refuses_a_bad_status_a_foreign_sprint_and_an_archived_project(): void
    {
        $this->quick($this->pm, ['text' => 'x1', 'status_id' => $this->statusId('Triage')])->assertStatus(422);
        $this->quick($this->pm, ['text' => 'x2', 'status_id' => $this->statusId('To Do', $this->oth)])->assertStatus(422);

        $foreign = Sprint::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'project_id' => $this->oth->id, 'name' => 'Other', 'start_date' => today(), 'end_date' => today()->addDay(), 'status' => 'planned', 'created_by' => $this->pm->id]);
        $this->ecp->update(['uses_sprints' => true]);
        $this->quick($this->pm, ['text' => 'x3', 'sprint_id' => $foreign->id])->assertStatus(422);

        $this->ecp->update(['status' => Project::ARCHIVED]);
        $this->quick($this->pm, ['text' => 'x4'])->assertForbidden();
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
    }

    public function test_quick_add_needs_a_title_and_membership_of_the_project(): void
    {
        $this->quick($this->pm, ['text' => ''])->assertStatus(422);
        $this->quick($this->dev3, ['text' => 'Not mine'])->assertNotFound(); // cannot even see the project
    }

    public function test_a_client_quick_adding_still_goes_through_triage_whatever_the_column(): void
    {
        $this->ecp->update(['triage_mode' => 'external_only']);

        $this->quick($this->client, ['text' => 'Please add dark mode', 'status_id' => $this->statusId('Done')])->assertOk()->assertJson(['triage' => true]);

        $this->assertSame('Triage', Task::withoutGlobalScopes()->latest('id')->firstOrFail()->status->name);
    }

    // ---- the quick-add parser --------------------------------------------------------------------

    public function test_the_parser_takes_shortcuts_out_of_the_title(): void
    {
        $people = [['id' => 1, 'name' => 'Rafi Islam'], ['id' => 2, 'name' => 'Nusrat Jahan']];
        $labels = [['id' => 7, 'name' => 'Urgent'], ['id' => 8, 'name' => 'Design']];

        $out = QuickAddParser::parse('Redo the header @rafi !high #urg', $people, $labels);

        $this->assertSame(['title' => 'Redo the header', 'assignee_id' => 1, 'priority' => 4, 'labels' => [7]], $out);
    }

    public function test_an_unknown_or_ambiguous_shortcut_stays_in_the_title(): void
    {
        $people = [['id' => 1, 'name' => 'Rafi Islam'], ['id' => 2, 'name' => 'Rafiq Hasan']];

        $out = QuickAddParser::parse('Email @rafi about #nothing !9', $people, []);

        $this->assertSame('Email @rafi about #nothing !9', $out['title']);
        $this->assertSame([null, null, []], [$out['assignee_id'], $out['priority'], $out['labels']]);
    }

    public function test_a_line_that_is_only_shortcuts_keeps_its_text_as_the_title(): void
    {
        $out = QuickAddParser::parse('!3', [], []);

        $this->assertSame(['!3', 3], [$out['title'], $out['priority']]);
    }

    public function test_an_exact_name_wins_over_a_prefix_match(): void
    {
        $people = [['id' => 1, 'name' => 'Sam'], ['id' => 2, 'name' => 'Samira Khan']];

        $this->assertSame(1, QuickAddParser::parse('Check @sam', $people, [])['assignee_id']);
    }
}
