<?php

namespace Tests\Feature\Tasks;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Task;
use App\Policies\TaskPolicy;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/**
 * What each role may do with a task: permission x data scope x project membership (WORKFLOW.md section 4).
 * Rules that depend on the data being changed (who can be assigned, which status moves) are tested in TaskWorkflowTest.
 */
class TaskPolicyTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    private const ABILITIES = ['view', 'update', 'changeStatus', 'assign', 'delete', 'triage', 'link', 'comment', 'attach', 'watch'];

    private Task $task; // reported by TL-A, in ECP

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
        $this->task = $this->task($this->ecp, $this->tlA);
    }

    /** @return array<string, bool> */
    private function can(Membership $m, ?Task $task = null): array
    {
        $this->enterCompany($m->fresh());
        $this->actingAs($m->user);
        $task ??= $this->task;

        return collect(self::ABILITIES)->mapWithKeys(fn ($a) => [$a => Gate::allows($a, $task)])->all();
    }

    private function only(string ...$allowed): array
    {
        return collect(self::ABILITIES)->mapWithKeys(fn ($a) => [$a => in_array($a, $allowed, true)])->all();
    }

    private function everything(): array
    {
        return $this->only(...self::ABILITIES);
    }

    public function test_the_company_admin_can_do_everything(): void
    {
        $this->assertEquals($this->everything(), $this->can($this->admin));
    }

    public function test_the_project_manager_runs_the_tasks_of_their_project(): void
    {
        $this->assertEquals($this->everything(), $this->can($this->pm));
    }

    public function test_the_owner_team_lead(): void
    {
        $this->assertEquals($this->everything(), $this->can($this->tlA)); // edit_any, delete_own (reporter), triage, link
    }

    public function test_a_team_member_can_work_on_but_not_manage_the_task(): void
    {
        $this->assertEquals($this->only('view', 'changeStatus', 'assign', 'comment', 'attach', 'watch'), $this->can($this->dev1));
    }

    public function test_the_dm_oversees_a_team_they_are_not_on_a_project_with(): void
    {
        // The task's reporter is in their team: they can see and run it, but only HOD-level roles can delete anyone's.
        $this->assertEquals($this->only('view', 'update', 'changeStatus', 'assign', 'triage', 'link', 'comment', 'attach', 'watch'), $this->can($this->dm));
    }

    public function test_the_hod_sees_their_departments_tasks_and_can_delete_them(): void
    {
        $this->assertEquals($this->everything(), $this->can($this->hod));
    }

    public function test_the_other_teams_people_can_do_nothing(): void
    {
        $this->assertEquals($this->only(), $this->can($this->dev3));
        $this->assertEquals($this->only(), $this->can($this->tlB));
    }

    public function test_an_internal_stakeholder_outside_the_project_can_do_nothing(): void
    {
        $this->assertEquals($this->only(), $this->can($this->stakeholder));
    }

    public function test_a_client_cannot_touch_the_teams_tasks(): void
    {
        $this->assertEquals($this->only(), $this->can($this->client));
    }

    public function test_a_client_can_comment_attach_edit_and_reopen_their_own_request(): void
    {
        $own = $this->task($this->ecp, $this->client);

        // view, edit_own, reopen (so status), delete_own, comment, attach, watch. No assign, triage or link.
        $this->assertEquals($this->only('view', 'update', 'changeStatus', 'delete', 'comment', 'attach', 'watch'), $this->can($this->client, $own));
    }

    public function test_nothing_but_viewing_and_watching_is_allowed_in_an_archived_project(): void
    {
        $this->ecp->forceFill(['status' => Project::ARCHIVED])->save();

        $this->assertEquals($this->only('view', 'watch'), $this->can($this->admin));
        $this->assertEquals($this->only('view', 'watch'), $this->can($this->pm));
    }

    public function test_deleted_tasks_can_no_longer_be_viewed(): void
    {
        $this->task->delete();

        $this->assertEquals($this->only(), $this->can($this->admin, Task::withTrashed()->find($this->task->id)));
    }

    public function test_a_revoked_permission_removes_the_ability_even_for_the_owner(): void
    {
        $perms = app(PermissionService::class);

        // The team lead reported the task, so they keep editing it through edit_own until that goes too.
        $perms->override($this->tlA, 'task.edit_any', 'revoke');
        $this->assertTrue($this->can($this->tlA)['update']);

        $perms->override($this->tlA, 'task.edit_own', 'revoke');
        $perms->override($this->tlA, 'comment.create', 'revoke');
        $abilities = $this->can($this->tlA);

        $this->assertFalse($abilities['update']);
        $this->assertFalse($abilities['comment']);
        $this->assertTrue($abilities['view'], 'seeing the task needs no edit permission');
    }

    public function test_a_granted_permission_adds_the_ability_inside_the_scope_only(): void
    {
        app(PermissionService::class)->override($this->dev1, 'task.edit_any', 'grant');
        app(PermissionService::class)->override($this->dev3, 'task.edit_any', 'grant');

        $this->assertTrue($this->can($this->dev1)['update']);
        $this->assertFalse($this->can($this->dev3)['update'], 'the permission alone does not reach a task they cannot see');
    }

    public function test_edit_own_means_created_by_not_assigned_to(): void
    {
        $mine = $this->task($this->ecp, $this->dev1);
        $assigned = $this->task($this->ecp, $this->tlA, ['assignee_id' => $this->dev1->id]);

        $this->assertTrue($this->can($this->dev1, $mine)['update']);
        $this->assertFalse($this->can($this->dev1, $assigned)['update']);
    }

    public function test_delete_own_means_reported_by(): void
    {
        $mine = $this->task($this->ecp, $this->dev1);

        $this->assertTrue($this->can($this->dev1, $mine)['delete']);
        $this->assertFalse($this->can($this->dev1)['delete']);
    }

    public function test_restoring_needs_delete_rights_over_a_deleted_task_in_a_visible_project(): void
    {
        $this->task->delete();
        $deleted = Task::withTrashed()->find($this->task->id);

        $this->enterCompany($this->pm->fresh());
        $this->actingAs($this->pm->user);
        $this->assertTrue(Gate::allows('restore', $deleted));

        foreach ([$this->dev1, $this->dev3, $this->client, $this->stakeholder] as $nobody) {
            $this->enterCompany($nobody->fresh());
            $this->actingAs($nobody->user);
            $this->assertFalse(Gate::allows('restore', $deleted), $nobody->user->name);
        }

    }

    public function test_creating_follows_membership_and_role_in_the_project(): void
    {
        $allowed = fn (Membership $m, Project $p) => $this->can2($m, $p);

        $this->assertTrue($allowed($this->pm, $this->ecp));
        $this->assertTrue($allowed($this->dev1, $this->ecp));
        $this->assertTrue($allowed($this->client, $this->ecp)); // stakeholders raise requests
        $this->assertFalse($allowed($this->dev1, $this->oth));
        $this->assertFalse($allowed($this->dev2, $this->ecp));
        $this->assertFalse($allowed($this->admin, $this->ecp), 'a company admin must join the project to add to it');
    }

    private function can2(Membership $m, Project $p): bool
    {
        $this->enterCompany($m->fresh());
        $this->actingAs($m->user);

        return Gate::allows('createTask', $p);
    }

    public function test_the_policy_is_registered_for_tasks_comments_and_attachments(): void
    {
        $this->assertInstanceOf(TaskPolicy::class, Gate::getPolicyFor(Task::class));
        $this->assertInstanceOf(TaskPolicy::class, Gate::getPolicyFor(Comment::class));
        $this->assertInstanceOf(TaskPolicy::class, Gate::getPolicyFor(Attachment::class));
    }

    public function test_without_a_company_context_nothing_is_allowed(): void
    {
        $this->actingAs($this->admin->user);

        $this->assertFalse(Gate::allows('view', $this->task));
        $this->assertFalse(Gate::allows('createTask', $this->ecp));
    }
}
