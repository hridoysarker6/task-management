<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The queue's failed jobs (mostly emails that could not be sent): see why, try again, or dismiss. */
class FailedJobController extends Controller
{
    public function index(): Response
    {
        $jobs = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(100)->get()->map(function ($j) {
            $payload = json_decode($j->payload, true) ?: [];

            return [
                'uuid' => $j->uuid,
                'name' => $payload['displayName'] ?? 'Job',
                'queue' => $j->queue,
                'error' => strtok($j->exception, "\n") ?: $j->exception,
                'failed_at' => $j->failed_at,
            ];
        });

        return Inertia::render('Admin/FailedJobs', [
            'jobs' => $jobs,
            'total' => DB::table('failed_jobs')->count(),
            'pending' => DB::table('jobs')->count(),
        ]);
    }

    public function retry(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'The job is back in the queue.');
    }

    public function destroy(string $uuid): RedirectResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->delete() > 0, 404);

        return back()->with('success', 'Dismissed.');
    }
}
