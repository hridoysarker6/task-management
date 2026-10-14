<?php

namespace Tests\Feature\Projects;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Policies\ProjectPolicy;
use App\Services\PermissionService;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * The three checks of WORKFLOW section 4 applied to projects: the permission, the data scope, and project membership.
 */
class ProjectPolicyTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    private Department $dept;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $this->project = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Portal', 'key' => 'ECP', 'department_id' => $this->dept->id]);
    }

    /** What the member may do with $project, as [ability => bool]. */
    private function abilities(Membership $m, ?Project $project = null): array
    {
        $this->enterCompany($m->fresh());
        $this->actingAs($m->user);
        $project ??= $this->project;

        return collect(['view', 'update', 'changeStatus', 'delete', 'manageMembers', 'manageWorkflow', 'manageSettings', 'manageLabels'])
            ->mapWithKeys(fn ($a) => [$a => Gate::allows($a, $project)])->all();
    }

    private function all(bool $value): array
    {
        return array_fill_keys(['view', 'update', 'changeStatus', 'delete', 'manageMembers', 'manageWorkflow', 'manageSettings', 'manageLabels'], $value);
    }

    public function test_the_company_admin_can_do_everything(): void
    {
        $this->assertEquals($this->all(true), $this->abilities($this->admin));
    }

    public function test_someone_who_cannot_see_the_project_can_do_nothing_with_it(): void
    {
        $dev = $this->makeMember($this->org, 'developer'); // own scope, not a member

        $this->assertEquals($this->all(false), $this->abilities($dev));
    }

    public function test_a_plain_member_can_only_view(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        app(ProjectService::class)->addMember($this->project, $dev, 'member');

        $this->assertEquals(['view' => true] + $this->all(false), $this->abilities($dev));
    }

    public function test_a_viewer_stakeholder_and_external_can_only_view(): void
    {
        $viewer = $this->makeMember($this->org, 'internal-stakeholder');
        $client = $this->makeMember($this->org, 'external-stakeholder');
        $service = app(ProjectService::class);
        $service->addMember($this->project, $viewer, 'viewer');
        $service->addMember($this->project, $client, 'stakeholder');

        $this->assertEquals(['view' => true] + $this->all(false), $this->abilities($viewer));
        $this->assertEquals(['view' => true] + $this->all(false), $this->abilities($client));
    }

    public function test_the_project_manager_runs_the_project_but_cannot_delete_it(): void
    {
        $dev = $this->makeMember($this->org, 'developer'); // no project permissions at all
        app(ProjectService::class)->changeManager($this->project, $dev->id);

        $this->assertEquals(['delete' => false] + $this->all(true), $this->abilities($dev));
    }

    public function test_a_team_lead_manages_members_workflow_settings_and_labels_but_not_details_or_status(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead');
        app(ProjectService::class)->addMember($this->project, $tl, 'member');

        $this->assertSame([
            'view' => true, 'update' => false, 'changeStatus' => false, 'delete' => false,
            'manageMembers' => true, 'manageWorkflow' => true, 'manageSettings' => true, 'manageLabels' => true,
        ], $this->abilities($tl));
    }

    public function test_a_project_manager_role_holder_can_create_edit_and_archive_in_scope(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        app(ProjectService::class)->addMember($this->project, $pm, 'member');

        $this->assertEquals(['delete' => false] + $this->all(true), $this->abilities($pm));
        $this->assertTrue(Gate::forUser($pm->user)->allows('create', Project::class));
    }

    public function test_the_hod_can_delete_inside_their_department_only(): void
    {
        $hod = $this->makeMember($this->org, 'hod', ['department_id' => $this->dept->id]);
        $otherDept = Department::factory()->create(['organization_id' => $this->org->id]);
        $elsewhere = app(ProjectService::class)->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH', 'department_id' => $otherDept->id]);

        $this->assertEquals($this->all(true), $this->abilities($hod));
        $this->assertEquals($this->all(false), $this->abilities($hod, $elsewhere));
    }

    public function test_the_permission_is_needed_even_when_the_project_is_visible(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        app(ProjectService::class)->addMember($this->project, $dev, 'member');
        app(PermissionService::class)->override($dev, 'project.view', 'revoke');

        $this->assertEquals($this->all(false), $this->abilities($dev));
    }

    public function test_a_granted_override_gives_the_ability_inside_the_scope(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        app(ProjectService::class)->addMember($this->project, $dev, 'member');
        app(PermissionService::class)->override($dev, 'project.manage_settings', 'grant');

        $abilities = $this->abilities($dev);
        $this->assertTrue($abilities['manageSettings']);
        $this->assertFalse($abilities['manageWorkflow']);
        $this->assertFalse($abilities['update']);
    }

    public function test_the_permission_alone_does_not_reach_outside_the_data_scope(): void
    {
        $dm = $this->makeMember($this->org, 'dm'); // team scope: cannot see a project nobody on their team is in
        app(PermissionService::class)->override($dm, 'project.edit', 'grant');

        $this->assertEquals($this->all(false), $this->abilities($dm));
    }

    public function test_nothing_can_be_changed_on_an_archived_project_except_its_status(): void
    {
        $this->project->forceFill(['status' => Project::ARCHIVED])->save();

        $this->assertSame([
            'view' => true, 'update' => false, 'changeStatus' => true, 'delete' => true,
            'manageMembers' => false, 'manageWorkflow' => false, 'manageSettings' => false, 'manageLabels' => false,
        ], $this->abilities($this->admin));
    }

    public function test_projects_of_other_companies_are_never_allowed(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $foreign = app(ProjectService::class)->create($other, $otherAdmin, ['name' => 'Theirs', 'key' => 'THR']);

        $this->assertEquals($this->all(false), $this->abilities($this->admin, $foreign));
    }

    public function test_creating_needs_the_create_permission(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead');
        $this->enterCompany($tl);
        $this->actingAs($tl->user);

        $this->assertFalse(Gate::allows('create', Project::class));
        $this->assertInstanceOf(ProjectPolicy::class, Gate::getPolicyFor(Project::class));
    }

    public function test_without_a_company_context_nothing_is_allowed(): void
    {
        $this->actingAs($this->admin->user);

        $this->assertFalse(Gate::allows('view', $this->project));
        $this->assertFalse(Gate::allows('create', Project::class));
    }
}
