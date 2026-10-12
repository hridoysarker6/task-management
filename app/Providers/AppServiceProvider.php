<?php

namespace App\Providers;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request (and per queued job), so company context never leaks between them.
        $this->app->scoped(CurrentOrganization::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // `@can('task.assign')`, `$user->can(...)` and Gate::authorize(...) resolve against the effective
        // permissions of the user's membership in the current company. A miss returns null so
        // that policies and other gate definitions still get their say.
        Gate::before(function ($user, string $ability) {
            $membership = app(CurrentOrganization::class)->membership;

            if ($user && $membership && $membership->user_id === $user->getAuthIdentifier() && $membership->hasPermission($ability)) {
                return true;
            }

            return null;
        });
    }
}
