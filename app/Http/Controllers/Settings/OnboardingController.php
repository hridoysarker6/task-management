<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Company Admin's setup wizard (WORKFLOW section 2). Every step can be skipped and finished later.
 * Progress lives in organizations.settings['onboarding'] = ['status' => in_progress|done, 'steps' => [key => done|skipped]].
 */
class OnboardingController extends Controller
{
    public const STEPS = ['profile', 'departments', 'roles', 'invite', 'hierarchy', 'project'];

    public function __construct(private CurrentOrganization $current) {}

    public function show(): Response
    {
        Gate::authorize('company.edit_settings');

        $state = self::state($this->current->organization);

        $steps = [
            ['key' => 'profile', 'title' => 'Company profile', 'text' => 'Add your logo, time zone, working days and date format.', 'href' => route('settings.company.edit'), 'auto' => (bool) $this->current->organization->logo_path],
            ['key' => 'departments', 'title' => 'Departments', 'text' => 'Create departments such as Software, QA or Infrastructure. Assign each head later.', 'href' => route('departments.index'), 'auto' => Department::exists()],
            ['key' => 'roles', 'title' => 'Roles & permissions', 'text' => 'Review the default permission matrix and adjust it to how you work.', 'href' => route('roles.index'), 'auto' => false],
            ['key' => 'invite', 'title' => 'Invite people', 'text' => 'Invite your team one at a time, or import a CSV file.', 'href' => route('people.invite'), 'auto' => Invitation::exists() || Membership::active()->count() > 1],
            ['key' => 'hierarchy', 'title' => 'Build the hierarchy', 'text' => 'Set who reports to whom, and check the result in the org chart.', 'href' => route('orgchart'), 'auto' => Membership::active()->whereNotNull('reports_to_id')->exists()],
            ['key' => 'project', 'title' => 'First project', 'text' => 'Create a project and add members. Projects arrive in the next release.', 'href' => null, 'auto' => false],
        ];

        return Inertia::render('Settings/Onboarding', [
            'steps' => collect($steps)->map(fn ($s) => $s + ['status' => $state['steps'][$s['key']] ?? ($s['auto'] ? 'done' : 'todo')])->all(),
            'status' => $state['status'],
        ]);
    }

    /** Mark one step done or skipped, or finish the whole wizard. */
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('company.edit_settings');

        $data = $request->validate([
            'step' => ['nullable', Rule::in(self::STEPS)],
            'status' => ['nullable', Rule::in(['done', 'skipped', 'todo'])],
            'finish' => ['boolean'],
        ]);

        $org = Organization::findOrFail($this->current->id());
        $state = self::state($org);

        if (! empty($data['step']) && ! empty($data['status'])) {
            $state['steps'][$data['step']] = $data['status'];
        }
        if ($request->boolean('finish')) {
            $state['status'] = 'done';
        }

        self::save($org, $state);

        return $request->boolean('finish')
            ? redirect()->route('dashboard')->with('success', 'Setup finished. You can change everything later in Settings.')
            : back();
    }

    /** @return array{status:?string,steps:array<string,string>} */
    public static function state(Organization $org): array
    {
        $state = $org->settings['onboarding'] ?? [];

        return ['status' => $state['status'] ?? null, 'steps' => $state['steps'] ?? []];
    }

    /** Called by the dashboard the first time the Company Admin lands: starts the wizard. */
    public static function begin(Organization $org): void
    {
        $state = self::state($org);
        $state['status'] = 'in_progress';
        self::save($org, $state);
    }

    private static function save(Organization $org, array $state): void
    {
        $settings = $org->settings ?? [];
        $settings['onboarding'] = $state;
        $org->forceFill(['settings' => $settings])->save();
    }
}
