<?php

namespace Tests\Feature\Tasks;

use App\Models\ProjectMember;
use App\Models\Task;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Who can see which task, for every role (WORKFLOW.md section 4 and 4.5). */
class TaskVisibilityTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    private Task $byPm;        // ECP, reported by the PM

    private Task $byDev1;      // ECP, reported by Dev1, assigned to Dev1

    private Task $forDev2;     // ECP, reported by TL-A, assigned to Dev2 (who is not on the project)

    private Task $byDev3;      // OTH, reported by Dev3

    private Task $byTlB;       // OTH, reported by TL-B, assigned to Dev3

    private Task $byClient;    // ECP, reported by the client

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();

        $this->byPm = $this->task($this->ecp, $this->pm);
        $this->byDev1 = $this->task($this->ecp, $this->dev1, ['assignee_id' => $this->dev1->id]);
        $this->forDev2 = $this->task($this->ecp, $this->tlA, ['assignee_id' => $this->dev2->id]);
        $this->byDev3 = $this->task($this->oth, $this->dev3);
        $this->byTlB = $this->task($this->oth, $this->tlB, ['assignee_id' => $this->dev3->id]);
        $this->byClient = $this->task($this->ecp, $this->client);
    }

    private function ids(Task ...$tasks): array
    {
        return collect($tasks)->pluck('id')->sort()->values()->all();
    }

    public function test_the_company_admin_sees_every_task(): void
    {
        $this->assertSame($this->ids($this->byPm, $this->byDev1, $this->forDev2, $this->byDev3, $this->byTlB, $this->byClient), $this->visibleIds($this->admin));
    }

    public function test_a_developer_sees_their_projects_tasks_but_not_the_other_teams(): void
    {
        // Dev1 is a member of ECP only.
        $this->assertSame($this->ids($this->byPm, $this->byDev1, $this->forDev2, $this->byClient), $this->visibleIds($this->dev1));
        $this->assertNotContains($this->byDev3->id, $this->visibleIds($this->dev1));
        $this->assertNotContains($this->byTlB->id, $this->visibleIds($this->dev1));
    }

    public function test_another_teams_developer_cannot_see_this_teams_tasks(): void
    {
        // Dev3 is a member of OTH only.
        $this->assertSame($this->ids($this->byDev3, $this->byTlB), $this->visibleIds($this->dev3));
    }

    public function test_a_developer_outside_the_project_sees_only_what_is_theirs(): void
    {
        // Dev2 is on no project: they see the one task assigned to them and nothing else.
        $this->assertSame($this->ids($this->forDev2), $this->visibleIds($this->dev2));
    }

    public function test_reporting_assigned_or_watching_makes_a_task_visible_without_project_membership(): void
    {
        $this->assertSame($this->ids($this->forDev2), $this->visibleIds($this->dev2));

        // Reported by them.
        $mine = $this->task($this->ecp, $this->dev2);
        // Watched by them.
        $this->byPm->watchers()->attach($this->dev2->id);

        $this->assertSame($this->ids($this->forDev2, $mine, $this->byPm), $this->visibleIds($this->dev2));
    }

    public function test_a_team_lead_sees_their_projects_and_their_teams_work(): void
    {
        // TL-A: member of ECP (all of it) and over Dev1/Dev2 (nothing extra here).
        $this->assertSame($this->ids($this->byPm, $this->byDev1, $this->forDev2, $this->byClient), $this->visibleIds($this->tlA));
    }

    public function test_the_other_team_lead_sees_only_their_own_team_and_project(): void
    {
        // TL-B: member of OTH, over Dev3. Not on ECP, none of ECP's people are below them.
        $this->assertSame($this->ids($this->byDev3, $this->byTlB), $this->visibleIds($this->tlB));
    }

    public function test_a_team_lead_sees_work_of_people_below_them_even_outside_their_projects(): void
    {
        // Dev3 (under TL-B) gets a task in ECP: TL-B now sees it because its assignee is in their team.
        $crossover = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev3->id]);

        $this->assertContains($crossover->id, $this->visibleIds($this->tlB));
        $this->assertContains($crossover->id, $this->visibleIds($this->tlA), 'TL-A is on ECP, so sees everything in it');
    }

    public function test_the_dm_sees_both_team_leads_teams(): void
    {
        // DM (team scope) is on no project: sees tasks reported by or assigned to anyone below them.
        $ids = $this->visibleIds($this->dm);

        $this->assertSame($this->ids($this->byDev1, $this->forDev2, $this->byDev3, $this->byTlB), $ids);
        $this->assertNotContains($this->byPm->id, $ids, 'the PM is not below the DM');
        $this->assertNotContains($this->byClient->id, $ids);
    }

    public function test_the_hod_sees_their_departments_work(): void
    {
        // Department D1: HOD, DM, TL-A, Dev1. Visible: tasks they reported or are assigned to.
        $ids = $this->visibleIds($this->hod);

        $this->assertSame($this->ids($this->byDev1, $this->forDev2), $ids); // Dev1's own task; TL-A's task
        $this->assertNotContains($this->byDev3->id, $ids);
        $this->assertNotContains($this->byPm->id, $ids);
    }

    public function test_the_project_manager_sees_all_of_their_project_only(): void
    {
        $this->assertSame($this->ids($this->byPm, $this->byDev1, $this->forDev2, $this->byClient), $this->visibleIds($this->pm));
    }

    public function test_an_internal_stakeholder_outside_the_project_sees_nothing_of_it(): void
    {
        $this->assertSame([], $this->visibleIds($this->stakeholder));

        $mine = $this->task($this->ecp, $this->stakeholder);
        $this->assertSame($this->ids($mine), $this->visibleIds($this->stakeholder));
    }

    public function test_an_external_client_sees_only_their_own_requests(): void
    {
        $this->assertSame($this->ids($this->byClient), $this->visibleIds($this->client));
    }

    public function test_an_external_client_sees_tasks_they_watch_or_are_assigned(): void
    {
        $this->byPm->watchers()->attach($this->client->id);

        $this->assertSame($this->ids($this->byPm, $this->byClient), $this->visibleIds($this->client));
    }

    public function test_a_client_sees_everything_when_the_project_allows_it(): void
    {
        $this->ecp->forceFill(['client_can_see_all' => true])->save();

        $this->assertSame($this->ids($this->byPm, $this->byDev1, $this->forDev2, $this->byClient), $this->visibleIds($this->client));
        $this->assertNotContains($this->byDev3->id, $this->visibleIds($this->client), 'that setting never reaches other projects');
    }

    public function test_a_client_without_a_project_membership_sees_nothing(): void
    {
        // Their own request in a project they have since left.
        $row = ProjectMember::where('project_id', $this->ecp->id)->where('membership_id', $this->client->id)->first();
        $row->update(['left_at' => now()]);

        $this->assertSame([], $this->visibleIds($this->client));

        // And a client of a project with "see all" who is not a member cannot use it either.
        $this->ecp->forceFill(['client_can_see_all' => true])->save();
        $this->assertSame([], $this->visibleIds($this->client));
    }

    public function test_leaving_a_project_removes_the_access_it_gave(): void
    {
        $this->assertContains($this->byPm->id, $this->visibleIds($this->dev1));

        ProjectMember::where('project_id', $this->ecp->id)->where('membership_id', $this->dev1->id)->update(['left_at' => now()]);

        // Still theirs: the one they reported and were assigned.
        $this->assertSame($this->ids($this->byDev1), $this->visibleIds($this->dev1));
    }

    public function test_deleted_tasks_are_never_visible(): void
    {
        $this->byPm->delete();

        $this->assertNotContains($this->byPm->id, $this->visibleIds($this->admin));
        $this->assertNotContains($this->byPm->id, $this->visibleIds($this->pm));
    }

    public function test_tasks_of_another_company_are_never_visible(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $project = app(ProjectService::class)->create($other, $otherAdmin, ['name' => 'Theirs', 'key' => 'THR']);
        $foreign = $this->task($project, $otherAdmin);

        $this->assertNotContains($foreign->id, $this->visibleIds($this->admin));
        // The same holds for the person who runs the other company's world from outside any request context.
        $this->assertSame([$foreign->id], Task::withoutGlobalScopes()->where('organization_id', $other->id)->pluck('id')->all());
    }

    // ---- the same rules through the pages -------------------------------------------------

    public function test_the_task_page_follows_the_same_rules(): void
    {
        $this->actingAsMember($this->dev1)->get('/tasks/ECP-1')->assertOk();
    }

    public function test_other_teams_tasks_return_forbidden_and_other_companies_not_found(): void
    {
        $this->byDev3->load('project');
        $this->actingAsMember($this->dev1)->get('/tasks/OTH-1')->assertForbidden();
        $this->actingAsMember($this->dev1)->get('/tasks/ECP-1')->assertOk();
        $this->actingAsMember($this->dev1)->get('/tasks/NOPE-1')->assertNotFound();
        $this->actingAsMember($this->dev1)->get('/tasks/ECP-999')->assertNotFound();
    }

    public function test_an_external_client_cannot_open_the_teams_tasks(): void
    {
        $this->actingAsMember($this->client)->get('/tasks/ECP-1')->assertForbidden(); // reported by the PM
        $this->actingAsMember($this->client)->get('/tasks/ECP-4')->assertOk(); // their own
    }

    public function test_a_task_key_from_another_company_does_not_resolve(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $project = app(ProjectService::class)->create($other, $otherAdmin, ['name' => 'Theirs', 'key' => 'THR']);
        $this->task($project, $otherAdmin);

        $this->actingAsMember($this->admin)->get('/tasks/THR-1')->assertNotFound();
    }
}
