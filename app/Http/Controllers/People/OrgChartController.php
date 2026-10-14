<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OrgChartController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('orgchart.view');

        $people = Membership::active()->where('user_type', 'internal')
            ->with(['user:id,name', 'role:id,name,level', 'department:id,name'])->get();

        $ids = $people->pluck('id')->flip();
        $byManager = $people->groupBy(fn (Membership $m) => $m->reports_to_id !== null && $ids->has($m->reports_to_id) ? $m->reports_to_id : 0);

        $build = function (Membership $m) use (&$build, $byManager): array {
            return [
                'id' => $m->id,
                'name' => $m->user->name,
                'role' => $m->role->name,
                'designation' => $m->designation,
                'department' => $m->department?->name,
                // Filled in once tasks exist (phase 5); the chart shows the badge only when this is a number.
                'open_tasks' => null,
                'children' => ($byManager[$m->id] ?? collect())->sortBy('role.level')->map($build)->values()->all(),
            ];
        };

        return Inertia::render('People/OrgChart', [
            'tree' => ($byManager[0] ?? collect())->sortBy('role.level')->map($build)->values(),
            'total' => $people->count(),
            'canViewProfiles' => Gate::allows('user.view'),
        ]);
    }
}
