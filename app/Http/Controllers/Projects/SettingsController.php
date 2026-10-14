<?php

namespace App\Http\Controllers\Projects;

use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Project settings: sprints, triage, client visibility, self-close and how progress is measured. */
class SettingsController extends ProjectBaseController
{
    public function edit(Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Settings', [
            'project' => $this->header($project) + [
                'uses_sprints' => $project->uses_sprints,
                'triage_mode' => $project->triage_mode,
                'client_can_see_all' => $project->client_can_see_all,
                'allow_self_close' => $project->allow_self_close,
                'progress_mode' => $project->progress_mode,
                'moves' => Project::MOVES[$project->status] ?? [],
            ],
        ]);
    }

    public function update(Request $request, Project $project, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('manageSettings', $project);

        $data = $request->validate([
            'uses_sprints' => ['required', 'boolean'],
            'triage_mode' => ['required', Rule::in(Project::TRIAGE_MODES)],
            'client_can_see_all' => ['required', 'boolean'],
            'allow_self_close' => ['required', 'boolean'],
            'progress_mode' => ['required', Rule::in(Project::PROGRESS_MODES)],
        ]);

        $projects->updateSettings($project, $data);

        return back()->with('success', 'Settings saved.');
    }
}
