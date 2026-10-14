<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Works out which company the signed-in user is acting in (session, then last used, then first available)
 * and publishes it through CurrentOrganization so every scoped query and permission check can use it.
 */
class SetCurrentOrganization
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->current->clear();

        $user = $request->user();

        if (! $user || $user->is_system_admin) {
            return $next($request);
        }

        $memberships = $user->accessibleMemberships();

        if ($memberships->isEmpty()) {
            // Every company they belong to is suspended: no access at all.
            if ($user->onlyInSuspendedCompanies()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('error', 'Your company\'s account is suspended. Please contact the platform administrator.');
            }

            // They were removed from every company: say so instead of showing an empty workspace.
            if (! $user->openApplication() && $message = $user->exitMessage()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('error', $message);
            }

            return $next($request);
        }

        $wanted = $request->session()->get('current_organization_id') ?? $user->last_organization_id;
        $membership = $memberships->firstWhere('organization_id', $wanted) ?? $memberships->first();

        $this->current->set($membership->organization, $membership);

        $request->session()->put('current_organization_id', $membership->organization_id);

        if ($user->last_organization_id !== $membership->organization_id) {
            $user->forceFill(['last_organization_id' => $membership->organization_id])->saveQuietly();
        }

        return $next($request);
    }
}
