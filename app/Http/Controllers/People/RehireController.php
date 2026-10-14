<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Role;
use App\Services\RehireService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RehireController extends Controller
{
    public function __construct(private RehireService $rehires, private CurrentOrganization $current) {}

    public function create(Membership $membership): Response|RedirectResponse
    {
        Gate::authorize('user.rehire');

        if ($membership->deleted_at === null || $membership->status !== Membership::EXITED) {
            return redirect()->route('people.show', $membership);
        }

        $membership->load(['user', 'role']);
        $me = $this->current->membership;

        return Inertia::render('People/Rehire', [
            'person' => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'last_role' => $membership->role->name,
                'designation' => $membership->designation,
                'employee_code' => $membership->employee_code,
                'left_at' => $membership->left_at?->toDateString(),
                'exit_type' => $membership->exit_type,
            ],
            'history' => $membership->employmentPeriods()->with(['role', 'department', 'manager.user'])->get()->map(fn ($p) => [
                'id' => $p->id,
                'joined_at' => $p->joined_at->toDateString(),
                'left_at' => $p->left_at?->toDateString(),
                'role' => $p->role?->name,
                'department' => $p->department?->name,
                'manager' => $p->manager?->user?->name,
                'exit_type' => $p->exit_type,
            ]),
            'roles' => Role::where('level', '>=', $me->role->level)->orderBy('level')->get(['id', 'name', 'level', 'is_external']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'managers' => Membership::active()->where('user_type', 'internal')->with(['user:id,name', 'role:id,name,level'])->get()
                ->sortBy(fn ($m) => $m->user->name)
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name, 'level' => $m->role->level])->values(),
            'canArchive' => $this->rehires->canArchive($membership) && Gate::allows('user.invite'),
            'previousProjects' => $this->rehires->previousProjects($membership)->map(fn ($p) => ['id' => $p->id, 'key' => $p->key, 'name' => $p->name]),
            'today' => today()->toDateString(),
        ]);
    }

    /** "Same person": restore the old membership with a new role and manager. */
    public function store(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('user.rehire');

        $data = $request->validate([
            'role_id' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'reports_to_id' => ['nullable', 'integer'],
            'designation' => ['nullable', 'string', 'max:100'],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'joined_at' => ['nullable', 'date'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer'],
        ]);

        $member = $this->rehires->rehire($membership, $this->current->membership, $data);

        return redirect()->route('people.show', $member)->with('success', "{$member->user->name} has been rehired.");
    }

    /** "Different person, same email": archive the old account so the email can be invited afresh. */
    public function differentPerson(Membership $membership): RedirectResponse
    {
        Gate::authorize('user.rehire');
        Gate::authorize('user.invite');

        $email = $membership->user->email;
        $this->rehires->archiveOldAccount($membership);

        return redirect()->route('people.invite', ['email' => $email])
            ->with('success', 'The old account was archived. Invite the new person with this email.');
    }
}
