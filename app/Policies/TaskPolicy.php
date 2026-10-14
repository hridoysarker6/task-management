<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;

/**
 * The three checks of WORKFLOW section 4 for tasks: the permission (task.edit_own / edit_any, ...), the data scope
 * and project membership (can they see the task at all), and project role (a project manager runs their project).
 * Rules that depend on the data being changed (who may be assigned, which moves are allowed) live in TaskService.
 */
class TaskPolicy
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

    private function projectRole(Project $project, Membership $me): ?string
    {
        return ProjectMember::where('project_id', $project->id)->where('membership_id', $me->id)->whereNull('left_at')->value('project_role');
    }

    private function isProjectManager(Task $task, Membership $me): bool
    {
        $project = Project::withTrashed()->find($task->project_id);

        return $project && ($project->manager_membership_id === $me->id || $this->projectRole($project, $me) === 'manager');
    }

    /** Can they add tasks to this project? A permission, plus a place in the project that is not read-only. */
    public function create(User $user, Project $project): bool
    {
        $me = $this->me($user);

        return $me !== null
            && $project->organization_id === $me->organization_id
            && ! $project->isArchived()
            && $this->can($user, 'task.create')
            && in_array($this->projectRole($project, $me), ['manager', 'member', 'stakeholder'], true);
    }

    public function view(User $user, Task $task): bool
    {
        $me = $this->me($user);

        return $me !== null
            && $task->organization_id === $me->organization_id
            && $this->can($user, 'task.view')
            && $task->deleted_at === null
            && Task::visibleTo($me)->whereKey($task->id)->exists();
    }

    /** Edit the fields. Own = created by them; any = anything they can see; a project manager may edit everything in their project. */
    public function update(User $user, Task $task): bool
    {
        $me = $this->me($user);

        return $me !== null && $this->open($task) && $this->view($user, $task) && (
            $this->can($user, 'task.edit_any')
            || $this->isProjectManager($task, $me)
            || ($this->can($user, 'task.edit_own') && ($task->reporter_id === $me->id || $task->created_by === $me->id))
        );
    }

    public function changeStatus(User $user, Task $task): bool
    {
        // The finer rules (close, reopen, allowed moves) are checked per move in TaskService::changeStatus.
        return $this->open($task) && $this->view($user, $task) && (
            $this->can($user, 'task.change_status') || $this->can($user, 'task.close') || $this->can($user, 'task.reopen')
        );
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->open($task) && $this->view($user, $task) && (
            $this->can($user, 'task.assign') || $this->can($user, 'task.change_status')
        );
    }

    public function delete(User $user, Task $task): bool
    {
        $me = $this->me($user);

        return $me !== null && $this->open($task) && $this->view($user, $task) && (
            $this->can($user, 'task.delete_any')
            || $this->isProjectManager($task, $me)
            || ($this->can($user, 'task.delete_own') && $task->reporter_id === $me->id)
        );
    }

    /** Bring a deleted task back: those who could delete it, even though it is hidden now. */
    public function restore(User $user, Task $task): bool
    {
        $me = $this->me($user);

        if ($me === null || $task->organization_id !== $me->organization_id || $task->deleted_at === null || ! $this->can($user, 'task.view')) {
            return false;
        }

        $project = Project::withTrashed()->find($task->project_id);

        return $project !== null && ! $project->isArchived() && ($this->can($user, 'task.delete_any') || $this->isProjectManager($task, $me))
            && Project::visibleTo($me)->whereKey($task->project_id)->exists();
    }

    public function triage(User $user, Task $task): bool
    {
        return $this->open($task) && $this->view($user, $task) && $this->can($user, 'task.triage');
    }

    public function link(User $user, Task $task): bool
    {
        return $this->open($task) && $this->view($user, $task) && $this->can($user, 'task.link');
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->open($task) && $this->view($user, $task) && $this->can($user, 'comment.create');
    }

    public function editComment(User $user, Comment $comment): bool
    {
        $me = $this->me($user);

        return $me !== null && $comment->membership_id === $me->id && $this->can($user, 'comment.edit_own')
            && ($task = Task::find($comment->task_id)) && $this->open($task) && $this->view($user, $task);
    }

    public function deleteComment(User $user, Comment $comment): bool
    {
        $me = $this->me($user);
        $task = Task::find($comment->task_id);

        return $me !== null && $task && $this->open($task) && $this->view($user, $task) && (
            $this->can($user, 'comment.delete_any')
            || ($comment->membership_id === $me->id && $this->can($user, 'comment.delete_own'))
        );
    }

    public function attach(User $user, Task $task): bool
    {
        return $this->open($task) && $this->view($user, $task) && $this->can($user, 'attachment.upload');
    }

    public function deleteAttachment(User $user, Attachment $attachment): bool
    {
        $me = $this->me($user);
        $task = Task::find($attachment->attachable_id);

        return $me !== null && $task && $this->open($task) && $this->view($user, $task) && (
            $this->can($user, 'attachment.delete_any')
            || ($attachment->membership_id === $me->id && $this->can($user, 'attachment.delete_own'))
        );
    }

    /** Watching only needs to be able to see the task. */
    public function watch(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    /** Nothing can be changed on a task whose project is archived. */
    private function open(Task $task): bool
    {
        $project = Project::withTrashed()->find($task->project_id);

        return $project !== null && ! $project->isArchived();
    }
}
