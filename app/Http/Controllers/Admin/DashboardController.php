<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $counts = Organization::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('Admin/Dashboard', [
            'counts' => collect(Organization::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
            // Applications are in the queue only after the applicant verified their email.
            'queue' => Organization::with('applicant:id,name,email')
                ->where('status', Organization::PENDING)
                ->whereHas('applicant', fn ($q) => $q->whereNotNull('email_verified_at'))
                ->oldest()
                ->limit(8)
                ->get(['id', 'name', 'slug', 'applicant_user_id', 'created_at']),
        ]);
    }
}
