<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Sprint;
use App\Services\ReportService;
use App\Support\ReportExporter;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Reports: sprint, workload, overdue and time, with filters, export and a print view. */
class ReportController extends Controller
{
    public function __construct(private CurrentOrganization $current, private ReportService $reports) {}

    public function index(Request $request, string $report = 'sprint'): Response
    {
        $filters = $this->prepare($request, $report);
        $me = $this->current->membership;

        $projects = Project::visibleTo($me)->orderBy('name')->get(['id', 'key', 'name']);

        return Inertia::render('Reports/Index', [
            'report' => $report,
            'tabs' => collect(ReportService::TITLES)->map(fn ($title, $key) => ['key' => $key, 'label' => $title])->values(),
            'data' => $this->reports->build($report, $me, $filters),
            'filters' => $filters,
            'options' => [
                'projects' => $projects,
                'sprints' => isset($filters['project_id']) ? Sprint::where('project_id', $filters['project_id'])->orderByDesc('start_date')->get(['id', 'name', 'status']) : [],
                'people' => Membership::active()->visibleTo($me)->where('user_type', 'internal')->with('user:id,name')->get()
                    ->map(fn (Membership $m) => ['id' => $m->id, 'name' => $m->user->name])->sortBy('name')->values(),
            ],
            'canExport' => $me->hasPermission('report.export'),
        ]);
    }

    /** Download the report (csv or xlsx), or show it as a clean page to print or save as PDF. */
    public function export(Request $request, string $report): mixed
    {
        Gate::authorize('report.export');

        $filters = $this->prepare($request, $report);
        $data = $this->reports->build($report, $this->current->membership, $filters);
        $name = Str::slug($report.'-report-'.today()->toDateString());

        return match ($request->query('format', 'csv')) {
            'xlsx' => ReportExporter::xlsx($data, $name),
            'print' => Inertia::render('Reports/Print', ['data' => $data, 'filters' => $filters, 'generated' => now()->toDayDateTimeString(), 'company' => $this->current->organization->name]),
            default => ReportExporter::csv($data, $name),
        };
    }

    /** @return array<string, mixed> */
    private function prepare(Request $request, string $report): array
    {
        abort_unless(in_array($report, ReportService::REPORTS, true), 404);
        Gate::authorize('report.view');

        return $this->reports->clean($request->query());
    }
}
