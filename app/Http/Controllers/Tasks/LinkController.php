<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskLink;
use App\Services\TaskLinkService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LinkController extends Controller
{
    public function __construct(private CurrentOrganization $current, private TaskLinkService $links) {}

    public function store(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->deleted_at !== null, 404);
        Gate::authorize('link', $task);

        $data = $request->validate([
            'type' => ['required', Rule::in(Task::LINK_TYPES)],
            'target_id' => ['required', 'integer'],
        ]);

        // The other task has to be one they can see; otherwise it simply does not exist for them.
        $target = Task::visibleTo($this->current->membership)->findOrFail($data['target_id']);

        $this->links->link($task, $target, $data['type'], $this->current->membership);

        return back()->with('success', 'Linked.');
    }

    public function destroy(TaskLink $link): RedirectResponse
    {
        $me = $this->current->membership;
        $source = Task::findOrFail($link->source_task_id);
        $target = Task::findOrFail($link->target_task_id);

        // Either end may remove it, provided they can see and link that end.
        abort_unless(Gate::allows('link', $source) || Gate::allows('link', $target), 403);
        abort_unless(Task::visibleTo($me)->whereKey([$source->id, $target->id])->exists(), 404);

        $this->links->unlink($link, $me);

        return back()->with('success', 'Link removed.');
    }
}
