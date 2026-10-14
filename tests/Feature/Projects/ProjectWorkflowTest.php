<?php

namespace Tests\Feature\Projects;

use App\Models\Label;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\StatusTransition;
use App\Models\Task;
use App\Services\ProjectService;
use App\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProjectWorkflowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
        $this->project = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Portal', 'key' => 'ECP']);
    }

    private function named(string $name): ProjectStatus
    {
        return $this->project->statuses()->where('name', $name)->firstOrFail();
    }

    private function base(): string
    {
        return "/projects/{$this->project->id}/workflow";
    }

    private function act(Membership $m)
    {
        return $this->actingAsMember($m);
    }

    // ---- statuses --------------------------------------------------------------------------

    public function test_the_workflow_page_lists_statuses_in_order(): void
    {
        $this->act($this->admin)->get($this->base())->assertOk()
            ->assertInertia(fn ($page) => $page->component('Projects/Workflow')->has('statuses', 9)
                ->where('statuses.0.name', 'Triage')->where('statuses.1.is_initial', true)->where('statuses.6.category', 'done')
                ->has('transitions', 0)->where('permissions', fn ($p) => collect($p)->contains('task.close') && ! collect($p)->contains('user.invite')));
    }

    public function test_a_status_can_be_added(): void
    {
        $this->act($this->admin)->post("{$this->base()}/statuses", ['name' => 'QA Testing', 'category' => 'in_progress', 'color' => '#06b6d4', 'show_on_board' => true])
            ->assertSessionHasNoErrors();

        $s = $this->named('QA Testing');
        $this->assertSame('in_progress', $s->category);
        $this->assertSame('#06b6d4', $s->color);
        $this->assertSame(9, $s->position, 'new statuses go last');
        $this->assertFalse($s->is_initial);
        $this->assertSame(10, $this->project->statuses()->count());
    }

    public function test_status_input_is_validated(): void
    {
        $post = fn (array $d) => $this->act($this->admin)->post("{$this->base()}/statuses", $d);

        $post(['name' => '', 'category' => 'done'])->assertSessionHasErrors('name');
        $post(['name' => 'X', 'category' => 'sideways'])->assertSessionHasErrors('category');
        $post(['name' => 'X', 'category' => 'todo', 'color' => 'red'])->assertSessionHasErrors('color');
        $post(['name' => str_repeat('a', 51), 'category' => 'todo'])->assertSessionHasErrors('name');
        $post(['name' => 'done', 'category' => 'todo'])->assertSessionHasErrors('name'); // names are unique, ignoring case
        $this->assertSame(9, $this->project->statuses()->count());
    }

    public function test_the_starting_status_can_be_moved_to_another_status(): void
    {
        $todo = $this->named('To Do');

        $this->act($this->admin)->put("{$this->base()}/statuses/{$todo->id}", ['name' => 'To Do', 'category' => 'todo', 'is_initial' => true])->assertSessionHasNoErrors();

        $this->assertSame(['To Do'], $this->project->statuses()->where('is_initial', true)->pluck('name')->all());
    }

    public function test_a_project_always_keeps_one_starting_status(): void
    {
        $backlog = $this->named('Backlog');

        $this->act($this->admin)->put("{$this->base()}/statuses/{$backlog->id}", ['name' => 'Backlog', 'category' => 'todo', 'is_initial' => false])->assertSessionHasErrors('is_initial');
        $this->act($this->admin)->delete("{$this->base()}/statuses/{$backlog->id}")->assertSessionHasErrors('status');
        $this->assertTrue($backlog->fresh()->is_initial);
    }

    public function test_a_status_can_be_renamed_recoloured_and_recategorised(): void
    {
        $review = $this->named('In Review');

        $this->act($this->admin)->put("{$this->base()}/statuses/{$review->id}", ['name' => 'Code Review', 'category' => 'in_progress', 'color' => '#ec4899', 'show_on_board' => false])->assertSessionHasNoErrors();

        $review->refresh();
        $this->assertSame(['Code Review', '#ec4899', false], [$review->name, $review->color, $review->show_on_board]);
    }

    public function test_renaming_to_an_existing_name_is_refused_but_keeping_your_own_is_fine(): void
    {
        $review = $this->named('In Review');

        $this->act($this->admin)->put("{$this->base()}/statuses/{$review->id}", ['name' => 'Blocked', 'category' => 'in_progress'])->assertSessionHasErrors('name');
        $this->act($this->admin)->put("{$this->base()}/statuses/{$review->id}", ['name' => 'in review', 'category' => 'in_progress'])->assertSessionHasNoErrors();
    }

    public function test_the_last_done_status_cannot_be_recategorised_or_deleted(): void
    {
        $done = $this->named('Done');

        $this->act($this->admin)->put("{$this->base()}/statuses/{$done->id}", ['name' => 'Done', 'category' => 'in_progress'])->assertSessionHasErrors('category');
        $this->act($this->admin)->delete("{$this->base()}/statuses/{$done->id}")->assertSessionHasErrors('status');
        $this->assertSame('done', $done->fresh()->category);

        // With a second Done status, one of them may go.
        $this->act($this->admin)->post("{$this->base()}/statuses", ['name' => 'Shipped', 'category' => 'done'])->assertSessionHasNoErrors();
        $this->act($this->admin)->delete("{$this->base()}/statuses/{$done->id}")->assertSessionHasNoErrors();
        $this->assertNull(ProjectStatus::find($done->id));
    }

    public function test_an_unused_status_can_be_deleted_and_takes_its_moves_with_it(): void
    {
        $blocked = $this->named('Blocked');
        $progress = $this->named('In Progress');
        StatusTransition::create(['project_id' => $this->project->id, 'from_status_id' => $progress->id, 'to_status_id' => $blocked->id]);

        $this->act($this->admin)->delete("{$this->base()}/statuses/{$blocked->id}")->assertSessionHasNoErrors();

        $this->assertNull(ProjectStatus::find($blocked->id));
        $this->assertSame(0, StatusTransition::count());
    }

    public function test_a_status_with_tasks_cannot_be_deleted(): void
    {
        $blocked = $this->named('Blocked');
        $make = fn (int $n) => Task::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $this->project->id, 'number' => $n, 'title' => "Task {$n}",
            'status_id' => $blocked->id, 'reporter_id' => $this->admin->id,
        ]);
        $make(1);
        $make(2);
        $make(3)->delete();

        $this->act($this->admin)->delete("{$this->base()}/statuses/{$blocked->id}")->assertSessionHasErrors('status');
        $this->assertSame(2, $blocked->fresh()->taskCount(), 'deleted tasks do not count');
        $this->act($this->admin)->get($this->base())->assertInertia(fn ($page) => $page->where('statuses.5.task_count', 2));
    }

    public function test_statuses_can_be_reordered(): void
    {
        $ids = $this->project->statuses()->pluck('id')->all();
        $reversed = array_reverse($ids);

        $this->act($this->admin)->put("{$this->base()}/order", ['order' => $reversed])->assertSessionHasNoErrors();

        $this->assertSame($reversed, $this->project->statuses()->pluck('id')->all());
        $this->assertSame(range(0, 8), $this->project->statuses()->pluck('position')->all());
    }

    public function test_a_reorder_must_list_every_status_exactly_once(): void
    {
        $ids = $this->project->statuses()->pluck('id')->all();
        $other = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH']);

        $this->act($this->admin)->put("{$this->base()}/order", ['order' => array_slice($ids, 1)])->assertSessionHasErrors('order');
        $this->act($this->admin)->put("{$this->base()}/order", ['order' => [...$ids, $ids[0]]])->assertSessionHasErrors('order');
        $this->act($this->admin)->put("{$this->base()}/order", ['order' => [...array_slice($ids, 1), $other->statuses()->first()->id]])->assertSessionHasErrors('order');
        $this->assertSame($ids, $this->project->statuses()->pluck('id')->all());
    }

    public function test_statuses_of_another_project_cannot_be_touched_through_this_one(): void
    {
        $other = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH']);
        $foreign = $other->statuses()->where('name', 'Blocked')->first();

        $this->act($this->admin)->put("{$this->base()}/statuses/{$foreign->id}", ['name' => 'Hacked', 'category' => 'todo'])->assertNotFound();
        $this->act($this->admin)->delete("{$this->base()}/statuses/{$foreign->id}")->assertNotFound();
        $this->assertSame('Blocked', $foreign->fresh()->name);
    }

    public function test_workflow_changes_need_the_manage_workflow_permission_or_the_project_manager_role(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $tl = $this->makeMember($this->org, 'team-lead'); // has project.manage_workflow
        $service = app(ProjectService::class);
        $service->addMember($this->project, $dev, 'member');
        $service->addMember($this->project, $tl, 'member');

        $this->act($dev)->get($this->base())->assertOk()->assertInertia(fn ($p) => $p->where('project.abilities.manage_workflow', false));
        $this->act($dev)->post("{$this->base()}/statuses", ['name' => 'Nope', 'category' => 'todo'])->assertForbidden();
        $this->act($dev)->put("{$this->base()}/order", ['order' => $this->project->statuses()->pluck('id')->all()])->assertForbidden();
        $this->act($dev)->put("{$this->base()}/transitions", ['moves' => []])->assertForbidden();

        $this->act($tl)->post("{$this->base()}/statuses", ['name' => 'Fine', 'category' => 'todo'])->assertSessionHasNoErrors();

        // A plain member becomes able to once they manage the project.
        $service->changeManager($this->project, $dev->id);
        $this->act($dev)->post("{$this->base()}/statuses", ['name' => 'Manager made', 'category' => 'todo'])->assertSessionHasNoErrors();
    }

    // ---- allowed moves ---------------------------------------------------------------------

    public function test_by_default_every_move_is_allowed(): void
    {
        $service = app(WorkflowService::class);

        $this->assertTrue($service->moveRule($this->project, $this->named('To Do')->id, $this->named('Done')->id));
    }

    public function test_allowed_moves_can_be_saved_and_restrict_everything_else(): void
    {
        $todo = $this->named('To Do');
        $progress = $this->named('In Progress');
        $review = $this->named('In Review');
        $done = $this->named('Done');

        $this->act($this->admin)->put("{$this->base()}/transitions", ['moves' => [
            ['from_status_id' => $todo->id, 'to_status_id' => $progress->id],
            ['from_status_id' => $progress->id, 'to_status_id' => $review->id],
            ['from_status_id' => $review->id, 'to_status_id' => $done->id, 'required_permission' => 'task.close'],
        ]])->assertSessionHasNoErrors();

        $this->assertSame(3, StatusTransition::where('project_id', $this->project->id)->count());

        $service = app(WorkflowService::class);
        $this->assertTrue($service->moveRule($this->project, $todo->id, $progress->id));
        $this->assertFalse($service->moveRule($this->project, $todo->id, $done->id), 'skipping ahead is not allowed');
        $this->assertFalse($service->moveRule($this->project, $progress->id, $todo->id), 'moves are one way unless listed');
        $this->assertSame('task.close', $service->moveRule($this->project, $review->id, $done->id));

        $this->act($this->admin)->get($this->base())->assertInertia(fn ($page) => $page->has('transitions', 3));
    }

    public function test_saving_replaces_the_previous_rules_and_an_empty_list_removes_them(): void
    {
        $todo = $this->named('To Do');
        $progress = $this->named('In Progress');

        $this->act($this->admin)->put("{$this->base()}/transitions", ['moves' => [['from_status_id' => $todo->id, 'to_status_id' => $progress->id]]]);
        $this->act($this->admin)->put("{$this->base()}/transitions", ['moves' => [['from_status_id' => $progress->id, 'to_status_id' => $todo->id]]]);
        $this->assertSame([[$progress->id, $todo->id]], StatusTransition::all()->map(fn ($t) => [$t->from_status_id, $t->to_status_id])->all());

        $this->act($this->admin)->put("{$this->base()}/transitions", ['moves' => []])->assertSessionHasNoErrors();
        $this->assertSame(0, StatusTransition::count());
        $this->assertTrue(app(WorkflowService::class)->moveRule($this->project, $todo->id, $progress->id));
    }

    public function test_invalid_moves_are_rejected_and_change_nothing(): void
    {
        $todo = $this->named('To Do');
        $progress = $this->named('In Progress');
        $other = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH']);
        $foreign = $other->statuses()->first();
        $keep = [['from_status_id' => $todo->id, 'to_status_id' => $progress->id]];
        $this->act($this->admin)->put("{$this->base()}/transitions", ['moves' => $keep]);

        $put = fn (array $moves) => $this->act($this->admin)->put("{$this->base()}/transitions", ['moves' => $moves]);

        $put([['from_status_id' => $todo->id, 'to_status_id' => $todo->id]])->assertSessionHasErrors('moves');
        $put([['from_status_id' => $todo->id, 'to_status_id' => $foreign->id]])->assertSessionHasErrors('moves');
        $put([['from_status_id' => $todo->id, 'to_status_id' => $progress->id], ['from_status_id' => $todo->id, 'to_status_id' => $progress->id]])->assertSessionHasErrors('moves');
        $put([['from_status_id' => $todo->id, 'to_status_id' => $progress->id, 'required_permission' => 'made.up']])->assertSessionHasErrors('moves');
        $put([['from_status_id' => 'x', 'to_status_id' => $progress->id]])->assertSessionHasErrors('moves.0.from_status_id');
        $this->act($this->admin)->put("{$this->base()}/transitions", [])->assertSessionHasErrors('moves');

        $this->assertSame(1, StatusTransition::count(), 'the earlier rules survive a rejected save');
    }

    // ---- settings --------------------------------------------------------------------------

    public function test_settings_can_be_changed(): void
    {
        $this->act($this->admin)->get("/projects/{$this->project->id}/settings")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Projects/Settings')->where('project.uses_sprints', true)->where('project.moves', ['on_hold', 'completed', 'archived']));

        $this->act($this->admin)->put("/projects/{$this->project->id}/settings", [
            'uses_sprints' => false, 'triage_mode' => 'all_stakeholders', 'client_can_see_all' => true, 'allow_self_close' => true, 'progress_mode' => 'points',
        ])->assertSessionHasNoErrors();

        $p = $this->project->fresh();
        $this->assertFalse($p->uses_sprints);
        $this->assertSame('all_stakeholders', $p->triage_mode);
        $this->assertTrue($p->client_can_see_all);
        $this->assertTrue($p->allow_self_close);
        $this->assertSame('points', $p->progress_mode);
    }

    public function test_settings_are_validated(): void
    {
        $valid = ['uses_sprints' => true, 'triage_mode' => 'off', 'client_can_see_all' => false, 'allow_self_close' => false, 'progress_mode' => 'count'];
        $put = fn (array $d) => $this->act($this->admin)->put("/projects/{$this->project->id}/settings", $d);

        $put([...$valid, 'triage_mode' => 'sometimes'])->assertSessionHasErrors('triage_mode');
        $put([...$valid, 'progress_mode' => 'vibes'])->assertSessionHasErrors('progress_mode');
        $put([...$valid, 'uses_sprints' => 'maybe'])->assertSessionHasErrors('uses_sprints');
        $put([])->assertSessionHasErrors(['uses_sprints', 'triage_mode', 'client_can_see_all', 'allow_self_close', 'progress_mode']);
    }

    public function test_settings_need_the_permission_but_anyone_who_can_see_the_project_can_read_them(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        app(ProjectService::class)->addMember($this->project, $dev, 'member');
        $payload = ['uses_sprints' => false, 'triage_mode' => 'off', 'client_can_see_all' => false, 'allow_self_close' => false, 'progress_mode' => 'count'];

        $this->act($dev)->get("/projects/{$this->project->id}/settings")->assertOk();
        $this->act($dev)->put("/projects/{$this->project->id}/settings", $payload)->assertForbidden();
        $this->assertTrue($this->project->fresh()->uses_sprints);
    }

    // ---- labels ----------------------------------------------------------------------------

    public function test_labels_can_be_created_edited_and_deleted(): void
    {
        $base = "/projects/{$this->project->id}/labels";

        $this->act($this->admin)->post($base, ['name' => 'Frontend', 'color' => '#3b82f6'])->assertSessionHasNoErrors();
        $label = Label::sole();
        $this->assertSame([$this->project->id, 'Frontend', '#3b82f6'], [$label->project_id, $label->name, $label->color]);

        $this->act($this->admin)->get($base)->assertOk()->assertInertia(fn ($page) => $page->component('Projects/Labels')->has('labels', 1));

        $this->act($this->admin)->put("{$base}/{$label->id}", ['name' => 'UI', 'color' => '#ec4899'])->assertSessionHasNoErrors();
        $this->assertSame('UI', $label->fresh()->name);

        $this->act($this->admin)->delete("{$base}/{$label->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, Label::count());
    }

    public function test_label_names_are_unique_within_a_project_only(): void
    {
        $other = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH']);
        $this->act($this->admin)->post("/projects/{$this->project->id}/labels", ['name' => 'Bug', 'color' => '#ef4444']);

        $this->act($this->admin)->post("/projects/{$this->project->id}/labels", ['name' => 'Bug', 'color' => '#ef4444'])->assertSessionHasErrors('name');
        $this->act($this->admin)->post("/projects/{$other->id}/labels", ['name' => 'Bug', 'color' => '#ef4444'])->assertSessionHasNoErrors();
        $this->assertSame(2, Label::count());
    }

    public function test_label_input_is_validated(): void
    {
        $post = fn (array $d) => $this->act($this->admin)->post("/projects/{$this->project->id}/labels", $d);

        $post(['name' => '', 'color' => '#ffffff'])->assertSessionHasErrors('name');
        $post(['name' => 'x', 'color' => 'blue'])->assertSessionHasErrors('color');
        $post(['name' => 'x', 'color' => '#fff'])->assertSessionHasErrors('color');
        $post(['name' => str_repeat('a', 51), 'color' => '#ffffff'])->assertSessionHasErrors('name');
        $this->assertSame(0, Label::count());
    }

    public function test_labels_of_another_project_cannot_be_touched_through_this_one(): void
    {
        $other = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH']);
        $foreign = $other->labels()->create(['name' => 'Theirs', 'color' => '#ffffff']);

        $this->act($this->admin)->put("/projects/{$this->project->id}/labels/{$foreign->id}", ['name' => 'Hacked', 'color' => '#000000'])->assertNotFound();
        $this->act($this->admin)->delete("/projects/{$this->project->id}/labels/{$foreign->id}")->assertNotFound();
        $this->assertSame('Theirs', $foreign->fresh()->name);
    }

    public function test_label_management_needs_label_manage_or_the_project_manager_role(): void
    {
        $dev = $this->makeMember($this->org, 'developer'); // no label.manage
        $tl = $this->makeMember($this->org, 'team-lead'); // label.manage
        $service = app(ProjectService::class);
        $service->addMember($this->project, $dev, 'member');
        $service->addMember($this->project, $tl, 'member');
        $base = "/projects/{$this->project->id}/labels";

        $this->act($dev)->get($base)->assertOk();
        $this->act($dev)->post($base, ['name' => 'Nope', 'color' => '#ffffff'])->assertForbidden();
        $this->act($tl)->post($base, ['name' => 'Fine', 'color' => '#ffffff'])->assertSessionHasNoErrors();

        $service->changeManager($this->project, $dev->id);
        $this->act($dev)->post($base, ['name' => 'Now ok', 'color' => '#ffffff'])->assertSessionHasNoErrors();
    }
}
