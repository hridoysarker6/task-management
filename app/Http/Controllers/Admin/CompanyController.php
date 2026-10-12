<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Services\CompanyReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyController extends Controller
{
    public function __construct(private CompanyReviewService $reviews) {}

    public function index(Request $request): Response
    {
        $status = $request->query('status', Organization::PENDING);
        abort_unless(in_array($status, Organization::STATUSES, true), 404);

        $search = trim((string) $request->query('q', ''));

        $companies = Organization::query()
            ->with('applicant:id,name,email,email_verified_at')
            ->where('status', $status)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhereHas('applicant', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Organization $o) => [
                'id' => $o->id,
                'name' => $o->name,
                'slug' => $o->slug,
                'industry' => $o->industry,
                'applicant' => $o->applicant?->only(['name', 'email']),
                'email_verified' => (bool) $o->applicant?->email_verified_at,
                'created_at' => $o->created_at->toIso8601String(),
            ]);

        $counts = Organization::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Admin/Companies/Index', [
            'companies' => $companies,
            'status' => $status,
            'search' => $search,
            'tabs' => collect(Organization::STATUSES)->map(fn ($s) => ['status' => $s, 'count' => (int) ($counts[$s] ?? 0)])->values(),
        ]);
    }

    public function show(Organization $organization): Response
    {
        $organization->load(['applicant:id,name,email,phone,email_verified_at', 'documents', 'reviews.actor:id,name', 'approver:id,name']);

        return Inertia::render('Admin/Companies/Show', [
            'company' => [
                ...$organization->only([
                    'id', 'name', 'slug', 'industry', 'size', 'address', 'phone', 'email', 'website',
                    'registration_no', 'status', 'user_limit',
                ]),
                'status_label' => $organization->statusLabel(),
                'contact_designation' => $organization->settings['contact_designation'] ?? null,
                'created_at' => $organization->created_at->toIso8601String(),
                'approved_at' => $organization->approved_at?->toIso8601String(),
                'approved_by' => $organization->approver?->name,
                'suspended_at' => $organization->suspended_at?->toIso8601String(),
                'member_count' => $organization->memberships()->withoutGlobalScopes()->where('status', 'active')->count(),
            ],
            'applicant' => $organization->applicant?->only(['name', 'email', 'phone']) + [
                'email_verified' => (bool) $organization->applicant?->email_verified_at,
            ],
            'documents' => $organization->documents->map(fn (OrganizationDocument $d) => [
                'id' => $d->id,
                'type' => $d->type,
                'original_name' => $d->original_name,
                'size' => $d->size,
                'url' => route('admin.companies.documents.download', [$organization, $d]),
            ]),
            'reviews' => $organization->reviews->map(fn ($r) => [
                'id' => $r->id,
                'action' => $r->action,
                'note' => $r->note,
                'actor' => $r->actor?->name,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function approve(Request $request, Organization $organization): RedirectResponse
    {
        $this->reviews->approve($organization, $request->user());

        return back()->with('success', "{$organization->name} approved. The contact person is now its Company Admin.");
    }

    public function reject(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        $this->reviews->reject($organization, $request->user(), $data['reason']);

        return back()->with('success', 'Application rejected. The applicant has been emailed.');
    }

    public function requestInfo(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:2000']]);

        $this->reviews->requestInfo($organization, $request->user(), $data['note']);

        return back()->with('success', 'More information requested. The applicant has been emailed.');
    }

    public function suspend(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->reviews->suspend($organization, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Company suspended. Nobody in it can sign in until it is reactivated.');
    }

    public function reactivate(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->reviews->reactivate($organization, $request->user(), $data['note'] ?? null);

        return back()->with('success', 'Company reactivated.');
    }

    public function updateUserLimit(Request $request, Organization $organization): RedirectResponse
    {
        $data = $request->validate(['user_limit' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        $this->reviews->setUserLimit($organization, $data['user_limit'] ?? null);

        return back()->with('success', 'User limit updated.');
    }

    public function downloadDocument(Organization $organization, OrganizationDocument $document): StreamedResponse
    {
        abort_unless($document->organization_id === $organization->id, 404);

        return Storage::download($document->path, $document->original_name);
    }
}
