<?php

namespace Tests\Feature\Tasks;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Services\PermissionService;
use App\Services\ProjectService;
use App\Services\TaskNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

class TaskCreateTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    private function create(array $data = [], ?Membership $by = null, ?Project $project = null)
    {
        return $this->actingAsMember($by ?? $this->pm)->post('/tasks', $data + [
            'project_id' => ($project ?? $this->ecp)->id,
            'title' => 'Payment page shows wrong total',
        ]);
    }

    private function last(?Project $project = null): Task
    {
        return Task::withoutGlobalScopes()->where('project_id', ($project ?? $this->ecp)->id)->latest('id')->firstOrFail();
    }

    // ---- the basics ------------------------------------------------------------------------

    public function test_a_task_is_created_with_the_next_key_and_sensible_defaults(): void
    {
        $this->create()->assertSessionHasNoErrors()->assertRedirect('/tasks/ECP-1');

        $task = $this->last();
        $this->assertSame(1, $task->number);
        $this->assertSame('ECP-1', $task->key_label);
        $this->assertSame('task', $task->type);
        $this->assertSame(3, $task->priority);
        $this->assertSame($this->pm->id, $task->reporter_id);
        $this->assertSame($this->pm->id, $task->created_by);
        $this->assertNull($task->assignee_id);
        $this->assertSame('Backlog', $task->status->name);
        $this->assertSame($this->org->id, $task->organization_id);
        $this->assertNull($task->completed_at);
        $this->assertSame(1, $this->ecp->fresh()->task_counter);
    }

    public function test_numbers_run_per_project_and_are_never_reused(): void
    {
        $this->create();
        $this->create();
        $this->create([], $this->tlB, $this->oth);
        $this->last()->delete(); // ECP-2 goes away
        $this->create();

        $this->assertSame([1, 2, 3], Task::withoutGlobalScopes()->withTrashed()->where('project_id', $this->ecp->id)->orderBy('number')->pluck('number')->all());
        $this->assertSame([1], Task::withoutGlobalScopes()->where('project_id', $this->oth->id)->pluck('number')->all());
    }

    public function test_the_counter_is_read_from_the_database_so_stale_copies_cannot_hand_out_the_same_number(): void
    {
        $service = app(TaskNumberService::class);
        $a = Project::withoutGlobalScopes()->find($this->ecp->id);
        $b = Project::withoutGlobalScopes()->find($this->ecp->id); // loaded before A moved the counter

        $this->assertSame(1, $service->next($a));
        $this->assertSame(2, $service->next($b));
        $this->assertSame(3, $service->next($a));
        $this->assertSame(3, $this->ecp->fresh()->task_counter);
    }

    public function test_the_reporter_and_assignee_follow_the_task(): void
    {
        $this->create(['assignee_id' => $this->dev1->id]);

        $watchers = $this->last()->watchers()->pluck('memberships.id')->all();
        $this->assertEqualsCanonicalizing([$this->pm->id, $this->dev1->id], $watchers);
    }

    public function test_every_field_can_be_set_on_creation(): void
    {
        $label = $this->ecp->labels()->create(['name' => 'Payments', 'color' => '#ef4444']);
        $epic = $this->task($this->ecp, $this->pm, ['type' => 'epic']);

        $this->create([
            'type' => 'bug', 'description' => '<p>Total is <strong>wrong</strong></p>', 'priority' => 5, 'assignee_id' => $this->dev1->id,
            'parent_id' => $epic->id, 'labels' => [$label->id], 'story_points' => 3.5, 'original_estimate_min' => 90,
            'start_date' => '2026-11-01', 'due_date' => '2026-11-15',
        ])->assertSessionHasNoErrors();

        $task = $this->last();
        $this->assertSame(['bug', 5, $epic->id, 90, 90], [$task->type, $task->priority, $task->parent_id, $task->original_estimate_min, $task->remaining_estimate_min]);
        $this->assertEquals(3.5, $task->story_points);
        $this->assertSame('2026-11-15', $task->due_date->toDateString());
        $this->assertSame([$label->id], $task->labels()->pluck('labels.id')->all());
        $this->assertSame('<p>Total is <strong>wrong</strong></p>', $task->description);
    }

    public function test_the_creation_is_logged_in_the_history(): void
    {
        $this->create();

        $activity = $this->last()->activities()->get();
        $this->assertSame(['created'], $activity->pluck('event')->all());
        $this->assertSame($this->pm->id, $activity->first()->membership_id);
        $this->assertSame('Payment page shows wrong total', $activity->first()->meta['title']);
    }

    public function test_files_can_be_attached_while_creating(): void
    {
        Storage::fake('local');

        $this->create(['files' => [UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'), UploadedFile::fake()->image('shot.png')]])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $this->last()->attachments()->count());
    }

    public function test_a_bad_file_stops_the_whole_request(): void
    {
        Storage::fake('local');

        $this->create(['files' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertSessionHasErrors('file');
    }

    // ---- the starting status ---------------------------------------------------------------

    public function test_team_members_start_in_the_backlog(): void
    {
        $this->create([], $this->tlA);

        $this->assertSame('Backlog', $this->last()->status->name);
    }

    public function test_requests_from_external_stakeholders_go_to_triage(): void
    {
        $this->create([], $this->client)->assertSessionHasNoErrors();

        $task = $this->last();
        $this->assertSame('Triage', $task->status->name);
        $this->assertSame('triage', $task->status->category);
        $this->assertSame($this->client->id, $task->reporter_id);
    }

    public function test_triage_can_be_switched_off_or_widened(): void
    {
        $this->ecp->forceFill(['triage_mode' => 'off'])->save();
        $this->create([], $this->client);
        $this->assertSame('Backlog', $this->last()->status->name);

        // All stakeholders: an internal stakeholder (project role stakeholder) now goes through triage, a team member does not.
        $this->ecp->forceFill(['triage_mode' => 'all_stakeholders'])->save();
        app(ProjectService::class)->addMember($this->ecp, $this->stakeholder, 'stakeholder');
        $this->create([], $this->stakeholder);
        $this->assertSame('Triage', $this->last()->status->name);
        $this->create([], $this->tlA);
        $this->assertSame('Backlog', $this->last()->status->name);

        // External only: the internal stakeholder skips it.
        $this->ecp->forceFill(['triage_mode' => 'external_only'])->save();
        $this->create([], $this->stakeholder);
        $this->assertSame('Backlog', $this->last()->status->name);
    }

    public function test_the_status_follows_the_projects_own_workflow(): void
    {
        $this->ecp->statuses()->where('name', 'Backlog')->update(['is_initial' => false]);
        $this->ecp->statuses()->where('name', 'To Do')->update(['is_initial' => true]);

        $this->create();

        $this->assertSame('To Do', $this->last()->status->name);
    }

    public function test_a_request_with_no_triage_status_falls_back_to_the_starting_status(): void
    {
        $this->ecp->statuses()->where('category', 'triage')->delete();

        $this->create([], $this->client);

        $this->assertSame('Backlog', $this->last()->status->name);
    }

    // ---- who can create --------------------------------------------------------------------

    public function test_only_project_members_with_a_working_role_can_create(): void
    {
        $this->create([], $this->dev2)->assertForbidden(); // not on the project
        $this->create([], $this->dev3)->assertForbidden(); // on another project
        $this->create([], $this->stakeholder)->assertForbidden();
        $this->assertSame(0, Task::withoutGlobalScopes()->count());

        app(ProjectService::class)->addMember($this->ecp, $this->dev2, 'viewer');
        $this->create([], $this->dev2)->assertForbidden(); // viewers are read-only

        app(ProjectService::class)->addMember($this->oth, $this->client, 'viewer');
        $this->create([], $this->client, $this->oth)->assertForbidden();

        $this->create([], $this->dev1)->assertSessionHasNoErrors();
        $this->create([], $this->client)->assertSessionHasNoErrors(); // a stakeholder may raise requests
    }

    public function test_the_company_admin_needs_to_be_on_the_project_too(): void
    {
        $this->create([], $this->admin)->assertForbidden();

        app(ProjectService::class)->addMember($this->ecp, $this->admin, 'member');
        $this->create([], $this->admin)->assertSessionHasNoErrors();
    }

    public function test_nothing_can_be_added_to_an_archived_project(): void
    {
        $this->ecp->forceFill(['status' => Project::ARCHIVED])->save();

        $this->create()->assertForbidden();
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
    }

    public function test_the_permission_is_needed_too(): void
    {
        app(PermissionService::class)->override($this->dev1, 'task.create', 'revoke');

        $this->create([], $this->dev1)->assertForbidden();
    }

    public function test_a_project_the_person_cannot_see_is_off_limits(): void
    {
        $this->create([], $this->dev1, $this->oth)->assertForbidden();
        $this->create(['project_id' => 99999], $this->dev1)->assertNotFound();
    }

    public function test_a_project_of_another_company_is_not_found(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $foreign = app(ProjectService::class)->create($other, $otherAdmin, ['name' => 'Theirs', 'key' => 'THR']);

        $this->create([], $this->admin, $foreign)->assertNotFound();
    }

    // ---- validation ------------------------------------------------------------------------

    public function test_the_input_is_validated(): void
    {
        $this->create(['title' => ''])->assertSessionHasErrors('title');
        $this->create(['title' => str_repeat('x', 256)])->assertSessionHasErrors('title');
        $this->create(['type' => 'saga'])->assertSessionHasErrors('type');
        $this->create(['priority' => 9])->assertSessionHasErrors('priority');
        $this->create(['story_points' => -1])->assertSessionHasErrors('story_points');
        $this->create(['original_estimate_min' => -5])->assertSessionHasErrors('original_estimate_min');
        $this->create(['start_date' => 'soon'])->assertSessionHasErrors('start_date');
        $this->create(['start_date' => '2026-12-01', 'due_date' => '2026-11-01'])->assertSessionHasErrors('due_date');
        $this->assertSame(0, Task::withoutGlobalScopes()->count());
    }

    public function test_labels_must_belong_to_the_project(): void
    {
        $foreign = $this->oth->labels()->create(['name' => 'Theirs', 'color' => '#ffffff']);

        $this->create(['labels' => [$foreign->id]])->assertSessionHasErrors('labels');
    }

    public function test_the_hierarchy_of_types_is_enforced(): void
    {
        $epic = $this->task($this->ecp, $this->pm, ['type' => 'epic']);
        $story = $this->task($this->ecp, $this->pm, ['type' => 'story']);
        $plain = $this->task($this->ecp, $this->pm, ['type' => 'task']);
        $subtask = $this->task($this->ecp, $this->pm, ['type' => 'subtask', 'parent_id' => $plain->id]);
        $foreign = $this->task($this->oth, $this->tlB, ['type' => 'epic']);

        // Allowed shapes.
        $this->create(['type' => 'story', 'parent_id' => $epic->id])->assertSessionHasNoErrors();
        $this->create(['type' => 'subtask', 'parent_id' => $story->id])->assertSessionHasNoErrors();
        $this->create(['type' => 'subtask', 'parent_id' => $plain->id])->assertSessionHasNoErrors();
        $this->create(['type' => 'task'])->assertSessionHasNoErrors();

        // Refused shapes.
        $this->create(['type' => 'subtask'])->assertSessionHasErrors('parent_id'); // needs a parent
        $this->create(['type' => 'subtask', 'parent_id' => $epic->id])->assertSessionHasErrors('parent_id'); // not under an epic
        $this->create(['type' => 'subtask', 'parent_id' => $subtask->id])->assertSessionHasErrors('parent_id'); // no deeper than one level
        $this->create(['type' => 'task', 'parent_id' => $plain->id])->assertSessionHasErrors('parent_id'); // use a sub-task
        $this->create(['type' => 'epic', 'parent_id' => $epic->id])->assertSessionHasErrors('parent_id');
        $this->create(['type' => 'task', 'parent_id' => $foreign->id])->assertSessionHasErrors('parent_id'); // other project
    }

    public function test_the_description_is_sanitised(): void
    {
        $this->create(['description' => '<p onclick="x()">Hi <script>alert(1)</script><a href="javascript:alert(1)">bad</a> <a href="https://ok.test">ok</a></p>']);

        $html = $this->last()->description;
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="https://ok.test"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $html);
    }

    // ---- assigning while creating -----------------------------------------------------------

    public function test_assignment_rules_apply_on_creation(): void
    {
        $this->create(['assignee_id' => $this->dev3->id])->assertSessionHasErrors('assignee_id'); // not on the project
        $this->create(['assignee_id' => $this->client->id])->assertSessionHasErrors('assignee_id'); // external
        $this->create(['assignee_id' => $this->pm->id])->assertSessionHasNoErrors(); // the PM, themself
        $this->create(['assignee_id' => $this->dev1->id], $this->dev1)->assertSessionHasNoErrors(); // self-assign
        $this->create(['assignee_id' => $this->tlA->id], $this->dev1)->assertSessionHasErrors('assignee_id'); // a developer cannot assign others
    }

    // ---- external stakeholders ---------------------------------------------------------------

    public function test_external_stakeholders_cannot_set_effort_assignee_or_epics(): void
    {
        $this->create(['type' => 'epic'], $this->client)->assertSessionHasErrors('type');

        $this->create(['story_points' => 8, 'original_estimate_min' => 600, 'assignee_id' => $this->dev1->id], $this->client)->assertSessionHasNoErrors();

        $task = $this->last();
        $this->assertNull($task->story_points);
        $this->assertNull($task->original_estimate_min);
        $this->assertNull($task->assignee_id);
    }

    // ---- the dialog's data -----------------------------------------------------------------

    public function test_the_create_dialog_offers_only_projects_the_person_can_add_to(): void
    {
        $this->actingAsMember($this->dev1)->getJson('/tasks/create-options')->assertOk()
            ->assertJsonCount(1, 'projects')->assertJsonPath('projects.0.key', 'ECP')->assertJsonPath('project', null);

        $this->actingAsMember($this->pm)->getJson('/tasks/create-options')->assertJsonCount(1, 'projects');
        $this->actingAsMember($this->dev2)->getJson('/tasks/create-options')->assertOk()->assertJsonCount(0, 'projects');
    }

    public function test_the_create_dialog_describes_the_chosen_project(): void
    {
        $label = $this->ecp->labels()->create(['name' => 'UI', 'color' => '#3b82f6']);

        $this->actingAsMember($this->pm)->getJson("/tasks/create-options?project_id={$this->ecp->id}")->assertOk()
            ->assertJsonPath('project.key', 'ECP')->assertJsonPath('project.triage', false)->assertJsonPath('effort', true)
            ->assertJsonPath('project.labels.0.name', 'UI')
            ->assertJson(fn ($json) => $json->has('project.assignees')->etc());

        $this->actingAsMember($this->client)->getJson("/tasks/create-options?project_id={$this->ecp->id}")
            ->assertJsonPath('project.triage', true)->assertJsonPath('effort', false)->assertJsonPath('project.assignees', [])
            ->assertJsonMissing(['epic']);
        $this->assertNotNull($label);
    }

    public function test_the_dialog_ignores_a_project_the_person_cannot_use(): void
    {
        $this->actingAsMember($this->dev1)->getJson("/tasks/create-options?project_id={$this->oth->id}")->assertOk()->assertJsonPath('project', null);
    }

    public function test_the_dialog_needs_the_create_permission(): void
    {
        app(PermissionService::class)->override($this->dev1, 'task.create', 'revoke');

        $this->actingAsMember($this->dev1)->getJson('/tasks/create-options')->assertForbidden();
    }

    // ---- bookkeeping -----------------------------------------------------------------------

    public function test_a_new_task_has_a_rank_after_the_others(): void
    {
        $this->create();
        $this->create();

        $ranks = Task::withoutGlobalScopes()->where('project_id', $this->ecp->id)->orderBy('number')->pluck('backlog_rank')->map(fn ($r) => (float) $r)->all();
        $this->assertTrue($ranks[1] > $ranks[0]);
    }

    public function test_the_project_membership_row_is_untouched(): void
    {
        $this->create();

        $this->assertSame(4, ProjectMember::where('project_id', $this->ecp->id)->whereNull('left_at')->count());
    }
}
