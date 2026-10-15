<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\AuditLogger;
use App\Support\Tenancy\CurrentOrganization;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Settings > Company: logo, time zone, working days and date format. */
class CompanySettingsController extends Controller
{
    public const DATE_FORMATS = ['d M Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'];

    public const DAYS = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    public function __construct(private CurrentOrganization $current) {}

    public function edit(): Response
    {
        Gate::authorize('company.view_settings');

        $org = $this->current->organization;

        return Inertia::render('Settings/Company', [
            'company' => [
                'name' => $org->name,
                'slug' => $org->slug,
                'logo_url' => $org->logo_path ? Storage::disk('public')->url($org->logo_path) : null,
                'timezone' => $org->timezone,
                'date_format' => $org->date_format,
                'working_days' => $org->working_days ?? [0, 1, 2, 3, 4],
            ],
            'timezones' => DateTimeZone::listIdentifiers(),
            'dateFormats' => collect(self::DATE_FORMATS)->map(fn ($f) => ['value' => $f, 'label' => now()->format($f)]),
            'days' => collect(self::DAYS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'canEdit' => Gate::allows('company.edit_settings'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('company.edit_settings');

        $data = $request->validate([
            'timezone' => ['required', Rule::in(DateTimeZone::listIdentifiers())],
            'date_format' => ['required', Rule::in(self::DATE_FORMATS)],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:0,6', 'distinct'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['boolean'],
        ]);

        /** @var Organization $org */
        $org = Organization::findOrFail($this->current->id());

        if ($request->hasFile('logo')) {
            $this->deleteLogo($org);
            $org->logo_path = $request->file('logo')->store("logos/{$org->id}", 'public');
        } elseif ($request->boolean('remove_logo')) {
            $this->deleteLogo($org);
            $org->logo_path = null;
        }

        $before = $org->only(['timezone', 'date_format', 'working_days', 'logo_path']);

        $org->fill([
            'timezone' => $data['timezone'],
            'date_format' => $data['date_format'],
            'working_days' => collect($data['working_days'])->map(fn ($d) => (int) $d)->sort()->values()->all(),
        ])->save();

        [$old, $new] = AuditLogger::diff($before, $org->only(array_keys($before)));
        if ($new) {
            app(AuditLogger::class)->record('company.settings_updated', $org, $old, $new, $org->name);
        }

        return back()->with('success', 'Company settings saved.');
    }

    private function deleteLogo(Organization $org): void
    {
        if ($org->logo_path) {
            Storage::disk('public')->delete($org->logo_path);
        }
    }
}
