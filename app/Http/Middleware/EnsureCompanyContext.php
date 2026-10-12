<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Routes inside a company need an active company; everyone else is sent back to the dashboard router. */
class EnsureCompanyContext
{
    public function __construct(private CurrentOrganization $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->current->check()) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
