<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;

/**
 * What one person may do to each task of one project, worked out for a whole page at once. It repeats the permission part
 * of `TaskPolicy` (update, changeStatus, assign) without the per-task "can they see it" query, so it is meant for tasks
 * that were already loaded through `Task::visibleTo`. The server still checks the real policy on every request;
 * this only decides which inline controls to offer.
 */
class TaskAbilities
{
    private bool $open;

    private bool $manager;

    private bool $editAny;

    private bool $editOwn;

    private bool $changeStatus;

    private bool $assign;

    public function __construct(private Membership $me, Project $project)
    {
        $this->open = ! $project->isArchived();
        $this->manager = $project->manager_membership_id === $me->id
            || ProjectMember::where('project_id', $project->id)->where('membership_id', $me->id)->whereNull('left_at')->value('project_role') === 'manager';
        $this->editAny = $me->hasPermission('task.edit_any');
        $this->editOwn = $me->hasPermission('task.edit_own');
        $this->changeStatus = $me->hasPermission('task.change_status') || $me->hasPermission('task.close') || $me->hasPermission('task.reopen');
        $this->assign = $me->hasPermission('task.assign') || $me->hasPermission('task.change_status');
    }

    /** @return array{update:bool,status:bool,assign:bool} */
    public function of(Task $task): array
    {
        return [
            'update' => $this->open && ($this->editAny || $this->manager || ($this->editOwn && ($task->reporter_id === $this->me->id || $task->created_by === $this->me->id))),
            'status' => $this->open && $this->changeStatus,
            'assign' => $this->open && $this->assign,
        ];
    }
}
