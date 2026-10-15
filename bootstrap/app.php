<?php

use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\EnsureSystemAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetCurrentOrganization::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Must run before route-model binding, so {role}, {project}... are resolved inside the current company.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: SetCurrentOrganization::class,
        );

        $middleware->alias([
            'system.admin' => EnsureSystemAdmin::class,
            'company' => EnsureCompanyContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Friendly pages for the errors people actually meet. While debugging, a real server error still shows its trace.
        $exceptions->respond(function (SymfonyResponse $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            // Plain JSON callers (axios, the bell, search) keep their JSON; only page visits get a page.
            $jsonCaller = $request->expectsJson() && ! $request->header('X-Inertia');

            if ($jsonCaller || ! in_array($status, [403, 404, 419, 500, 503], true) || ($status >= 500 && config('app.debug'))) {
                return $response;
            }

            return Inertia::render('Error', ['status' => $status])->toResponse($request)->setStatusCode($status);
        });
    })->create();
