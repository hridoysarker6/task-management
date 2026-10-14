<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $membership = app(CurrentOrganization::class)->membership;

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // Join / exit history in the current company (a rehire adds a new period).
            'employment' => $membership ? [
                'company' => $membership->organization->name,
                'role' => $membership->role->name,
                'manager' => $membership->manager?->user?->name,
                'periods' => $membership->employmentPeriods()->with(['role', 'department', 'manager.user'])->get()->map(fn ($p) => [
                    'id' => $p->id,
                    'joined_at' => $p->joined_at->toDateString(),
                    'left_at' => $p->left_at?->toDateString(),
                    'role' => $p->role?->name,
                    'department' => $p->department?->name,
                    'manager' => $p->manager?->user?->name,
                    'designation' => $p->designation,
                    'exit_type' => $p->exit_type,
                ]),
            ] : null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
