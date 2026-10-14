<?php

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Models\ProjectStatus;
use App\Services\WorkflowService;
use App\Support\DefaultWorkflow;
use App\Support\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Project settings > Workflow: statuses (board columns) and the optional allowed moves between them. */
class WorkflowController extends ProjectBaseController
{
    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Workflow', [
            'project' => $this->header($project),
            'statuses' => $project->statuses()->get()->map(fn (ProjectStatus $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'color' => $s->color,
                'position' => $s->position,
                'is_initial' => $s->is_initial,
                'show_on_board' => $s->show_on_board,
                'task_count' => $s->taskCount(),
            ]),
            'transitions' => $project->transitions()->get(['from_status_id', 'to_status_id', 'required_permission']),
            'categories' => DefaultWorkflow::CATEGORIES,
            // Only task permissions make sense as the requirement for a move.
            'permissions' => collect(PermissionCatalog::names())->filter(fn ($n) => str_starts_with($n, 'task.'))->values(),
        ]);
    }

    public function store(Request $request, Project $project, WorkflowService $workflow): RedirectResponse
    {
        Gate::authorize('manageWorkflow', $project);

        $workflow->createStatus($project, $this->validated($request));

        return back()->with('success', 'Status added.');
    }

    public function update(Request $request, Project $project, ProjectStatus $status, WorkflowService $workflow): RedirectResponse
    {
        Gate::authorize('manageWorkflow', $project);
        abort_unless($status->project_id === $project->id, 404);

        $workflow->updateStatus($status, $this->validated($request));

        return back()->with('success', 'Status updated.');
    }

    public function destroy(Project $project, ProjectStatus $status, WorkflowService $workflow): RedirectResponse
    {
        Gate::authorize('manageWorkflow', $project);
        abort_unless($status->project_id === $project->id, 404);

        $workflow->deleteStatus($status);

        return back()->with('success', 'Status deleted.');
    }

    public function reorder(Request $request, Project $project, WorkflowService $workflow): RedirectResponse
    {
        Gate::authorize('manageWorkflow', $project);

        $data = $request->validate(['order' => ['required', 'array', 'min:1'], 'order.*' => ['integer']]);

        $workflow->reorder($project, $data['order']);

        return back();
    }

    public function transitions(Request $request, Project $project, WorkflowService $workflow): RedirectResponse
    {
        Gate::authorize('manageWorkflow', $project);

        $data = $request->validate([
            'moves' => ['present', 'array'],
            'moves.*.from_status_id' => ['required', 'integer'],
            'moves.*.to_status_id' => ['required', 'integer'],
            'moves.*.required_permission' => ['nullable', 'string', 'max:100'],
        ]);

        $workflow->syncTransitions($project, $data['moves']);

        return back()->with('success', $data['moves'] ? 'Allowed moves saved.' : 'Every move is allowed again.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'category' => ['required', Rule::in(DefaultWorkflow::CATEGORIES)],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'show_on_board' => ['boolean'],
            'is_initial' => ['boolean'],
        ]);
    }
}
