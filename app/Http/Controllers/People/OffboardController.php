<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Services\HierarchyService;
use App\Services\OffboardingService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class OffboardController extends Controller
{
    public function __construct(
        private OffboardingService $offboarding,
        private HierarchyService $hierarchy,
        private CurrentOrganization $current,
    ) {}

    public function create(Membership $membership): Response|RedirectResponse
    {
        Gate::authorize('user.offboard');

        if ($membership->status !== Membership::ACTIVE) {
            return redirect()->route('people.show', $membership);
        }

        $membership->load(['user', 'role']);
        $plan = $this->offboarding->handoverPlan($membership);
        $reports = $plan['direct_reports'];

        // Candidates for the direct reports' new manager: must outrank every one of them, and not be below the leaver.
        $highestReportLevel = $reports->min(fn ($r) => $r->role->level);
        $below = $this->hierarchy->descendantIds($membership);

        $candidates = $reports->isEmpty() ? collect() : Membership::active()
            ->where('user_type', 'internal')->where('id', '!=', $membership->id)->whereNotIn('id', $below)
            ->whereHas('role', fn ($q) => $q->where('level', '<', $highestReportLevel))
            ->with(['user:id,name', 'role:id,name'])->get()
            ->sortBy(fn ($m) => $m->user->name);

        return Inertia::render('People/Offboard', [
            'person' => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'role' => $membership->role->name,
                'joined_at' => $membership->joined_at?->toDateString(),
                'scheduled_exit_at' => $membership->scheduled_exit_at?->toDateString(),
                'exit_type' => $membership->exit_type,
                'default_manager_id' => $membership->reports_to_id,
            ],
            'directReports' => $reports->map(fn ($r) => ['id' => $r->id, 'name' => $r->user->name, 'role' => $r->role->name]),
            'managers' => $candidates->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name])->values(),
            'projects' => $plan['projects']->map(fn ($p) => ['id' => $p->id, 'key' => $p->key, 'name' => $p->name]),
            'projectManagers' => $plan['projects']->isEmpty() ? [] : Membership::active()->where('user_type', 'internal')->where('id', '!=', $membership->id)
                ->with(['user:id,name', 'role:id,name'])->get()->sortBy(fn ($m) => $m->user->name)
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->user->name, 'role' => $m->role->name])->values(),
            'exitTypes' => collect(OffboardingService::EXIT_TYPES)->map(fn ($t) => ['value' => $t, 'label' => ucfirst(str_replace('_', ' ', $t))]),
            'today' => today()->toDateString(),
        ]);
    }

    public function store(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('user.offboard');

        $data = $request->validate([
            'exit_type' => ['required', Rule::in(OffboardingService::EXIT_TYPES)],
            'left_at' => ['required', 'date'],
            'exit_reason' => ['nullable', 'string', 'max:2000'],
            'new_manager_id' => ['nullable', 'integer'],
            'new_project_manager_id' => ['nullable', 'integer'],
        ]);

        $this->offboarding->offboard($membership, $this->current->membership, $data);

        $immediate = Carbon::parse($data['left_at'])->lte(today());

        return redirect()->route('people.index', ['tab' => $immediate ? 'former' : 'active'])->with(
            'success',
            $immediate ? "{$membership->user->name} has left the company." : "Exit scheduled for {$data['left_at']}. They stay active until then."
        );
    }

    public function cancel(Membership $membership): RedirectResponse
    {
        Gate::authorize('user.offboard');

        $this->offboarding->cancelScheduledExit($membership);

        return back()->with('success', 'Scheduled exit cancelled.');
    }
}
