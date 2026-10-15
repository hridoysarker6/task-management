<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Services\NotificationPreferenceService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Per kind of event: bell on or off, email on or off. */
class PreferencesController extends Controller
{
    public function __construct(private CurrentOrganization $current, private NotificationPreferenceService $preferences) {}

    public function show(): Response
    {
        abort_if($this->current->membership === null, 403);

        return Inertia::render('Notifications/Preferences', ['preferences' => $this->preferences->all($this->current->membership)]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_if($this->current->membership === null, 403);

        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*.app' => ['required', 'boolean'],
            'preferences.*.mail' => ['required', 'boolean'],
        ]);

        $this->preferences->save($this->current->membership, $data['preferences']);

        return back()->with('success', 'Notification settings saved.');
    }
}
