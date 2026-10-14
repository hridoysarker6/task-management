<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Services\TaskService;
use App\Services\TriageService;
use App\Support\TaskPresenter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The triage queue of a project: requests from stakeholders waiting to be accepted, rejected or clarified. */
class TriageController extends Controller
{
    public function __construct(private CurrentOrganization $current, private TriageService $triage) {}

    public function index(Project $project, TaskService $tasks): Response
    {
        Gate::authorize('view', $project);
        Gate::authorize('task.triage');

        $me = $this->current->membership;

        $queue = Task::visibleTo($me)->where('project_id', $project->id)
            ->whereHas('status', fn ($q) => $q->where('category', 'triage'))
            ->with(['project', 'status', 'assignee.user:id,name', 'reporter.user:id,name'])
            ->orderBy('created_at')->get();

        return Inertia::render('Tasks/Triage', [
            'project' => ['id' => $project->id, 'key' => $project->key, 'name' => $project->name, 'triage_mode' => $project->triage_mode],
            'requests' => $queue->map(fn (Task $t) => TaskPresenter::row($t) + [
                'description' => $t->description,
                'reporter' => $t->reporter->user->name,
                'external' => $t->reporter->isExternal(),
                'created_at' => $t->created_at->toIso8601String(),
            ])->values(),
            'assignees' => $tasks->assigneeOptions($project, $me),
            'types' => Task::TYPES,
            'priorities' => collect(Task::PRIORITIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function accept(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTriage($task);

        $data = $request->validate([
            'type' => ['nullable', Rule::in(Task::TYPES)],
            'priority' => ['nullable', 'integer', 'between:1,5'],
            'assignee_id' => ['nullable', 'integer'],
        ]);

        $this->triage->accept($task, $this->current->membership, $data);

        return back()->with('success', "{$task->key_label} accepted and moved to the backlog.");
    }

    public function reject(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTriage($task);

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->triage->reject($task, $this->current->membership, $data['reason']);

        return back()->with('success', "{$task->key_label} rejected.");
    }

    public function askForInfo(Request $request, Task $task): RedirectResponse
    {
        $this->authorizeTriage($task);

        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);

        $this->triage->askForInfo($task, $this->current->membership, $data['message']);

        return back()->with('success', 'Your question was posted on the request.');
    }

    private function authorizeTriage(Task $task): void
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('triage', $task);
    }
}
