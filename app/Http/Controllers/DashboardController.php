<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Settings\OnboardingController;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/** Sends each kind of signed-in person to the right home page. */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentOrganization $current)
    {
        $user = $request->user();

        if ($user->is_system_admin) {
            return redirect()->route('admin.dashboard');
        }

        if (! $current->check() && $user->openApplication()) {
            return redirect()->route('application.show');
        }

        $onboarding = null;

        if ($current->check() && Gate::allows('company.edit_settings')) {
            $onboarding = OnboardingController::state($current->organization)['status'];

            // The first time the Company Admin lands, start the setup wizard (every step is skippable).
            if ($onboarding === null) {
                OnboardingController::begin($current->organization);

                return redirect()->route('onboarding.show');
            }
        }

        return Inertia::render('Dashboard', ['onboarding' => $onboarding]);
    }
}
