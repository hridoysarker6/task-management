<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ResubmitApplicationRequest;
use App\Models\Organization;
use App\Services\CompanyRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The applicant's own view of their company registration: status page, edit and resubmit. */
class ApplicationController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $organization = $request->user()->openApplication();

        if (! $organization) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Company/Awaiting', [
            'organization' => $this->summary($organization),
            'note' => $organization->latestReview('rejected', 'info_requested')?->note,
        ]);
    }

    public function edit(Request $request): Response|RedirectResponse
    {
        $organization = $request->user()->openApplication();

        if (! $organization?->isEditableByApplicant()) {
            return redirect()->route('application.show');
        }

        return Inertia::render('Company/EditApplication', [
            'organization' => $organization->only(['name', 'slug', 'industry', 'size', 'address', 'phone', 'email', 'website', 'registration_no', 'status']),
            'documents' => $organization->documents()->get(['id', 'type', 'original_name', 'size']),
            'note' => $organization->latestReview('rejected', 'info_requested')?->note,
            'sizes' => ResubmitApplicationRequest::SIZES,
            'maxFileMb' => config('platform.documents.max_kb') / 1024,
            'maxFiles' => config('platform.documents.max_files'),
        ]);
    }

    public function update(ResubmitApplicationRequest $request, CompanyRegistrationService $registration): RedirectResponse
    {
        $registration->resubmit(
            $request->user()->openApplication(),
            $request->company(),
            $request->validated('note'),
            $request->file('trade_license'),
            $request->file('other_documents', []),
        );

        return redirect()->route('application.show')->with('success', 'Application resubmitted. We will review it shortly.');
    }

    /** @return array<string, mixed> */
    private function summary(Organization $organization): array
    {
        return [
            'name' => $organization->name,
            'status' => $organization->status,
            'statusLabel' => $organization->statusLabel(),
            'canEdit' => $organization->isEditableByApplicant(),
        ];
    }
}
