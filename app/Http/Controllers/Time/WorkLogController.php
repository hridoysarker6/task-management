<?php

namespace App\Http\Controllers\Time;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\WorkLog;
use App\Services\WorkLogService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Logging time on a task, and changing or removing an entry. */
class WorkLogController extends Controller
{
    public function __construct(private CurrentOrganization $current, private WorkLogService $logs) {}

    public function store(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('view', $task);
        Gate::authorize('worklog.create');

        $this->logs->create($task, $this->current->membership, $this->validated($request));

        return back()->with('success', 'Time logged.');
    }

    public function update(Request $request, WorkLog $worklog): RedirectResponse
    {
        $this->assertVisible($worklog);

        $this->logs->update($worklog, $this->current->membership, $this->validated($request));

        return back()->with('success', 'Entry saved.');
    }

    public function destroy(WorkLog $worklog): RedirectResponse
    {
        $this->assertVisible($worklog);

        $this->logs->delete($worklog, $this->current->membership);

        return back()->with('success', 'Entry removed.');
    }

    /** The person must still be able to see the task the entry belongs to. */
    private function assertVisible(WorkLog $worklog): void
    {
        $task = Task::withTrashed()->findOrFail($worklog->task_id);
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('view', $task);
    }

    /** @return array{work_date:string,minutes:int,note:?string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'minutes' => ['required', 'integer', 'min:1', 'max:'.WorkLogService::MAX_DAY_MIN],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
