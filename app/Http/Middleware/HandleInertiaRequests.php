<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $current = app(CurrentOrganization::class);

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Effective permissions in the current company; the Vue `can()` helper and `v-can` read these.
                'permissions' => fn () => $current->membership?->permissionNames() ?? [],
                'role' => fn () => $current->membership?->role?->name,
            ],
            'currentOrganization' => fn () => $current->organization?->only(['id', 'name', 'slug']),
            // For the company switcher (only users in more than one company see it).
            'organizations' => fn () => $request->user() && ! $request->user()->is_system_admin
                ? $request->user()->accessibleMemberships()
                    ->map(fn ($m) => ['id' => $m->organization->id, 'name' => $m->organization->name])
                    ->values()
                : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
            ],
        ];
    }
}
