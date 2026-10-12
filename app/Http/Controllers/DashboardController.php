<?php

namespace App\Http\Controllers;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
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

        return Inertia::render('Dashboard');
    }
}
