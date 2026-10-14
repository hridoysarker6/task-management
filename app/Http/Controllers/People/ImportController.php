<?php

namespace App\Http\Controllers\People;

use App\Http\Controllers\Controller;
use App\Services\UserImportService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bulk invite from a CSV: upload, preview with validation errors, confirm, download the error report. */
class ImportController extends Controller
{
    private const TTL_MINUTES = 60;

    public function __construct(private UserImportService $import, private CurrentOrganization $current) {}

    public function create(): Response
    {
        Gate::authorize('user.import');

        return Inertia::render('People/Import', ['stage' => 'upload', 'columns' => UserImportService::COLUMNS, 'maxRows' => UserImportService::MAX_ROWS]);
    }

    public function template(): StreamedResponse
    {
        Gate::authorize('user.import');

        return response()->streamDownload(fn () => print $this->import->template(), 'people-import-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function preview(Request $request): RedirectResponse
    {
        Gate::authorize('user.import');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $rows = $this->import->parse($request->file('file')->getRealPath());
        $token = (string) Str::uuid();
        Cache::put($this->key($token), ['rows' => $rows, 'result' => null], now()->addMinutes(self::TTL_MINUTES));

        return redirect()->route('people.import.show', $token);
    }

    public function show(string $token): Response|RedirectResponse
    {
        Gate::authorize('user.import');

        $state = Cache::get($this->key($token));
        if (! $state) {
            return redirect()->route('people.import.create')->with('error', 'That import has expired. Upload the file again.');
        }

        if ($state['result']) {
            return Inertia::render('People/Import', [
                'stage' => 'result',
                'token' => $token,
                'created' => $state['result']['created'],
                'failed' => $state['result']['failed'],
            ]);
        }

        $rows = $this->import->validate($this->current->organization, $this->current->membership, $state['rows']);
        $valid = collect($rows)->filter(fn ($r) => ! $r['errors'])->count();

        return Inertia::render('People/Import', [
            'stage' => 'preview',
            'token' => $token,
            'rows' => $rows,
            'validCount' => $valid,
            'invalidCount' => count($rows) - $valid,
        ]);
    }

    public function confirm(string $token): RedirectResponse
    {
        Gate::authorize('user.import');

        $state = Cache::get($this->key($token));
        abort_unless($state && ! $state['result'], 404);

        $result = $this->import->import($this->current->organization, $this->current->membership, $state['rows']);

        Cache::put($this->key($token), ['rows' => [], 'result' => $result], now()->addMinutes(self::TTL_MINUTES));

        return redirect()->route('people.import.show', $token);
    }

    public function errors(string $token): StreamedResponse
    {
        Gate::authorize('user.import');

        $state = Cache::get($this->key($token));
        abort_unless($state, 404);

        $failed = $state['result']['failed']
            ?? collect($this->import->validate($this->current->organization, $this->current->membership, $state['rows']))
                ->filter(fn ($r) => $r['errors'] !== [])->values()->all();

        return response()->streamDownload(fn () => print $this->import->errorReport($failed), 'people-import-errors.csv', ['Content-Type' => 'text/csv']);
    }

    private function key(string $token): string
    {
        return "people-import:{$this->current->id()}:{$token}";
    }
}
