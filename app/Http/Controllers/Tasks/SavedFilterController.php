<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Support\TaskFilters;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Saving the current filters of the list or board under a name, privately or shared with the project. */
class SavedFilterController extends Controller
{
    private const MAX_PER_PROJECT = 50;

    public function __construct(private CurrentOrganization $current) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:100'],
            'view' => ['required', Rule::in(['list', 'board'])],
            'filters' => ['present', 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ]);

        $project = Project::findOrFail($data['project_id']);
        Gate::authorize('view', $project);

        $me = $this->current->membership;
        $name = trim($data['name']);

        $mine = SavedFilter::where('membership_id', $me->id)->where('project_id', $project->id);
        if ((clone $mine)->count() >= self::MAX_PER_PROJECT) {
            throw ValidationException::withMessages(['name' => 'You can keep up to '.self::MAX_PER_PROJECT.' saved filters per project. Delete some first.']);
        }
        if ((clone $mine)->where('view', $data['view'])->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
            throw ValidationException::withMessages(['name' => 'You already have a saved filter with that name.']);
        }

        $filters = TaskFilters::clean($data['filters']);
        if ($filters === []) {
            throw ValidationException::withMessages(['filters' => 'Set at least one filter before saving.']);
        }

        SavedFilter::create([
            'membership_id' => $me->id,
            'project_id' => $project->id,
            'name' => $name,
            'view' => $data['view'],
            'filters' => $filters,
            'is_shared' => (bool) ($data['is_shared'] ?? false),
        ]);

        return back()->with('success', "Filter \"{$name}\" saved.");
    }

    /** Only the owner can delete a saved filter; everyone else just does not find it. */
    public function destroy(SavedFilter $savedFilter): RedirectResponse
    {
        $me = $this->current->membership;
        $project = Project::find($savedFilter->project_id);

        abort_unless($project && Gate::allows('view', $project), 404);
        abort_unless($savedFilter->membership_id === $me->id, 404);

        $savedFilter->delete();

        return back()->with('success', 'Saved filter deleted.');
    }
}
