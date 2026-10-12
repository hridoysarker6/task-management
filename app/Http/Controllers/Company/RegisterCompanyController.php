<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\RegisterCompanyRequest;
use App\Services\CompanyRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterCompanyController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Company/Register', [
            'sizes' => RegisterCompanyRequest::SIZES,
            'maxFileMb' => config('platform.documents.max_kb') / 1024,
            'maxFiles' => config('platform.documents.max_files'),
        ]);
    }

    public function store(RegisterCompanyRequest $request, CompanyRegistrationService $registration): RedirectResponse
    {
        $organization = $registration->register(
            $request->company(),
            $request->contact(),
            $request->file('trade_license'),
            $request->file('other_documents', []),
        );

        Auth::login($organization->applicant);

        return redirect()->route('verification.notice');
    }
}
