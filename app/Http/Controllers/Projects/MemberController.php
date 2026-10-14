<?php

namespace App\Http\Controllers\Projects;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends ProjectBaseController
{
    private const ASSIGNABLE = ['member', 'stakeholder', 'viewer'];

    public function index(Project $project): Response
    {
        Gate::authorize('view', $project);

        $rows = ProjectMember::where('project_id', $project->id)->with('membership.user:id,name,email')
            ->orderByRaw("case project_role when 'manager' then 0 when 'member' then 1 when 'stakeholder' then 2 else 3 end")
            ->get();

        $current = $rows->whereNull('left_at');
        $manage = Gate::allows('manageMembers', $project);

        return Inertia::render('Projects/Members', [
            'project' => $this->header($project),
            'members' => $current->map(fn (ProjectMember $m) => $this->row($m))->values(),
            'former' => $rows->whereNotNull('left_at')->map(fn (ProjectMember $m) => $this->row($m))->values(),
            'candidates' => $manage ? Membership::active()
                ->whereNotIn('id', $current->pluck('membership_id'))
                ->with(['user:id,name', 'role:id,name'])->get()
                ->sortBy(fn ($m) => $m->user->name)
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name, 'external' => $m->isExternal()])->values() : [],
            'roles' => self::ASSIGNABLE,
        ]);
    }

    public function store(Request $request, Project $project, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('manageMembers', $project);

        $data = $request->validate([
            'membership_id' => ['required', 'integer'],
            'project_role' => ['required', Rule::in(self::ASSIGNABLE)],
        ]);

        $member = Membership::active()->find($data['membership_id']);
        if (! $member instanceof Membership) {
            abort(404);
        }

        $projects->addMember($project, $member, $data['project_role'], $this->current->membership);

        return back()->with('success', "{$member->user->name} added to the project.");
    }

    public function update(Request $request, Project $project, ProjectMember $member, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('manageMembers', $project);
        abort_unless($member->project_id === $project->id, 404);

        $data = $request->validate(['project_role' => ['required', Rule::in(self::ASSIGNABLE)]]);

        $projects->changeMemberRole($project, $member, $data['project_role']);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Project $project, ProjectMember $member, ProjectService $projects): RedirectResponse
    {
        Gate::authorize('manageMembers', $project);
        abort_unless($member->project_id === $project->id, 404);

        $projects->removeMember($project, $member);

        return back()->with('success', 'Removed from the project.');
    }

    /** @return array<string, mixed> */
    private function row(ProjectMember $m): array
    {
        return [
            'id' => $m->id,
            'membership_id' => $m->membership_id,
            'name' => $m->membership->user->name,
            'email' => $m->membership->user->email,
            'project_role' => $m->project_role,
            'external' => $m->membership->user_type === 'external',
            'joined_at' => $m->joined_at?->toDateString(),
            'left_at' => $m->left_at?->toDateString(),
            'is_former_employee' => $m->membership->deleted_at !== null,
        ];
    }
}
