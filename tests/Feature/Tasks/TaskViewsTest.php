<?php

namespace Tests\Feature\Tasks;

use App\Models\Label;
use App\Models\Membership;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\StatusTransition;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Board, list, backlog, bulk actions, saved filters and the team view (Phase 6). */
class TaskViewsTest extends TestCase
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

    private function statusId(string $name, ?Project $project = null): int
    {
        return ($project ?? $this->ecp)->statuses()->where('name', $name)->firstOrFail()->id;
    }

    private function key(Task $t): string
    {
        return $t->project->key.'-'.$t->number;
    }

    /** @return list<string> */
    private function keys(array $rows): array
    {
        return array_map(fn ($r) => $r['key'], $rows);
    }

    private function pageOf(Membership $by, string $path): array
    {
        return $this->props($this->actingAsMember($by)->get("/projects/{$this->ecp->id}/{$path}"));
    }

    // ---- board ---------------------------------------------------------------------------------

    public function test_the_board_has_a_column_per_visible_status_and_the_visible_cards(): void
    {
        $this->ecp->update(['uses_sprints' => false]);
        $a = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        $b = $this->task($this->ecp, $this->pm, ['status' => 'In Progress']);
        $this->task($this->oth, $this->pm);

        $props = $this->pageOf($this->pm, 'board');

        $this->assertContains('To Do', array_column($props['columns'], 'name'));
        $this->assertEqualsCanonicalizing([$this->key($a), $this->key($b)], $this->keys($props['tasks']));
        $this->assertSame([], $props['lanes']);
    }

    public function test_a_status_hidden_from_the_board_has_no_column(): void
    {
        $this->ecp->statuses()->where('name', 'To Do')->firstOrFail()->update(['show_on_board' => false]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $props = $this->pageOf($this->pm, 'board');

        $this->assertNotContains('To Do', array_column($props['columns'], 'name'));
        $this->assertSame([], $props['tasks']);
    }

    public function test_board_filters_narrow_the_cards(): void
    {
        $this->ecp->update(['uses_sprints' => false]);
        $mine = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->tlA->id, 'type' => 'bug']);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);

        $props = $this->pageOf($this->pm, "board?assignee[]={$this->tlA->id}&type[]=bug");

        $this->assertSame([$this->key($mine)], $this->keys($props['tasks']));
    }

    public function test_swimlanes_group_by_assignee_with_a_last_lane_for_unassigned(): void
    {
        $this->ecp->update(['uses_sprints' => false]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->tlA->id]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $props = $this->pageOf($this->pm, 'board?swimlane=assignee');

        $this->assertSame('assignee', $props['swimlane']);
        $this->assertCount(2, $props['lanes']);
        $this->assertSame('Unassigned', $props['lanes'][1]['label']);
    }

    public function test_the_board_only_shows_what_the_viewer_may_see(): void
    {
        $this->ecp->update(['uses_sprints' => false]);
        $theirs = $this->task($this->ecp, $this->client, ['status' => 'To Do']);
        $other = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $props = $this->pageOf($this->client, 'board');

        $this->assertContains($this->key($theirs), $this->keys($props['tasks']));
        $this->assertNotContains($this->key($other), $this->keys($props['tasks']));
        $this->assertFalse($props['showEffort']);
    }

    public function test_someone_outside_the_project_cannot_open_its_board(): void
    {
        $this->actingAsMember($this->dev3)->get("/projects/{$this->ecp->id}/board")->assertForbidden();
    }

    // ---- moving cards --------------------------------------------------------------------------

    public function test_dropping_a_card_in_another_column_changes_its_status_and_position(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        $first = $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'board_position' => 1000]);
        $last = $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'board_position' => 2000]);

        $this->actingAsMember($this->tlA)->postJson("/tasks/{$this->key($task)}/move", [
            'status_id' => $this->statusId('In Progress'), 'prev_id' => $first->id, 'next_id' => $last->id,
        ])->assertOk()->assertJson(['ok' => true, 'position' => 1500.0]);

        $this->assertSame('In Progress', $task->fresh()->status->name);
    }

    public function test_dropping_at_the_top_and_bottom_of_a_column(): void
    {
        $a = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'board_position' => 1000]);
        $b = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'board_position' => 2000]);
        $to = $this->statusId('To Do');

        $this->actingAsMember($this->tlA)->postJson("/tasks/{$this->key($b)}/move", ['status_id' => $to, 'prev_id' => null, 'next_id' => $a->id])
            ->assertOk()->assertJson(['position' => 0.0]);
        $this->actingAsMember($this->tlA)->postJson("/tasks/{$this->key($b)}/move", ['status_id' => $to, 'prev_id' => $a->id, 'next_id' => null])
            ->assertOk()->assertJson(['position' => 2000.0]);
    }

    public function test_a_column_is_renumbered_when_neighbours_have_no_room(): void
    {
        $a = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'board_position' => 1.0]);
        $b = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'board_position' => 1.0000001]);
        $c = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'board_position' => 5000]);

        $this->actingAsMember($this->tlA)->postJson("/tasks/{$this->key($c)}/move", ['status_id' => $this->statusId('To Do'), 'prev_id' => $a->id, 'next_id' => $b->id])->assertOk();

        $order = Task::withoutGlobalScopes()->where('project_id', $this->ecp->id)->orderBy('board_position')->pluck('id')->all();
        $this->assertSame([$a->id, $c->id, $b->id], $order);
    }

    public function test_a_move_the_workflow_does_not_allow_changes_nothing(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        StatusTransition::create(['project_id' => $this->ecp->id, 'from_status_id' => $this->statusId('Backlog'), 'to_status_id' => $this->statusId('To Do')]);

        $this->actingAsMember($this->tlA)->postJson("/tasks/{$this->key($task)}/move", ['status_id' => $this->statusId('Done')])->assertStatus(422);

        $this->assertSame('To Do', $task->fresh()->status->name);
    }

    public function test_a_status_of_another_project_is_rejected(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $this->actingAsMember($this->tlA)->postJson("/tasks/{$this->key($task)}/move", ['status_id' => $this->statusId('In Progress', $this->oth)])->assertNotFound();
    }

    public function test_people_without_access_cannot_move_a_card(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        $to = $this->statusId('In Progress');

        $this->actingAsMember($this->dev3)->postJson("/tasks/{$this->key($task)}/move", ['status_id' => $to])->assertForbidden();
        $this->actingAsMember($this->client)->postJson("/tasks/{$this->key($task)}/move", ['status_id' => $to])->assertForbidden();
    }

    // ---- list ----------------------------------------------------------------------------------

    public function test_the_list_sorts_and_ignores_unknown_sort_keys(): void
    {
        $a = $this->task($this->ecp, $this->pm, ['title' => 'Alpha']);
        $b = $this->task($this->ecp, $this->pm, ['title' => 'Bravo']);

        $asc = $this->pageOf($this->pm, 'tasks?sort=title&dir=asc');
        $desc = $this->pageOf($this->pm, 'tasks?sort=title&dir=desc');
        $bad = $this->pageOf($this->pm, 'tasks?sort=password;drop');

        $this->assertSame([$this->key($a), $this->key($b)], $this->keys($asc['tasks']['data']));
        $this->assertSame([$this->key($b), $this->key($a)], $this->keys($desc['tasks']['data']));
        $this->assertSame('created_at', $bad['sort']);
    }

    public function test_the_list_paginates_and_only_accepts_known_page_sizes(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->task($this->ecp, $this->pm);
        }

        $default = $this->pageOf($this->pm, 'tasks');
        $odd = $this->pageOf($this->pm, 'tasks?per_page=7');
        $fifty = $this->pageOf($this->pm, 'tasks?per_page=50');

        $this->assertCount(25, $default['tasks']['data']);
        $this->assertSame(30, $default['tasks']['total']);
        $this->assertSame(25, $odd['perPage']);
        $this->assertCount(30, $fifty['tasks']['data']);
    }

    public function test_each_list_filter_applies(): void
    {
        $label = Label::create(['organization_id' => $this->org->id, 'project_id' => $this->ecp->id, 'name' => 'Urgent', 'color' => '#ef4444']);
        $bug = $this->task($this->ecp, $this->tlA, ['title' => 'Checkout crash', 'type' => 'bug', 'priority' => 5, 'assignee_id' => $this->dev1->id, 'due_date' => now()->subDays(3)]);
        $bug->labels()->attach($label->id);
        $plain = $this->task($this->ecp, $this->pm, ['title' => 'Write docs']);

        $ids = fn (string $qs) => $this->keys($this->pageOf($this->pm, "tasks?{$qs}")['tasks']['data']);

        $this->assertSame([$this->key($bug)], $ids('q=crash'));
        $this->assertSame([$this->key($bug)], $ids('type[]=bug'));
        $this->assertSame([$this->key($bug)], $ids('priority[]=5'));
        $this->assertSame([$this->key($bug)], $ids("label[]={$label->id}"));
        $this->assertSame([$this->key($bug)], $ids("assignee[]={$this->dev1->id}"));
        $this->assertSame([$this->key($bug)], $ids("reporter[]={$this->tlA->id}"));
        $this->assertSame([$this->key($plain)], $ids('assignee[]=none'));
        $this->assertSame([$this->key($bug)], $ids('overdue=1'));
        $this->assertSame([$this->key($bug)], $ids('due_to='.now()->subDay()->toDateString()));
    }

    public function test_only_my_issues_means_assigned_to_me(): void
    {
        $assigned = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->tlA->id]);
        $this->task($this->ecp, $this->tlA);
        $this->task($this->ecp, $this->pm);

        $rows = $this->pageOf($this->tlA, 'tasks?mine=1')['tasks']['data'];

        $this->assertSame([$this->key($assigned)], $this->keys($rows));
    }

    public function test_external_people_never_get_story_points_in_the_list(): void
    {
        $this->task($this->ecp, $this->client, ['story_points' => 8]);

        $rows = $this->pageOf($this->client, 'tasks')['tasks']['data'];

        $this->assertNull($rows[0]['story_points']);
    }

    // ---- bulk ----------------------------------------------------------------------------------

    private function bulk(Membership $by, array $data)
    {
        return $this->actingAsMember($by)->post("/projects/{$this->ecp->id}/tasks/bulk", $data);
    }

    public function test_bulk_assign_updates_every_task(): void
    {
        $a = $this->task($this->ecp, $this->pm);
        $b = $this->task($this->ecp, $this->pm);

        $this->bulk($this->pm, ['action' => 'assign', 'ids' => [$a->id, $b->id], 'assignee_id' => $this->dev1->id])->assertSessionHas('success');

        $this->assertSame($this->dev1->id, $a->fresh()->assignee_id);
        $this->assertSame($this->dev1->id, $b->fresh()->assignee_id);
    }

    public function test_bulk_status_reports_the_tasks_that_cannot_move_and_still_moves_the_rest(): void
    {
        $ok = $this->task($this->ecp, $this->pm, ['status' => 'Backlog']);
        $stuck = $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $this->bulk($this->pm, ['action' => 'status', 'ids' => [$ok->id, $stuck->id], 'status_id' => $this->statusId('To Do')])->assertSessionHas('bulk_failed');

        $this->assertSame('To Do', $ok->fresh()->status->name);
    }

    public function test_bulk_labels_add_and_remove(): void
    {
        $label = Label::create(['organization_id' => $this->org->id, 'project_id' => $this->ecp->id, 'name' => 'Urgent', 'color' => '#ef4444']);
        $task = $this->task($this->ecp, $this->pm);

        $this->bulk($this->pm, ['action' => 'label_add', 'ids' => [$task->id], 'label_id' => $label->id]);
        $this->assertTrue($task->fresh()->labels->contains($label));

        $this->bulk($this->pm, ['action' => 'label_remove', 'ids' => [$task->id], 'label_id' => $label->id]);
        $this->assertFalse($task->fresh()->labels->contains($label));
    }

    public function test_bulk_delete_soft_deletes(): void
    {
        $task = $this->task($this->ecp, $this->pm);

        $this->bulk($this->pm, ['action' => 'delete', 'ids' => [$task->id]]);

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    public function test_bulk_never_touches_tasks_of_another_project(): void
    {
        $foreign = $this->task($this->oth, $this->admin);

        $this->bulk($this->pm, ['action' => 'delete', 'ids' => [$foreign->id]])->assertSessionHas('bulk_failed');

        $this->assertNotSoftDeleted('tasks', ['id' => $foreign->id]);
    }

    public function test_bulk_needs_the_permission_and_a_sensible_selection(): void
    {
        $task = $this->task($this->ecp, $this->pm);

        $this->bulk($this->client, ['action' => 'delete', 'ids' => [$task->id]])->assertForbidden();
        $this->bulk($this->pm, ['action' => 'delete', 'ids' => []])->assertSessionHasErrors('ids');
        $this->bulk($this->pm, ['action' => 'explode', 'ids' => [$task->id]])->assertSessionHasErrors('action');
    }

    // ---- backlog -------------------------------------------------------------------------------

    public function test_the_backlog_lists_not_started_work_in_rank_order_without_sub_tasks(): void
    {
        $second = $this->task($this->ecp, $this->pm, ['backlog_rank' => 2000]);
        $first = $this->task($this->ecp, $this->pm, ['backlog_rank' => 1000]);
        $this->task($this->ecp, $this->pm, ['status' => 'In Progress']);
        $this->task($this->ecp, $this->pm, ['type' => 'subtask', 'parent_id' => $first->id]);

        $props = $this->pageOf($this->pm, 'backlog');

        $this->assertSame([$this->key($first), $this->key($second)], $this->keys($props['tasks']));
        $this->assertTrue($props['canReorder']);
    }

    public function test_dropping_a_task_in_the_backlog_gives_it_a_rank_between_its_neighbours(): void
    {
        $a = $this->task($this->ecp, $this->pm, ['backlog_rank' => 1000]);
        $b = $this->task($this->ecp, $this->pm, ['backlog_rank' => 2000]);
        $c = $this->task($this->ecp, $this->pm, ['backlog_rank' => 3000]);

        $this->actingAsMember($this->pm)->postJson("/tasks/{$this->key($c)}/rank", ['prev_id' => $a->id, 'next_id' => $b->id])->assertOk()->assertJson(['rank' => 1500.0]);
    }

    public function test_reordering_needs_the_backlog_permission_and_an_open_project(): void
    {
        $task = $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->client)->postJson("/tasks/{$this->key($task)}/rank", [])->assertForbidden();

        $this->ecp->update(['status' => Project::ARCHIVED]);
        $this->actingAsMember($this->pm)->postJson("/tasks/{$this->key($task)}/rank", [])->assertForbidden();
    }

    // ---- saved filters -------------------------------------------------------------------------

    private function saveFilter(Membership $by, array $attributes = [])
    {
        return $this->actingAsMember($by)->post('/saved-filters', $attributes + ['project_id' => $this->ecp->id, 'name' => 'Open bugs', 'view' => 'list', 'filters' => ['type' => ['bug']], 'is_shared' => false]);
    }

    public function test_a_private_filter_is_only_seen_by_its_owner_and_a_shared_one_by_the_project(): void
    {
        $this->saveFilter($this->pm, ['name' => 'Mine']);
        $this->saveFilter($this->pm, ['name' => 'Everyone', 'is_shared' => true]);

        $names = fn (Membership $m) => array_column($this->pageOf($m, 'tasks')['savedFilters'], 'name');

        $this->assertEqualsCanonicalizing(['Mine', 'Everyone'], $names($this->pm));
        $this->assertSame(['Everyone'], $names($this->tlA));
    }

    public function test_a_filter_needs_a_name_and_at_least_one_condition(): void
    {
        $this->saveFilter($this->pm, ['name' => ''])->assertSessionHasErrors('name');
        $this->saveFilter($this->pm, ['filters' => ['evil' => 'x']])->assertSessionHasErrors();
        $this->assertSame(0, SavedFilter::count());
    }

    public function test_only_the_owner_can_delete_a_filter(): void
    {
        $this->saveFilter($this->pm, ['is_shared' => true]);
        $filter = SavedFilter::firstOrFail();

        $this->actingAsMember($this->tlA)->delete("/saved-filters/{$filter->id}")->assertNotFound();
        $this->assertSame(1, SavedFilter::count());

        $this->actingAsMember($this->pm)->delete("/saved-filters/{$filter->id}");
        $this->assertSame(0, SavedFilter::count());
    }

    public function test_a_filter_cannot_be_saved_on_a_project_the_person_cannot_see(): void
    {
        $this->saveFilter($this->dev3)->assertForbidden();
    }

    // ---- team ----------------------------------------------------------------------------------

    public function test_the_team_view_groups_open_work_of_everyone_below_the_viewer(): void
    {
        $mine = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'status' => 'In Progress']);
        $this->task($this->oth, $this->admin, ['assignee_id' => $this->dev3->id]);
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'status' => 'Done']);

        $props = $this->props($this->actingAsMember($this->tlA)->get('/team'));

        $this->assertEqualsCanonicalizing([$this->dev1->id, $this->dev2->id], array_column($props['people'], 'id'));
        $dev1 = collect($props['people'])->firstWhere('id', $this->dev1->id);
        $this->assertSame(1, $dev1['open']);
        $this->assertSame([$this->key($mine)], $this->keys($dev1['tasks']));
    }

    public function test_the_team_view_is_for_people_with_reports(): void
    {
        $this->actingAsMember($this->dev1)->get('/team')->assertForbidden();
    }
}
