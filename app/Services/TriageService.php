<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The triage queue: requests from stakeholders wait here until someone accepts, rejects or asks for more information. */
class TriageService
{
    public function __construct(
        private TaskService $tasks,
        private CommentService $comments,
        private TaskActivityLogger $activity,
    ) {}

    /**
     * Accept the request: set its type, priority and assignee, and move it to the backlog.
     *
     * @param  array{type?:?string,priority?:?int,assignee_id?:?int}  $data
     *
     * @throws ValidationException
     */
    public function accept(Task $task, Membership $actor, array $data = []): Task
    {
        $this->assertInTriage($task);
        $project = Project::findOrFail($task->project_id);

        $accepted = DB::transaction(function () use ($task, $actor, $data, $project) {
            $changes = collect($data)->only(['type', 'priority'])->filter(fn ($v) => $v !== null && $v !== '')->all();
            if ($changes) {
                $this->tasks->update($task, $actor, $changes);
            }

            if (! empty($data['assignee_id'])) {
                $this->tasks->assign($task, Membership::withoutGlobalScope(OrganizationScope::class)->with('role')->findOrFail($data['assignee_id']), $actor);
            }

            $start = $project->statuses()->where('is_initial', true)->first()
                ?? $project->statuses()->where('category', 'todo')->first()
                ?? throw ValidationException::withMessages(['status' => 'This project has no starting status.']);

            $this->tasks->move($task, $start);
            $this->activity->log($task, 'triage_accepted', meta: ['to' => $start->name], actorId: $actor->id);

            return $task;
        });

        app(Notifier::class)->triageDecision($accepted, true);

        return $accepted;
    }

    /** Reject the request with a reason; the task is closed in a cancelled status (Rejected when there is one). */
    public function reject(Task $task, Membership $actor, string $reason): Task
    {
        $this->assertInTriage($task);

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the request is rejected. The person who raised it will see this.']);
        }

        $project = Project::findOrFail($task->project_id);
        $statuses = $project->statuses()->where('category', 'cancelled')->get();
        $to = $statuses->first(fn (ProjectStatus $s) => strtolower($s->name) === 'rejected') ?? $statuses->first()
            ?? throw ValidationException::withMessages(['status' => 'This project has no cancelled status to reject into. Add one in its workflow settings.']);

        $rejected = DB::transaction(function () use ($task, $to, $reason, $actor) {
            $this->tasks->move($task, $to, $reason);
            $this->activity->log($task, 'triage_rejected', null, null, $reason, actorId: $actor->id);

            return $task;
        });

        app(Notifier::class)->triageDecision($rejected, false, $reason);

        return $rejected;
    }

    /** Ask the requester for more details: a public comment, and the task stays in triage. */
    public function askForInfo(Task $task, Membership $actor, string $message): Task
    {
        $this->assertInTriage($task);

        DB::transaction(function () use ($task, $actor, $message) {
            $this->comments->add($task, $actor, $message, internal: false);
            $this->activity->log($task, 'triage_info_requested', actorId: $actor->id);
        });

        return $task;
    }

    /** @throws ValidationException */
    private function assertInTriage(Task $task): void
    {
        if (ProjectStatus::whereKey($task->status_id)->value('category') !== 'triage') {
            throw ValidationException::withMessages(['task' => 'This task is not waiting in triage.']);
        }
    }
}
