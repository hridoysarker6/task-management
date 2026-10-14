<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Role;
use App\Services\HierarchyService;
use App\Services\MemberService;
use App\Services\SeatService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PersonController extends Controller
{
    public function __construct(private CurrentOrganization $current, private HierarchyService $hierarchy) {}

    private function me(): Membership
    {
        return $this->current->membership;
    }

    public function index(Request $request): Response
    {
        Gate::authorize('user.view');

        $tab = $request->query('tab', 'active');
        abort_unless(in_array($tab, ['active', 'invited', 'former'], true), 404);
        if ($tab === 'invited') {
            Gate::authorize('user.invite');
        }
        if ($tab === 'former') {
            Gate::authorize('user.view_former');
        }

        $q = trim((string) $request->query('q', ''));
        $roleId = $request->integer('role_id') ?: null;
        $departmentId = $request->integer('department_id') ?: null;

        $people = $this->peopleQuery($tab === 'former' ? 'former' : 'active', $q, $roleId, $departmentId);
        $invitations = Invitation::open()->where('email', 'like', "%{$q}%")
            ->when($roleId, fn ($x) => $x->where('role_id', $roleId))
            ->when($departmentId, fn ($x) => $x->where('department_id', $departmentId));

        $rows = match ($tab) {
            'invited' => $invitations->with(['role', 'department', 'inviter.user'])->latest()->paginate(20)->withQueryString()
                ->through(fn (Invitation $i) => [
                    'id' => $i->id,
                    'email' => $i->email,
                    'name' => $i->name,
                    'role' => $i->role->name,
                    'department' => $i->department?->name,
                    'invited_by' => $i->inviter?->user?->name,
                    'expires_at' => $i->expires_at->toIso8601String(),
                    'state' => $i->state(),
                ]),
            default => $people->with(['user', 'role', 'department', 'manager.user'])->orderBy('id')->paginate(20)->withQueryString()
                ->through(fn (Membership $m) => $this->row($m)),
        };

        $seats = app(SeatService::class);

        return Inertia::render('People/Index', [
            'tab' => $tab,
            'rows' => $rows,
            'filters' => ['q' => $q, 'role_id' => $roleId, 'department_id' => $departmentId],
            'counts' => [
                'active' => $this->peopleQuery('active', '', null, null)->count(),
                'invited' => Gate::allows('user.invite') ? Invitation::open()->count() : null,
                'former' => Gate::allows('user.view_former') ? $this->peopleQuery('former', '', null, null)->count() : null,
            ],
            'roles' => Role::orderBy('level')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'abilities' => [
                'invite' => Gate::allows('user.invite'),
                'import' => Gate::allows('user.import'),
            ],
            'seats' => [
                'limit' => $this->current->organization->user_limit,
                'used' => $seats->used($this->current->organization),
            ],
        ]);
    }

    public function show(Membership $membership): Response
    {
        Gate::authorize('user.view');
        $this->authorizeVisible($membership);

        $isFormer = $membership->deleted_at !== null;
        if ($isFormer) {
            Gate::authorize('user.view_former');
        }

        $membership->load(['user', 'role', 'department', 'manager.user']);
        $me = $this->me();

        $reports = $isFormer ? collect() : $membership->directReports()->where('status', Membership::ACTIVE)->with(['user', 'role'])->get();
        $level = $membership->role->level;

        $editable = ! $isFormer && $membership->role->level >= $me->role->level;

        return Inertia::render('People/Show', [
            'person' => $this->detail($membership),
            'reports' => $reports->map(fn (Membership $r) => ['id' => $r->id, 'name' => $r->user->name, 'role' => $r->role->name]),
            'history' => $membership->employmentPeriods()->with(['role', 'department', 'manager.user'])->get()->map(fn ($p) => [
                'id' => $p->id,
                'joined_at' => $p->joined_at->toDateString(),
                'left_at' => $p->left_at?->toDateString(),
                'role' => $p->role?->name,
                'department' => $p->department?->name,
                'manager' => $p->manager?->user?->name,
                'designation' => $p->designation,
                'exit_type' => $p->exit_type,
                'exit_reason' => $p->exit_reason,
            ]),
            'abilities' => [
                'edit' => $editable && Gate::allows('user.edit'),
                'change_role' => $editable && Gate::allows('user.change_role') && $membership->id !== $me->id,
                'change_manager' => $editable && Gate::allows('user.change_manager') && $membership->id !== $me->id && ! $membership->isExternal(),
                'offboard' => ! $isFormer && Gate::allows('user.offboard') && $membership->id !== $me->id && $membership->role->level >= $me->role->level,
                'rehire' => $isFormer && Gate::allows('user.rehire'),
            ],
            'options' => $editable ? [
                'roles' => Role::where('is_external', $membership->isExternal())->where('level', '>=', $me->role->level)
                    ->orderBy('level')->get(['id', 'name', 'level']),
                'departments' => Department::orderBy('name')->get(['id', 'name']),
                'managers' => $membership->isExternal() ? [] : $this->managerCandidates($membership, $level),
            ] : null,
        ]);
    }

    public function update(Request $request, Membership $membership, MemberService $members): RedirectResponse
    {
        Gate::authorize('user.view');
        $this->authorizeVisible($membership);

        $data = $request->validate([
            'role_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'reports_to_id' => ['sometimes', 'nullable', 'integer'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:100'],
            'employee_code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        // Each kind of change has its own permission.
        if (array_key_exists('role_id', $data)) {
            Gate::authorize('user.change_role');
        }
        if (array_key_exists('reports_to_id', $data)) {
            Gate::authorize('user.change_manager');
        }
        if (array_intersect(['department_id', 'designation', 'employee_code'], array_keys($data))) {
            Gate::authorize('user.edit');
        }

        $members->update($membership, $this->me(), $data);

        return back()->with('success', 'Saved.');
    }

    // --- helpers --------------------------------------------------------------------------------

    /** @return Builder<Membership> */
    private function peopleQuery(string $kind, string $q, ?int $roleId, ?int $departmentId)
    {
        $query = $kind === 'former' ? Membership::former() : Membership::active();

        return $query->visibleTo($this->me())
            ->when($q !== '', fn ($x) => $x->where(fn ($x) => $x
                ->where('employee_code', 'like', "%{$q}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))))
            ->when($roleId, fn ($x) => $x->where('role_id', $roleId))
            ->when($departmentId, fn ($x) => $x->where('department_id', $departmentId));
    }

    /** @return array<string, mixed> */
    private function row(Membership $m): array
    {
        return [
            'id' => $m->id,
            'name' => $m->user->name,
            'email' => $m->user->email,
            'role' => $m->role->name,
            'department' => $m->department?->name,
            'manager' => $m->manager?->user?->name,
            'designation' => $m->designation,
            'employee_code' => $m->employee_code,
            'user_type' => $m->user_type,
            'joined_at' => $m->joined_at?->toDateString(),
            'left_at' => $m->left_at?->toDateString(),
            'exit_type' => $m->exit_type,
            'scheduled_exit_at' => $m->scheduled_exit_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Membership $m): array
    {
        return $this->row($m) + [
            'phone' => $m->user->phone,
            'status' => $m->status,
            'is_former' => $m->deleted_at !== null,
            'role_id' => $m->role_id,
            'department_id' => $m->department_id,
            'reports_to_id' => $m->reports_to_id,
            'exit_reason' => $m->exit_reason,
            'is_self' => $m->id === $this->me()->id,
        ];
    }

    /** People who could be this person's manager: active, internal, outranking them, not below them. */
    private function managerCandidates(Membership $member, int $level): array
    {
        $below = $this->hierarchy->descendantIds($member);

        return Membership::active()->where('user_type', 'internal')
            ->where('id', '!=', $member->id)->whereNotIn('id', $below)
            ->whereHas('role', fn ($q) => $q->where('level', '<', $level))
            ->with(['user:id,name', 'role:id,name'])->get()
            ->sortBy(fn ($m) => $m->user->name)
            ->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name])
            ->values()->all();
    }

    private function authorizeVisible(Membership $membership): void
    {
        abort_unless(
            Membership::withTrashed()->visibleTo($this->me())->whereKey($membership->id)->exists(),
            403,
            'You cannot view this person.'
        );
    }
}
