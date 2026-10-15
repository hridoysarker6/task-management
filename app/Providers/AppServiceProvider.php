<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\Comment;
use App\Policies\TaskPolicy;
use App\Services\NotificationPreferenceService;
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
        // Preferences are read once per request or job, so a long-running queue worker never serves stale ones.
        $this->app->scoped(NotificationPreferenceService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Comments and attachments are authorised by the task policy (who wrote it, who may delete any).
        Gate::policy(Comment::class, TaskPolicy::class);
        Gate::policy(Attachment::class, TaskPolicy::class);

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
