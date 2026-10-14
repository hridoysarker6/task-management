<?php

namespace App\Http\Controllers\Projects;

use App\Models\Label;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LabelController extends ProjectBaseController
{
    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Labels', [
            'project' => $this->header($project),
            'labels' => $project->labels()->get(['id', 'name', 'color']),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('manageLabels', $project);

        $project->labels()->create($this->validated($request, $project));

        return back()->with('success', 'Label added.');
    }

    public function update(Request $request, Project $project, Label $label): RedirectResponse
    {
        Gate::authorize('manageLabels', $project);
        abort_unless($label->project_id === $project->id, 404);

        $label->update($this->validated($request, $project, $label));

        return back()->with('success', 'Label updated.');
    }

    public function destroy(Project $project, Label $label): RedirectResponse
    {
        Gate::authorize('manageLabels', $project);
        abort_unless($label->project_id === $project->id, 404);

        $label->delete();

        return back()->with('success', 'Label deleted.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Project $project, ?Label $label = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:50',
                Rule::unique('labels', 'name')->where('project_id', $project->id)->ignore($label?->id)],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => 'This project already has a label with that name.']);
    }
}
