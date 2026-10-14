<?php

namespace Tests\Feature\Projects;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Role;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/** Projects meet people leaving and coming back (Phase 3 hooks). */
class ProjectExitTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    private ProjectService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
        $this->service = app(ProjectService::class);
    }

    private function project(string $key, array $data = []): Project
    {
        return $this->service->create($this->org, $this->admin, $data + ['name' => "Project {$key}", 'key' => $key]);
    }

    private function offboard(Membership $m, array $data = [])
    {
        return $this->actingAsMember($this->admin)->post("/people/{$m->id}/offboard", $data + ['exit_type' => 'resigned', 'left_at' => today()->toDateString()]);
    }

    private function row(Project $p, Membership $m): ?ProjectMember
    {
        return ProjectMember::where('project_id', $p->id)->where('membership_id', $m->id)->first();
    }

    // ---- leaving ---------------------------------------------------------------------------

    public function test_leaving_ends_every_project_membership_but_keeps_the_history(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $a = $this->project('AAA');
        $b = $this->project('BBB');
        $this->service->addMember($a, $dev, 'member');
        $this->service->addMember($b, $dev, 'viewer');

        $this->offboard($dev)->assertSessionHasNoErrors();

        foreach ([$a, $b] as $project) {
            $row = $this->row($project, $dev);
            $this->assertNotNull($row, 'the row stays as history');
            $this->assertNotNull($row->left_at);
        }
        $this->assertSame(2, ProjectMember::where('membership_id', $dev->id)->count());
    }

    public function test_someone_who_already_left_a_project_keeps_their_original_left_date(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $project = $this->project('AAA');
        $row = $this->service->addMember($project, $dev, 'member');
        $this->service->removeMember($project, $row);
        $left = $row->fresh()->left_at;

        $this->travelTo(now()->addDay());
        $this->offboard($dev);

        $this->assertEquals($left, $row->fresh()->left_at);
    }

    public function test_the_former_employee_shows_as_former_on_the_members_page(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $project = $this->project('AAA');
        $this->service->addMember($project, $dev, 'member');
        $this->offboard($dev);

        $this->actingAsMember($this->admin)->get("/projects/{$project->id}/members")
            ->assertInertia(fn ($page) => $page->has('members', 0)->has('former', 1)->where('former.0.is_former_employee', true));
    }

    public function test_a_project_manager_cannot_leave_without_a_successor(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $successor = $this->makeMember($this->org, 'project-manager');
        $a = $this->project('AAA', ['manager_membership_id' => $pm->id]);
        $b = $this->project('BBB', ['manager_membership_id' => $pm->id]);

        $this->offboard($pm)->assertSessionHasErrors('new_project_manager_id');
        $this->assertSame(Membership::ACTIVE, $pm->fresh()->status);
        $this->assertSame($pm->id, $a->fresh()->manager_membership_id);

        $this->offboard($pm, ['new_project_manager_id' => $successor->id])->assertSessionHasNoErrors();

        foreach ([$a, $b] as $project) {
            $this->assertSame($successor->id, $project->fresh()->manager_membership_id);
            $this->assertSame('manager', $this->row($project, $successor)->project_role);
        }
        $this->assertNotNull($this->row($a, $pm)->left_at);
    }

    public function test_the_handover_screen_lists_the_projects_they_manage(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $this->project('AAA', ['manager_membership_id' => $pm->id]);
        $archived = $this->project('OLD', ['manager_membership_id' => $pm->id]);
        $archived->forceFill(['status' => Project::ARCHIVED])->save();

        $this->actingAsMember($this->admin)->get("/people/{$pm->id}/offboard")->assertOk()
            ->assertInertia(fn ($page) => $page->has('projects', 1)->where('projects.0.key', 'AAA')
                ->where('projectManagers', fn ($m) => collect($m)->pluck('id')->doesntContain($pm->id) && collect($m)->isNotEmpty()));
    }

    public function test_the_new_project_manager_must_be_a_valid_person(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $client = $this->makeMember($this->org, 'external-stakeholder');
        [$other] = $this->makeCompany();
        $foreigner = $this->makeMember($other, 'project-manager');
        $this->project('AAA', ['manager_membership_id' => $pm->id]);

        $this->offboard($pm, ['new_project_manager_id' => $pm->id])->assertSessionHasErrors('new_project_manager_id');
        $this->offboard($pm, ['new_project_manager_id' => $client->id])->assertSessionHasErrors('new_project_manager_id');
        $this->offboard($pm, ['new_project_manager_id' => $foreigner->id])->assertSessionHasErrors('new_project_manager_id');
        $this->assertSame(Membership::ACTIVE, $pm->fresh()->status);
    }

    public function test_an_archived_project_does_not_block_the_exit_and_just_loses_its_manager(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $project = $this->project('OLD', ['manager_membership_id' => $pm->id]);
        $project->forceFill(['status' => Project::ARCHIVED])->save();

        $this->offboard($pm)->assertSessionHasNoErrors();

        $this->assertNull($project->fresh()->manager_membership_id);
        $this->assertNotNull($this->row($project, $pm)->left_at);
    }

    public function test_a_scheduled_exit_hands_projects_over_at_scheduling_time(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $successor = $this->makeMember($this->org, 'project-manager');
        $project = $this->project('AAA', ['manager_membership_id' => $pm->id]);

        $this->offboard($pm, ['left_at' => today()->addDays(5)->toDateString(), 'new_project_manager_id' => $successor->id])->assertSessionHasNoErrors();

        $this->assertSame($successor->id, $project->fresh()->manager_membership_id);
        $this->assertNull($this->row($project, $pm)->left_at, 'still on the project until their last day');

        $this->travelTo(today()->addDays(6));
        Artisan::call('members:process-exits');

        $this->assertNotNull($this->row($project, $pm)->fresh()->left_at);
    }

    public function test_a_manager_who_is_still_on_a_project_when_the_scheduler_runs_leaves_it_without_one(): void
    {
        $pm = $this->makeMember($this->org, 'project-manager');
        $project = $this->project('AAA');
        $this->offboard($pm, ['left_at' => today()->addDay()->toDateString()]); // nothing to hand over yet
        $this->service->changeManager($project, $pm->id); // made manager afterwards

        $this->travelTo(today()->addDays(2));
        Artisan::call('members:process-exits');

        $this->assertNull($project->fresh()->manager_membership_id);
        $this->assertNotNull($this->row($project, $pm)->left_at);
    }

    // ---- coming back -----------------------------------------------------------------------

    private function formerWithProjects(): array
    {
        $dev = $this->makeMember($this->org, 'developer');
        $a = $this->project('AAA');
        $b = $this->project('BBB');
        $c = $this->project('CCC');
        $this->service->addMember($a, $dev, 'member');
        $this->service->addMember($b, $dev, 'viewer');
        $this->service->addMember($c, $dev, 'member');
        $this->offboard($dev);

        return [Membership::withoutGlobalScopes()->onlyTrashed()->findOrFail($dev->id), $a, $b, $c];
    }

    private function rehire(Membership $former, array $extra = [])
    {
        return $this->actingAsMember($this->admin)->post("/people/{$former->id}/rehire", $extra + [
            'role_id' => $former->role_id,
        ]);
    }

    public function test_the_rehire_page_offers_the_previous_projects(): void
    {
        [$former, $a, $b, $c] = $this->formerWithProjects();
        $c->forceFill(['status' => Project::ARCHIVED])->save();

        $this->actingAsMember($this->admin)->get("/people/{$former->id}/rehire")
            ->assertInertia(fn ($page) => $page->has('previousProjects', 2)->where('previousProjects.0.key', 'AAA')->where('previousProjects.1.key', 'BBB'));
    }

    public function test_project_access_is_not_restored_unless_chosen(): void
    {
        Notification::fake();
        [$former, $a, $b] = $this->formerWithProjects();

        $this->rehire($former)->assertSessionHasNoErrors();

        foreach ([$a, $b] as $project) {
            $this->assertNotNull($this->row($project, $former)->left_at, 'still out of the project');
        }
        $this->assertNull(Project::visibleTo(Membership::withoutGlobalScopes()->with('role')->find($former->id))->where('id', $a->id)->first());
    }

    public function test_chosen_projects_are_restored_with_their_old_role(): void
    {
        Notification::fake();
        [$former, $a, $b, $c] = $this->formerWithProjects();

        $this->rehire($former, ['project_ids' => [$a->id, $b->id]])->assertSessionHasNoErrors();

        $this->assertNull($this->row($a, $former)->fresh()->left_at);
        $this->assertSame('member', $this->row($a, $former)->project_role);
        $this->assertNull($this->row($b, $former)->fresh()->left_at);
        $this->assertSame('viewer', $this->row($b, $former)->project_role);
        $this->assertNotNull($this->row($c, $former)->left_at, 'not ticked, so not restored');
        $this->assertSame(1, ProjectMember::where('project_id', $a->id)->where('membership_id', $former->id)->count());
    }

    public function test_a_former_project_manager_comes_back_as_an_ordinary_member(): void
    {
        Notification::fake();
        $pm = $this->makeMember($this->org, 'project-manager');
        $successor = $this->makeMember($this->org, 'project-manager');
        $project = $this->project('AAA', ['manager_membership_id' => $pm->id]);
        $this->offboard($pm, ['new_project_manager_id' => $successor->id]);
        $former = Membership::withoutGlobalScopes()->onlyTrashed()->findOrFail($pm->id);

        $this->rehire($former, ['project_ids' => [$project->id]]);

        $this->assertSame('member', $this->row($project, $former)->project_role, 'the manager role went to the successor');
        $this->assertSame($successor->id, $project->fresh()->manager_membership_id);
    }

    public function test_only_projects_the_person_really_was_on_can_be_restored(): void
    {
        Notification::fake();
        [$former] = $this->formerWithProjects();
        $stranger = $this->project('ZZZ');
        [$other, $otherAdmin] = $this->makeCompany();
        $foreign = $this->service->create($other, $otherAdmin, ['name' => 'Theirs', 'key' => 'THR']);

        $this->rehire($former, ['project_ids' => [$stranger->id, $foreign->id]])->assertSessionHasNoErrors();

        $this->assertNull($this->row($stranger, $former));
        $this->assertNull(ProjectMember::where('project_id', $foreign->id)->where('membership_id', $former->id)->first());
    }

    public function test_an_archived_project_cannot_take_someone_back(): void
    {
        Notification::fake();
        [$former, $a] = $this->formerWithProjects();
        $a->forceFill(['status' => Project::ARCHIVED])->save();

        $this->rehire($former, ['project_ids' => [$a->id]])->assertSessionHasNoErrors();

        $this->assertNotNull($this->row($a, $former)->left_at);
    }

    public function test_rehiring_into_an_external_role_restores_them_as_a_stakeholder(): void
    {
        Notification::fake();
        [$former, $a] = $this->formerWithProjects();
        $external = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'external-stakeholder')->first();

        $this->rehire($former, ['role_id' => $external->id, 'project_ids' => [$a->id]])->assertSessionHasNoErrors();

        $this->assertSame('stakeholder', $this->row($a, $former)->project_role);
    }
}
