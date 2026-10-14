<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Deleted tasks of a project, for the people who may restore them. */
class TrashController extends Controller
{
    public function __invoke(Project $project, CurrentOrganization $current): Response
    {
        Gate::authorize('view', $project);

        $me = $current->membership;
        abort_unless($me->hasPermission('task.delete_any') || Gate::allows('manageMembers', $project), 403);

        $tasks = Task::onlyTrashed()->where('project_id', $project->id)->with(['project', 'status', 'reporter.user:id,name'])
            ->orderByDesc('deleted_at')->limit(100)->get();

        return Inertia::render('Tasks/Trash', [
            'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name],
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'key' => $t->key_label,
                'title' => $t->title,
                'type' => $t->type,
                'status' => $t->status->name,
                'reporter' => $t->reporter->user->name,
                'deleted_at' => $t->deleted_at->toIso8601String(),
                'can_restore' => Gate::allows('restore', $t),
            ])->values(),
        ]);
    }
}
