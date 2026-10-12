<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationSwitchController extends Controller
{
    public function __invoke(Request $request, Organization $organization): RedirectResponse
    {
        $allowed = $request->user()->accessibleMemberships()->contains('organization_id', $organization->id);

        abort_unless($allowed, 403);

        $request->session()->put('current_organization_id', $organization->id);
        $request->user()->forceFill(['last_organization_id' => $organization->id])->save();

        return redirect()->route('dashboard');
    }
}
