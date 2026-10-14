<?php

namespace App\Http\Controllers\People;

use App\Exceptions\FormerMemberException;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Role;
use App\Services\InvitationService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Company side of invitations: invite, resend, revoke. */
class InvitationController extends Controller
{
    public function __construct(private InvitationService $invitations, private CurrentOrganization $current) {}

    public function create(Request $request): Response
    {
        Gate::authorize('user.invite');

        $me = $this->current->membership;

        return Inertia::render('People/Invite', [
            'email' => (string) $request->query('email', ''),
            'roles' => Role::where('level', '>=', $me->role->level)->orderBy('level')->get(['id', 'name', 'level', 'is_external']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            // Managers are picked from the active internal members; rank is checked on save.
            'managers' => Membership::active()->where('user_type', 'internal')->with(['user:id,name', 'role:id,name,level'])->get()
                ->sortBy(fn ($m) => $m->user->name)
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name, 'level' => $m->role->level])
                ->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('user.invite');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => ['nullable', 'string', 'max:150'],
            'role_id' => ['required', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'reports_to_id' => ['nullable', 'integer'],
            'designation' => ['nullable', 'string', 'max:100'],
            'employee_code' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $this->invitations->invite($this->current->organization, $this->current->membership, $data);
        } catch (FormerMemberException $e) {
            return redirect()->route('people.rehire.create', $e->membership)
                ->with('warning', 'This email belongs to a former employee. You can rehire them below.');
        }

        return redirect()->route('people.index', ['tab' => 'invited'])->with('success', "Invitation sent to {$data['email']}.");
    }

    public function resend(Invitation $invitation): RedirectResponse
    {
        Gate::authorize('user.invite');

        $this->invitations->resend($invitation, $this->current->membership);

        return back()->with('success', "Invitation re-sent to {$invitation->email}.");
    }

    public function destroy(Invitation $invitation): RedirectResponse
    {
        Gate::authorize('user.invite');

        $this->invitations->revoke($invitation);

        return back()->with('success', 'Invitation revoked.');
    }
}
