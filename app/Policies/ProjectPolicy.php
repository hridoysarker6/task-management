<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * Three checks together (WORKFLOW.md section 4): the permission, the data scope (can they see the project at all),
 * and project membership. A project's own manager can run it without holding the company-wide permissions.
 */
class ProjectPolicy
{
    private function me(User $user): ?Membership
    {
        $membership = app(CurrentOrganization::class)->membership;

        return $membership && $membership->user_id === $user->id ? $membership : null;
    }

    private function can(User $user, string $permission): bool
    {
        return $this->me($user)?->hasPermission($permission) ?? false;
    }

    /** Is this person the project's manager (by role in the project)? */
    public function isManager(User $user, Project $project): bool
    {
        $me = $this->me($user);

        return $me !== null && (
            $project->manager_membership_id === $me->id
            || ProjectMember::where('project_id', $project->id)->where('membership_id', $me->id)
                ->where('project_role', 'manager')->whereNull('left_at')->exists()
        );
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'project.create');
    }

    /** May they add tasks to this project? (the rule lives in TaskPolicy; this lets `Gate::allows('createTask', $project)` find it) */
    public function createTask(User $user, Project $project): bool
    {
        return app(TaskPolicy::class)->create($user, $project);
    }

    public function view(User $user, Project $project): bool
    {
        $me = $this->me($user);

        return $me !== null
            && $project->organization_id === $me->organization_id
            && $this->can($user, 'project.view')
            && Project::visibleTo($me)->whereKey($project->id)->exists();
    }

    /** Edit the details. Not allowed on archived projects. */
    public function update(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'project.edit');
    }

    /** Move between active / on hold / completed / archived and back. */
    public function changeStatus(User $user, Project $project): bool
    {
        return $this->view($user, $project) && ($this->can($user, 'project.archive') || $this->isManager($user, $project));
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $this->can($user, 'project.delete');
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'project.manage_members');
    }

    public function manageWorkflow(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'project.manage_workflow');
    }

    public function manageSettings(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'project.manage_settings');
    }

    public function manageLabels(User $user, Project $project): bool
    {
        return $this->manage($user, $project, 'label.manage');
    }

    private function manage(User $user, Project $project, string $permission): bool
    {
        return ! $project->isArchived()
            && $this->view($user, $project)
            && ($this->can($user, $permission) || $this->isManager($user, $project));
    }
}
