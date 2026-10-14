<?php

namespace Tests\Concerns;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectService;

/**
 * A small company for task tests:
 *
 *   HOD (dept D1) > DM (D1) > TL-A (D1) > Dev1 (D1), Dev2
 *                       \---> TL-B      > Dev3
 *   plus PM (project manager), an internal stakeholder and an external client.
 *
 * Project ECP: PM manages; TL-A, Dev1 are members; the client is a stakeholder. Project OTH: TL-B and Dev3 are members.
 * Dev2 and the internal stakeholder belong to no project.
 */
trait BuildsTaskWorld
{
    use InteractsWithTenants;

    protected Organization $org;

    protected Department $dept;

    protected Membership $admin;

    protected Membership $hod;

    protected Membership $dm;

    protected Membership $tlA;

    protected Membership $tlB;

    protected Membership $dev1;

    protected Membership $dev2;

    protected Membership $dev3;

    protected Membership $pm;

    protected Membership $stakeholder;

    protected Membership $client;

    protected Project $ecp;

    protected Project $oth;

    protected function buildWorld(): void
    {
        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
        $this->dept = Department::factory()->create(['organization_id' => $this->org->id, 'name' => 'Software']);

        $this->hod = $this->makeMember($this->org, 'hod', ['department_id' => $this->dept->id]);
        $this->dm = $this->makeMember($this->org, 'dm', ['department_id' => $this->dept->id, 'reports_to_id' => $this->hod->id]);
        $this->tlA = $this->makeMember($this->org, 'team-lead', ['department_id' => $this->dept->id, 'reports_to_id' => $this->dm->id]);
        $this->tlB = $this->makeMember($this->org, 'team-lead', ['reports_to_id' => $this->dm->id]);
        $this->dev1 = $this->makeMember($this->org, 'developer', ['department_id' => $this->dept->id, 'reports_to_id' => $this->tlA->id]);
        $this->dev2 = $this->makeMember($this->org, 'developer', ['reports_to_id' => $this->tlA->id]);
        $this->dev3 = $this->makeMember($this->org, 'developer', ['reports_to_id' => $this->tlB->id]);
        $this->pm = $this->makeMember($this->org, 'project-manager');
        $this->stakeholder = $this->makeMember($this->org, 'internal-stakeholder');
        $this->client = $this->makeMember($this->org, 'external-stakeholder');

        $projects = app(ProjectService::class);
        $this->ecp = $projects->create($this->org, $this->admin, ['name' => 'E-Commerce', 'key' => 'ECP', 'manager_membership_id' => $this->pm->id]);
        $projects->addMember($this->ecp, $this->tlA, 'member');
        $projects->addMember($this->ecp, $this->dev1, 'member');
        $projects->addMember($this->ecp, $this->client, 'stakeholder');

        $this->oth = $projects->create($this->org, $this->admin, ['name' => 'Other', 'key' => 'OTH']);
        $projects->addMember($this->oth, $this->tlB, 'member');
        $projects->addMember($this->oth, $this->dev3, 'member');
    }

    /** A task created directly (no permission checks), in the project's starting status unless 'status' (name) is given. */
    protected function task(Project $project, ?Membership $reporter = null, array $attributes = []): Task
    {
        $status = $project->statuses()->where('name', $attributes['status'] ?? 'Backlog')->firstOrFail();
        unset($attributes['status']);
        $number = $project->task_counter + 1;
        $project->forceFill(['task_counter' => $number])->save();

        return Task::withoutGlobalScopes()->create($attributes + [
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'number' => $number,
            'title' => "Task {$number}",
            'type' => 'task',
            'status_id' => $status->id,
            'priority' => 3,
            'reporter_id' => ($reporter ?? $this->admin)->id,
        ]);
    }

    /** The ids of the tasks a member can see, sorted. */
    protected function visibleIds(Membership $m): array
    {
        $this->enterCompany($m->fresh());

        return Task::visibleTo($m->fresh())->orderBy('id')->pluck('id')->all();
    }
}
