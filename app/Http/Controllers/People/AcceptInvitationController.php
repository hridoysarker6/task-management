<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Public side of invitations: the page the emailed link opens, and accepting it. */
class AcceptInvitationController extends Controller
{
    public function __construct(private InvitationService $invitations) {}

    public function show(Request $request, string $token): Response
    {
        $invitation = $this->invitations->findByToken($token);

        if (! $invitation) {
            return Inertia::render('Invitations/Accept', ['state' => 'invalid']);
        }

        $organization = Organization::find($invitation->organization_id);
        $existing = User::where('email', $invitation->email)->exists();
        $me = $request->user();

        // After signing in, come straight back here.
        if ($existing && ! $me) {
            $request->session()->put('url.intended', route('invitations.show', $token));
        }

        return Inertia::render('Invitations/Accept', [
            'state' => $invitation->isUsable() && $organization?->isActive() ? 'usable' : ($invitation->state() === 'pending' ? 'expired' : $invitation->state()),
            'token' => $token,
            'invitation' => [
                'email' => $invitation->email,
                'name' => $invitation->name,
                'role' => $invitation->role->name,
                'company' => $organization?->name,
            ],
            'accountExists' => $existing,
            'signedInAs' => $me?->email,
            'emailMatches' => $me && strtolower($me->email) === $invitation->email,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token);
        if (! $invitation) {
            abort(404);
        }

        $existing = User::where('email', $invitation->email)->first();

        if ($existing) {
            // Someone with an account must prove it is theirs by being signed in as them.
            abort_unless($request->user()?->is($existing), 403, 'Sign in with the invited email address first.');
            $membership = $this->invitations->accept($invitation, $existing);
        } else {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:150'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]);
            $membership = $this->invitations->accept($invitation, null, $data);

            Auth::login($membership->user);
            $request->session()->regenerate();
        }

        $request->session()->put('current_organization_id', $membership->organization_id);

        return redirect()->route('dashboard')->with('success', 'Welcome aboard!');
    }
}
