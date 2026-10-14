<?php

namespace Tests\Feature\Projects;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Role;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
    }

    /** Create a project through the service. */
    private function project(array $data = [], ?Organization $org = null, ?Membership $by = null): Project
    {
        $org ??= $this->org;
        static $n = 0;
        $n++;

        return app(ProjectService::class)->create($org, $by ?? $this->admin, $data + ['name' => "Project {$n}", 'key' => 'P'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)]);
    }

    private function submit(array $overrides = [], ?Membership $by = null)
    {
        return $this->actingAsMember($by ?? $this->admin)->post('/projects', array_merge(['name' => 'E-Commerce Portal', 'key' => 'ECP'], $overrides));
    }

    // ---- create ----------------------------------------------------------------------------

    public function test_a_project_is_created_with_the_default_workflow(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->submit([
            'description' => 'Online shop', 'department_id' => $dept->id, 'manager_membership_id' => $pm->id,
            'start_date' => '2026-11-01', 'end_date' => '2027-03-31',
        ])->assertSessionHasNoErrors();

        $project = Project::sole();
        $this->assertSame('ECP', $project->key);
        $this->assertSame($this->org->id, $project->organization_id);
        $this->assertSame(Project::ACTIVE, $project->status);
        $this->assertSame($pm->id, $project->manager_membership_id);
        $this->assertSame($this->admin->id, $project->created_by);
        $this->assertSame('2027-03-31', $project->end_date->toDateString());
        $this->assertSame(0, $project->task_counter);

        // Defaults: sprints on, external-only triage, nothing special.
        $this->assertTrue($project->uses_sprints);
        $this->assertSame('external_only', $project->triage_mode);
        $this->assertFalse($project->client_can_see_all);
        $this->assertFalse($project->allow_self_close);
        $this->assertSame('count', $project->progress_mode);

        $statuses = $project->statuses;
        $this->assertSame(['Triage', 'Backlog', 'To Do', 'In Progress', 'In Review', 'Blocked', 'Done', 'Cancelled', 'Rejected'], $statuses->pluck('name')->all());
        $this->assertSame(['Backlog'], $statuses->where('is_initial', true)->pluck('name')->all());
        $this->assertSame(['Done'], $statuses->where('category', 'done')->pluck('name')->all());
        $this->assertSame(['Triage'], $statuses->where('category', 'triage')->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Cancelled', 'Rejected'], $statuses->where('category', 'cancelled')->pluck('name')->all());
        $this->assertSame(range(0, 8), $statuses->pluck('position')->all());
    }

    public function test_the_project_manager_becomes_a_member_with_the_manager_role(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');

        $this->submit(['manager_membership_id' => $pm->id]);

        $row = ProjectMember::sole();
        $this->assertSame($pm->id, $row->membership_id);
        $this->assertSame('manager', $row->project_role);
        $this->assertNull($row->left_at);
        $this->assertNotNull($row->joined_at);
    }

    public function test_the_key_is_upper_cased_and_validated(): void
    {
        $this->submit(['key' => 'web2'])->assertSessionHasNoErrors();
        $this->assertSame('WEB2', Project::sole()->key);

        foreach (['A', 'LONGERTHAN10', '1ABC', 'AB-C', 'A B', ''] as $bad) {
            $this->submit(['key' => $bad, 'name' => 'x'.$bad])->assertSessionHasErrors('key');
        }
        $this->assertSame(1, Project::count());
    }

    public function test_the_key_is_unique_per_company_even_after_deletion(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();

        $this->submit()->assertSessionHasNoErrors();
        $this->submit(['name' => 'Another'])->assertSessionHasErrors('key');
        $this->submit(['key' => 'ecp', 'name' => 'Another'])->assertSessionHasErrors('key');

        // Another company can use the same key.
        $this->actingAsMember($otherAdmin)->post('/projects', ['name' => 'Theirs', 'key' => 'ECP'])->assertSessionHasNoErrors();

        // A deleted project still reserves its key (the database enforces it).
        Project::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('key', 'ECP')->first()->delete();
        $this->submit(['name' => 'Third'])->assertSessionHasErrors('key');
    }

    public function test_required_fields_and_dates_are_validated(): void
    {
        $this->submit(['name' => ''])->assertSessionHasErrors('name');
        $this->submit(['start_date' => '2026-12-01', 'end_date' => '2026-11-01'])->assertSessionHasErrors('end_date');
        $this->submit(['start_date' => 'soon'])->assertSessionHasErrors('start_date');
        $this->assertSame(0, Project::count());
    }

    public function test_department_and_manager_must_belong_to_the_company_and_the_manager_be_internal_and_active(): void
    {
        [$other] = $this->makeCompany();
        $foreignDept = Department::factory()->create(['organization_id' => $other->id]);
        $foreigner = $this->makeMember($other, 'project-manager');
        $client = $this->makeMember($this->org, 'external-stakeholder');
        $gone = $this->makeMember($this->org, 'project-manager');
        $gone->forceFill(['status' => Membership::EXITED])->save();
        $gone->delete();

        $this->submit(['department_id' => $foreignDept->id])->assertSessionHasErrors('department_id');
        $this->submit(['manager_membership_id' => $foreigner->id])->assertSessionHasErrors('manager_membership_id');
        $this->submit(['manager_membership_id' => $client->id])->assertSessionHasErrors('manager_membership_id');
        $this->submit(['manager_membership_id' => $gone->id])->assertSessionHasErrors('manager_membership_id');
        $this->assertSame(0, Project::count());
    }

    public function test_only_people_with_project_create_can_create(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $tl = $this->makeMember($this->org, 'team-lead');
        $pm = $this->makeMember($this->org, 'project-manager');

        $this->submit([], $dev)->assertForbidden();
        $this->submit([], $tl)->assertForbidden();
        $this->actingAsMember($dev)->get('/projects/create')->assertForbidden();
        $this->actingAsMember($pm)->get('/projects/create')->assertOk()->assertInertia(fn ($page) => $page->component('Projects/Form')->where('project', null));
        $this->submit([], $pm)->assertSessionHasNoErrors();
    }

    // ---- visibility (permission + data scope + membership) ---------------------------------

    public function test_a_company_wide_role_sees_every_project(): void
    {
        $this->project();
        $this->project();

        $this->actingAsMember($this->admin)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 2));
    }

    public function test_a_developer_sees_only_projects_they_are_a_member_of(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $mine = $this->project();
        $this->project();
        app(ProjectService::class)->addMember($mine, $dev, 'member');

        $this->actingAsMember($dev)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.id', $mine->id));
        $this->actingAsMember($dev)->get("/projects/{$mine->id}")->assertOk();
    }

    public function test_someone_outside_a_project_cannot_open_any_of_its_pages(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $secret = $this->project();

        foreach (['', '/edit', '/members', '/workflow', '/settings', '/labels'] as $suffix) {
            $this->actingAsMember($dev)->get("/projects/{$secret->id}{$suffix}")->assertForbidden();
        }
    }

    public function test_a_team_lead_sees_projects_their_team_works_on(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead');
        $dev = $this->makeMember($this->org, 'developer', ['reports_to_id' => $tl->id]);
        $outsider = $this->makeMember($this->org, 'developer');

        $teamProject = $this->project();
        $otherProject = $this->project();
        app(ProjectService::class)->addMember($teamProject, $dev, 'member');
        app(ProjectService::class)->addMember($otherProject, $outsider, 'member');

        $this->actingAsMember($tl)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.id', $teamProject->id));
        $this->actingAsMember($tl)->get("/projects/{$otherProject->id}")->assertForbidden();
    }

    public function test_a_team_lead_sees_projects_managed_by_someone_in_their_team(): void
    {
        $dm = $this->makeMember($this->org, 'dm');
        $tl = $this->makeMember($this->org, 'team-lead', ['reports_to_id' => $dm->id]);
        $project = $this->project(['manager_membership_id' => $tl->id]);

        $this->actingAsMember($dm)->get("/projects/{$project->id}")->assertOk();
    }

    public function test_a_department_scope_sees_the_departments_projects(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $hod = $this->makeMember($this->org, 'hod', ['department_id' => $dept->id]);
        $inDept = $this->project(['department_id' => $dept->id]);
        $this->project();

        $this->actingAsMember($hod)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.id', $inDept->id));
    }

    public function test_external_people_only_see_projects_they_were_added_to(): void
    {
        $client = $this->makeMember($this->org, 'external-stakeholder');
        $theirs = $this->project();
        $this->project();
        app(ProjectService::class)->addMember($theirs, $client, 'stakeholder');

        $this->actingAsMember($client)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 1)->where('projects.data.0.id', $theirs->id));

        // Even a company-wide scope on an external role changes nothing: it is a hard rule.
        $client->role->forceFill(['data_scope' => 'organization'])->save();
        $this->actingAsMember($client)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 1));
    }

    public function test_leaving_a_project_removes_the_access_it_gave(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $project = $this->project();
        $service = app(ProjectService::class);
        $row = $service->addMember($project, $dev, 'member');

        $this->actingAsMember($dev)->get("/projects/{$project->id}")->assertOk();

        $service->removeMember($project, $row);

        $this->actingAsMember($dev)->get("/projects/{$project->id}")->assertForbidden();
    }

    public function test_projects_of_other_companies_are_not_found(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $foreign = $this->project([], $other, $otherAdmin);

        foreach (['', '/edit', '/members', '/workflow', '/settings', '/labels'] as $suffix) {
            $this->actingAsMember($this->admin)->get("/projects/{$foreign->id}{$suffix}")->assertNotFound();
        }
        $this->actingAsMember($this->admin)->put("/projects/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->actingAsMember($this->admin)->post("/projects/{$foreign->id}/status", ['status' => 'archived'])->assertNotFound();
        $this->actingAsMember($this->admin)->delete("/projects/{$foreign->id}")->assertNotFound();
        $this->assertNotSame('Hacked', $foreign->fresh()->name);
        $this->actingAsMember($this->admin)->get('/projects')->assertInertia(fn ($page) => $page->has('projects.data', 0));
    }

    // ---- list filters ----------------------------------------------------------------------

    public function test_the_list_hides_archived_projects_by_default_and_filters(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $a = $this->project(['name' => 'Alpha Shop', 'key' => 'ALP', 'department_id' => $dept->id]);
        $b = $this->project(['name' => 'Beta Bank', 'key' => 'BTA']);
        $c = $this->project(['name' => 'Gamma Old', 'key' => 'GAM']);
        $b->forceFill(['status' => Project::ON_HOLD])->save();
        $c->forceFill(['status' => Project::ARCHIVED])->save();

        $get = fn (string $qs) => $this->actingAsMember($this->admin)->get('/projects'.$qs);

        $get('')->assertInertia(fn ($p) => $p->has('projects.data', 2));
        $get('?status=archived')->assertInertia(fn ($p) => $p->has('projects.data', 1)->where('projects.data.0.key', 'GAM'));
        $get('?status=all')->assertInertia(fn ($p) => $p->has('projects.data', 3));
        $get('?status=on_hold')->assertInertia(fn ($p) => $p->has('projects.data', 1)->where('projects.data.0.key', 'BTA'));
        $get("?department_id={$dept->id}")->assertInertia(fn ($p) => $p->has('projects.data', 1)->where('projects.data.0.key', 'ALP'));
        $get('?q=bank')->assertInertia(fn ($p) => $p->has('projects.data', 1)->where('projects.data.0.key', 'BTA'));
        $get('?q=ALP')->assertInertia(fn ($p) => $p->has('projects.data', 1));
        $get('?status=bogus')->assertNotFound();
    }

    public function test_my_projects_only(): void
    {
        $mine = $this->project();
        $this->project();
        $managed = $this->project();
        app(ProjectService::class)->addMember($mine, $this->admin, 'member');
        app(ProjectService::class)->changeManager($managed, $this->admin->id);

        $this->actingAsMember($this->admin)->get('/projects?mine=1')->assertInertia(fn ($p) => $p->has('projects.data', 2));
    }

    public function test_the_list_shows_manager_and_member_counts(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $dev = $this->makeMember($this->org, 'developer');
        $project = $this->project(['manager_membership_id' => $pm->id]);
        app(ProjectService::class)->addMember($project, $dev, 'member');

        $this->actingAsMember($this->admin)->get('/projects')->assertInertia(fn ($p) => $p->where('projects.data.0.members', 2)->where('projects.data.0.manager', $pm->user->name));
    }

    // ---- show & edit -----------------------------------------------------------------------

    public function test_the_overview_shows_the_project(): void
    {
        $project = $this->project(['description' => 'Hello']);

        $this->actingAsMember($this->admin)->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Projects/Show')
                ->where('project.key', $project->key)->where('project.description', 'Hello')->has('statuses', 9)->where('project.abilities.update', true));
    }

    public function test_a_project_can_be_edited(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $project = $this->project(['key' => 'OLD']);

        $this->actingAsMember($this->admin)->put("/projects/{$project->id}", [
            'name' => 'Renamed', 'key' => 'NEW', 'description' => 'Changed', 'department_id' => $dept->id, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ])->assertSessionHasNoErrors()->assertRedirect(route('projects.show', $project));

        $project->refresh();
        $this->assertSame(['Renamed', 'NEW', 'Changed', $dept->id], [$project->name, $project->key, $project->description, $project->department_id]);
    }

    public function test_the_key_cannot_change_once_tasks_exist(): void
    {
        $project = $this->project(['key' => 'OLD']);
        $project->forceFill(['task_counter' => 3])->save();

        $this->actingAsMember($this->admin)->put("/projects/{$project->id}", ['name' => 'Same', 'key' => 'NEW'])->assertSessionHasErrors('key');
        $this->actingAsMember($this->admin)->put("/projects/{$project->id}", ['name' => 'Same', 'key' => 'OLD'])->assertSessionHasNoErrors();

        $this->assertSame('OLD', $project->fresh()->key);
        $this->actingAsMember($this->admin)->get("/projects/{$project->id}/edit")->assertInertia(fn ($p) => $p->where('project.key_locked', true));
    }

    public function test_changing_the_key_to_one_in_use_is_refused(): void
    {
        $this->project(['key' => 'TAKEN']);
        $project = $this->project(['key' => 'MINE']);

        $this->actingAsMember($this->admin)->put("/projects/{$project->id}", ['name' => 'x', 'key' => 'TAKEN'])->assertSessionHasErrors('key');
    }

    public function test_the_project_manager_can_edit_without_the_company_wide_permission(): void
    {
        $dev = $this->makeMember($this->org, 'developer'); // no project.edit
        $project = $this->project(['manager_membership_id' => $dev->id]);

        $this->actingAsMember($dev)->put("/projects/{$project->id}", ['name' => 'Mine now'])->assertSessionHasNoErrors();
        $this->assertSame('Mine now', $project->fresh()->name);
    }

    public function test_an_ordinary_member_cannot_edit(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $project = $this->project();
        app(ProjectService::class)->addMember($project, $dev, 'member');

        $this->actingAsMember($dev)->get("/projects/{$project->id}/edit")->assertForbidden();
        $this->actingAsMember($dev)->put("/projects/{$project->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->assertNotSame('Hacked', $project->fresh()->name);
    }

    public function test_changing_the_manager_hands_the_role_over(): void
    {
        $old = $this->makeMember($this->org, 'project-manager');
        $new = $this->makeMember($this->org, 'project-manager');
        $project = $this->project(['manager_membership_id' => $old->id]);

        $this->actingAsMember($this->admin)->put("/projects/{$project->id}", ['name' => $project->name, 'manager_membership_id' => $new->id])->assertSessionHasNoErrors();

        $this->assertSame($new->id, $project->fresh()->manager_membership_id);
        $this->assertSame('manager', ProjectMember::where('project_id', $project->id)->where('membership_id', $new->id)->value('project_role'));
        $this->assertSame('member', ProjectMember::where('project_id', $project->id)->where('membership_id', $old->id)->value('project_role'));
        $this->assertNull(ProjectMember::where('project_id', $project->id)->where('membership_id', $old->id)->value('left_at'), 'the old PM stays on the project');
    }

    public function test_the_manager_can_be_cleared(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $project = $this->project(['manager_membership_id' => $pm->id]);

        $this->actingAsMember($this->admin)->put("/projects/{$project->id}", ['name' => $project->name, 'manager_membership_id' => null])->assertSessionHasNoErrors();

        $this->assertNull($project->fresh()->manager_membership_id);
    }

    public function test_changing_the_manager_needs_the_manage_members_permission(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead'); // project.edit? no. Use a PM: has edit + manage_members
        $custom = Role::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Editor', 'slug' => 'editor', 'level' => 55, 'data_scope' => 'organization']);
        $custom->permissions()->sync(Permission::whereIn('name', ['project.view', 'project.edit'])->pluck('id'));
        $editor = $this->makeMember($this->org, 'project-manager');
        $editor->forceFill(['role_id' => $custom->id])->save();
        $project = $this->project();

        $this->actingAsMember($editor->fresh())->put("/projects/{$project->id}", ['name' => 'Fine', 'manager_membership_id' => $tl->id])->assertForbidden();
        $this->assertNull($project->fresh()->manager_membership_id);
        $this->assertNotSame('Fine', $project->fresh()->name, 'a refused request changes nothing');
    }

    // ---- status: on hold / completed / archived / restore -----------------------------------

    public function test_a_project_moves_through_its_lifecycle(): void
    {
        $project = $this->project();
        $go = fn (string $to) => $this->actingAsMember($this->admin)->post("/projects/{$project->id}/status", ['status' => $to]);

        $go('on_hold')->assertSessionHasNoErrors();
        $this->assertSame('on_hold', $project->fresh()->status);
        $go('active')->assertSessionHasNoErrors();
        $go('completed')->assertSessionHasNoErrors();
        $this->assertSame('completed', $project->fresh()->status);
        $go('archived')->assertSessionHasNoErrors();
        $this->assertSame('archived', $project->fresh()->status);
        $go('active')->assertSessionHasNoErrors(); // restore
        $this->assertSame('active', $project->fresh()->status);
    }

    public function test_impossible_status_moves_are_refused(): void
    {
        $project = $this->project();
        $project->forceFill(['status' => Project::ARCHIVED])->save();

        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/status", ['status' => 'completed'])->assertSessionHasErrors('status');
        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/status", ['status' => 'on_hold'])->assertSessionHasErrors('status');
        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/status", ['status' => 'archived'])->assertSessionHasErrors('status');
        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/status", ['status' => 'nonsense'])->assertSessionHasErrors('status');
        $this->assertSame('archived', $project->fresh()->status);
    }

    public function test_the_move_table_is_complete(): void
    {
        $this->assertSame(['on_hold', 'completed', 'archived'], Project::MOVES['active']);
        $this->assertTrue((new Project(['status' => 'completed']))->canMoveTo('active'));
        $this->assertFalse((new Project(['status' => 'on_hold']))->canMoveTo('completed'));
    }

    public function test_archived_projects_are_read_only_but_can_still_be_viewed_and_restored(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $project = $this->project();
        app(ProjectService::class)->addMember($project, $dev, 'member');
        $project->forceFill(['status' => Project::ARCHIVED])->save();

        $this->actingAsMember($this->admin)->get("/projects/{$project->id}")->assertOk()->assertInertia(fn ($p) => $p->where('project.is_archived', true)->where('project.abilities.update', false));

        $this->actingAsMember($this->admin)->put("/projects/{$project->id}", ['name' => 'Nope'])->assertForbidden();
        $this->actingAsMember($this->admin)->put("/projects/{$project->id}/settings", ['uses_sprints' => false, 'triage_mode' => 'off', 'client_can_see_all' => false, 'allow_self_close' => false, 'progress_mode' => 'count'])->assertForbidden();
        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/members", ['membership_id' => $dev->id, 'project_role' => 'viewer'])->assertForbidden();
        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/labels", ['name' => 'x', 'color' => '#ffffff'])->assertForbidden();
        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/workflow/statuses", ['name' => 'x', 'category' => 'todo'])->assertForbidden();

        $this->actingAsMember($this->admin)->post("/projects/{$project->id}/status", ['status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('active', $project->fresh()->status);
    }

    public function test_who_may_change_a_projects_status(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $pm = $this->makeMember($this->org, 'project-manager');
        $project = $this->project(['manager_membership_id' => $dev->id]); // the dev is its manager
        app(ProjectService::class)->addMember($project, $pm, 'viewer'); // so the PM can see it

        $this->actingAsMember($dev)->post("/projects/{$project->id}/status", ['status' => 'on_hold'])->assertSessionHasNoErrors(); // project manager
        $this->actingAsMember($pm)->post("/projects/{$project->id}/status", ['status' => 'active'])->assertSessionHasNoErrors(); // has project.archive, in scope

        $other = $this->project();
        $outsider = $this->makeMember($this->org, 'developer');
        $this->actingAsMember($outsider)->post("/projects/{$other->id}/status", ['status' => 'archived'])->assertForbidden();
    }

    // ---- delete ----------------------------------------------------------------------------

    public function test_only_people_with_project_delete_can_delete(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $pm = $this->makeMember($this->org, 'project-manager'); // can edit/archive but not delete
        $hod = $this->makeMember($this->org, 'hod', ['department_id' => $dept->id]);
        $project = $this->project(['manager_membership_id' => $pm->id, 'department_id' => $dept->id]);

        $this->actingAsMember($pm)->delete("/projects/{$project->id}")->assertForbidden();
        $this->assertNotSoftDeleted($project);

        $this->actingAsMember($hod)->delete("/projects/{$project->id}")->assertRedirect(route('projects.index'));
        $this->assertSoftDeleted($project);
        $this->actingAsMember($this->admin)->get("/projects/{$project->id}")->assertNotFound();
    }
}
