<?php

namespace Tests\Feature\Projects;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ProjectMembersTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    private Project $project;

    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
        $this->service = app(ProjectService::class);
        $this->project = $this->service->create($this->org, $this->admin, ['name' => 'Portal', 'key' => 'ECP']);
    }

    private function add(Membership $m, string $role = 'member', ?Membership $by = null)
    {
        return $this->actingAsMember($by ?? $this->admin)->post("/projects/{$this->project->id}/members", ['membership_id' => $m->id, 'project_role' => $role]);
    }

    private function row(Membership $m): ?ProjectMember
    {
        return ProjectMember::where('project_id', $this->project->id)->where('membership_id', $m->id)->first();
    }

    public function test_the_members_page_lists_people_and_candidates(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $this->service->addMember($this->project, $dev, 'member');
        $free = $this->makeMember($this->org, 'team-lead');

        $this->actingAsMember($this->admin)->get("/projects/{$this->project->id}/members")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Projects/Members')->has('members', 1)->has('former', 0)
                ->where('candidates', fn ($c) => collect($c)->pluck('id')->contains($free->id) && ! collect($c)->pluck('id')->contains($dev->id)));
    }

    public function test_people_can_be_added_with_a_project_role(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $viewer = $this->makeMember($this->org, 'team-lead');

        $this->add($dev, 'member')->assertSessionHasNoErrors();
        $this->add($viewer, 'viewer')->assertSessionHasNoErrors();

        $this->assertSame('member', $this->row($dev)->project_role);
        $this->assertSame('viewer', $this->row($viewer)->project_role);
        $this->assertSame($this->admin->id, $this->row($dev)->added_by);
        $this->assertNotNull($this->row($dev)->joined_at);
        $this->assertNull($this->row($dev)->left_at);
    }

    public function test_the_manager_role_cannot_be_handed_out_here(): void
    {
        $dev = $this->makeMember($this->org, 'developer');

        $this->add($dev, 'manager')->assertSessionHasErrors('project_role');
        $this->add($dev, 'wizard')->assertSessionHasErrors('project_role');
        $this->assertNull($this->row($dev));
    }

    public function test_the_same_person_cannot_be_added_twice(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $this->add($dev)->assertSessionHasNoErrors();

        $this->add($dev, 'viewer')->assertSessionHasErrors('membership_id');
        $this->assertSame(1, ProjectMember::where('membership_id', $dev->id)->count());
    }

    public function test_external_people_can_only_be_stakeholders_or_viewers(): void
    {
        $client = $this->makeMember($this->org, 'external-stakeholder');

        $this->add($client, 'member')->assertSessionHasErrors('project_role');
        $this->add($client, 'stakeholder')->assertSessionHasNoErrors();
        $this->assertSame('stakeholder', $this->row($client)->project_role);

        $this->actingAsMember($this->admin)->put("/projects/{$this->project->id}/members/{$this->row($client)->id}", ['project_role' => 'member'])
            ->assertSessionHasErrors('project_role');
        $this->actingAsMember($this->admin)->put("/projects/{$this->project->id}/members/{$this->row($client)->id}", ['project_role' => 'viewer'])->assertSessionHasNoErrors();
    }

    public function test_only_active_people_of_this_company_can_be_added(): void
    {
        [$other] = $this->makeCompany();
        $foreigner = $this->makeMember($other, 'developer');
        $gone = $this->makeMember($this->org, 'developer');
        $gone->forceFill(['status' => Membership::EXITED])->save();
        $gone->delete();

        $this->add($foreigner)->assertNotFound();
        $this->add($gone)->assertNotFound();
        $this->assertSame(0, ProjectMember::whereIn('membership_id', [$foreigner->id, $gone->id])->count());
    }

    public function test_removing_someone_keeps_the_row_with_a_left_date(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $this->add($dev);
        $row = $this->row($dev);

        $this->actingAsMember($this->admin)->delete("/projects/{$this->project->id}/members/{$row->id}")->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertNotNull($row->left_at);
        $this->actingAsMember($this->admin)->get("/projects/{$this->project->id}/members")
            ->assertInertia(fn ($page) => $page->has('members', 0)->has('former', 1));
    }

    public function test_adding_someone_again_clears_their_left_date(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $this->add($dev, 'member');
        $row = $this->row($dev);
        $this->service->removeMember($this->project, $row);
        $this->assertNotNull($row->fresh()->left_at);

        $this->add($dev, 'viewer')->assertSessionHasNoErrors();

        $again = $this->row($dev);
        $this->assertSame($row->id, $again->id, 'the same row is reused');
        $this->assertNull($again->left_at);
        $this->assertSame('viewer', $again->project_role);
        $this->assertSame(1, ProjectMember::where('membership_id', $dev->id)->count());
    }

    public function test_the_project_manager_cannot_be_removed_or_demoted_here(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $this->service->changeManager($this->project, $pm->id);
        $row = $this->row($pm);

        $this->actingAsMember($this->admin)->delete("/projects/{$this->project->id}/members/{$row->id}")->assertSessionHasErrors('member');
        $this->actingAsMember($this->admin)->put("/projects/{$this->project->id}/members/{$row->id}", ['project_role' => 'viewer'])->assertSessionHasErrors('project_role');
        $this->assertNull($row->fresh()->left_at);
        $this->assertSame('manager', $row->fresh()->project_role);
    }

    public function test_a_role_can_be_changed(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $this->add($dev);

        $this->actingAsMember($this->admin)->put("/projects/{$this->project->id}/members/{$this->row($dev)->id}", ['project_role' => 'stakeholder'])->assertSessionHasNoErrors();

        $this->assertSame('stakeholder', $this->row($dev)->project_role);
    }

    public function test_changing_the_role_of_someone_who_left_is_refused(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $this->add($dev);
        $row = $this->row($dev);
        $this->service->removeMember($this->project, $row);

        $this->actingAsMember($this->admin)->put("/projects/{$this->project->id}/members/{$row->id}", ['project_role' => 'viewer'])->assertSessionHasErrors('member');
        $this->actingAsMember($this->admin)->delete("/projects/{$this->project->id}/members/{$row->id}")->assertSessionHasErrors('member');
    }

    public function test_the_project_manager_manages_members_without_the_company_wide_permission(): void
    {
        $dev = $this->makeMember($this->org, 'developer'); // no project.manage_members
        $other = $this->makeMember($this->org, 'developer');
        $this->service->changeManager($this->project, $dev->id);

        $this->add($other, 'member', $dev)->assertSessionHasNoErrors();
        $this->assertNotNull($this->row($other));
    }

    public function test_ordinary_members_can_see_but_not_change_the_team(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $other = $this->makeMember($this->org, 'developer');
        $this->service->addMember($this->project, $dev, 'member');

        $this->actingAsMember($dev)->get("/projects/{$this->project->id}/members")->assertOk()
            ->assertInertia(fn ($page) => $page->where('project.abilities.manage_members', false)->where('candidates', []));
        $this->add($other, 'member', $dev)->assertForbidden();
        $this->assertNull($this->row($other));
    }

    public function test_team_leads_can_manage_members_of_projects_they_can_see(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead'); // has project.manage_members
        $dev = $this->makeMember($this->org, 'developer');
        $this->service->addMember($this->project, $tl, 'member');

        $this->add($dev, 'member', $tl)->assertSessionHasNoErrors();
        $this->assertNotNull($this->row($dev));
    }

    public function test_members_of_another_project_cannot_be_touched_through_this_one(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $elsewhere = $this->service->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH']);
        $foreignRow = $this->service->addMember($elsewhere, $dev, 'member');

        $this->actingAsMember($this->admin)->put("/projects/{$this->project->id}/members/{$foreignRow->id}", ['project_role' => 'viewer'])->assertNotFound();
        $this->actingAsMember($this->admin)->delete("/projects/{$this->project->id}/members/{$foreignRow->id}")->assertNotFound();
        $this->assertNull($foreignRow->fresh()->left_at);
        $this->assertSame('member', $foreignRow->fresh()->project_role);
    }

    public function test_adding_to_another_companys_project_is_not_possible(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $foreign = $this->service->create($other, $otherAdmin, ['name' => 'Theirs', 'key' => 'THR']);
        $dev = $this->makeMember($this->org, 'developer');

        $this->actingAsMember($this->admin)->post("/projects/{$foreign->id}/members", ['membership_id' => $dev->id, 'project_role' => 'member'])->assertNotFound();
    }
}
