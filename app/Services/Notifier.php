<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Membership;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Scopes\OrganizationScope;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\Timesheet;
use App\Notifications\AppNotification;
use App\Notifications\SprintCompleted;
use App\Notifications\SprintStarted;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskClosed;
use App\Notifications\TaskCommented;
use App\Notifications\TaskDueTomorrow;
use App\Notifications\TaskInTriage;
use App\Notifications\TaskMentioned;
use App\Notifications\TaskOverdue;
use App\Notifications\TaskStatusChanged;
use App\Notifications\TimesheetReturned;
use App\Notifications\TimesheetSubmitted;
use App\Notifications\TriageDecision;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Works out who should hear about something and sends it (WORKFLOW.md section 10). The person who did the thing is
 * never told about their own action, only people still employed are told, and a problem sending never breaks the
 * action that caused it. Whether a person gets a bell, an email or nothing is decided by their own preferences.
 */
class Notifier
{
    public function __construct(private CurrentOrganization $current) {}

    // --- tasks -----------------------------------------------------------------------------------

    /** A task was created: requests go to the people who triage, and an assignee hears they have it. */
    public function taskCreated(Task $task): void
    {
        $task->loadMissing('project', 'status');
        $actor = $this->actor();

        if ($task->status->category === 'triage') {
            $this->deliver($this->triagers($task->project), new TaskInTriage($task, $actor?->user?->name));
        }

        if ($task->assignee_id && $task->status->category !== 'triage') {
            $this->deliver($this->members([$task->assignee_id]), new TaskAssigned($task, $actor?->user?->name));
        }
    }

    /** Told to whoever now has the task, and to whoever just lost it. */
    public function taskAssigned(Task $task, ?Membership $old, ?Membership $new): void
    {
        $actor = $this->actor();

        if ($new) {
            $this->deliver($this->members([$new->id]), new TaskAssigned($task, $actor?->user?->name));
        }
        if ($old && $old->id !== $new?->id) {
            $this->deliver($this->members([$old->id]), new TaskAssigned($task, $actor?->user?->name, 'unassigned', $new?->user?->name));
        }
    }

    /** A status change: "closed / reopened" when it crosses Done, otherwise a plain status change. */
    public function statusChanged(Task $task, ProjectStatus $from, ProjectStatus $to): void
    {
        $actor = $this->actor();
        $name = $actor?->user?->name;
        $closing = $to->category === 'done';
        $reopening = $from->category === 'done' && ! $closing;

        $notification = $closing || $reopening
            ? new TaskClosed($task, $closing, $name)
            : new TaskStatusChanged($task, $from->name, $to->name, $name);

        $this->deliver($this->followers($task), $notification);
    }

    public function triageDecision(Task $task, bool $accepted, ?string $reason = null): void
    {
        $this->deliver($this->members([$task->reporter_id]), new TriageDecision($task, $accepted, $reason));
    }

    /** Watchers hear about a comment; anyone mentioned in it hears about the mention instead. */
    public function commented(Task $task, Comment $comment, Membership $author): void
    {
        $excerpt = $this->excerpt($comment->body);
        $mentioned = $comment->mentions()->pluck('memberships.id');

        $watchers = $this->members($task->watchers()->pluck('memberships.id'))
            ->reject(fn (Membership $m) => $mentioned->contains($m->id))
            // An internal comment never reaches clients.
            ->reject(fn (Membership $m) => $comment->is_internal && $m->isExternal());

        $this->deliver($watchers, new TaskCommented($task, $excerpt, $author->user->name));
        $this->deliver($this->members($mentioned), new TaskMentioned($task, $excerpt, $author->user->name));
    }

    // --- reminders (the daily scheduler) -----------------------------------------------------------

    /** @return int how many people were told */
    public function dueTomorrow(Task $task): int
    {
        return $task->assignee_id ? $this->remind($this->members([$task->assignee_id]), new TaskDueTomorrow($task)) : 0;
    }

    /** The assignee, and their direct manager. @return int how many people were told */
    public function overdue(Task $task): int
    {
        if (! $task->assignee_id) {
            return 0;
        }

        $assignee = Membership::withoutGlobalScope(OrganizationScope::class)->with('user:id,name')->find($task->assignee_id);
        $told = $this->remind($this->members([$task->assignee_id]), new TaskOverdue($task));

        if ($assignee?->reports_to_id) {
            $told += $this->remind($this->members([$assignee->reports_to_id]), new TaskOverdue($task, $assignee->user->name, true));
        }

        return $told;
    }

    // --- sprints and timesheets ------------------------------------------------------------------

    public function sprintStarted(Sprint $sprint): void
    {
        $this->deliver($this->projectMembers($sprint->project), new SprintStarted($sprint));
    }

    /** @param array{completed:int,carried:int,dropped:int,points:float} $summary */
    public function sprintCompleted(Sprint $sprint, array $summary): void
    {
        $this->deliver($this->projectMembers($sprint->project), new SprintCompleted($sprint, $summary));
    }

    /** The people above the owner who may approve (and anyone whose data scope is the whole company). */
    public function timesheetSubmitted(Timesheet $sheet): void
    {
        $sheet->loadMissing('owner.user');
        $above = DB::table('membership_hierarchy')->where('descendant_id', $sheet->membership_id)->where('depth', '>', 0)->pluck('ancestor_id');

        $approvers = Membership::withoutGlobalScope(OrganizationScope::class)->with(['user', 'role'])
            ->where('organization_id', $sheet->organization_id)->where('status', Membership::ACTIVE)
            ->where(fn ($q) => $q->whereIn('id', $above)->orWhereHas('role', fn ($r) => $r->where('data_scope', 'organization')))
            ->get()->filter(fn (Membership $m) => $m->id !== $sheet->membership_id && $m->hasPermission('timesheet.approve') && $m->covers($sheet->owner));

        $this->deliver($approvers, new TimesheetSubmitted($sheet, $sheet->owner->user->name));
    }

    public function timesheetReturned(Timesheet $sheet, Membership $by, string $note): void
    {
        $this->deliver($this->members([$sheet->membership_id]), new TimesheetReturned($sheet, $by->user->name, $note));
    }

    // --- who ---------------------------------------------------------------------------------------

    private function actor(): ?Membership
    {
        return $this->current->membership?->loadMissing('user:id,name');
    }

    /**
     * Active people (still employed) by id.
     *
     * @param  iterable<int, int|null>  $ids
     * @return Collection<int, Membership>
     */
    private function members(iterable $ids): Collection
    {
        $ids = collect($ids)->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : Membership::withoutGlobalScope(OrganizationScope::class)->with(['user', 'role'])
            ->where('status', Membership::ACTIVE)->whereIn('id', $ids)->get();
    }

    /** The reporter, the assignee and everyone watching. @return Collection<int, Membership> */
    private function followers(Task $task): Collection
    {
        return $this->members(collect([$task->reporter_id, $task->assignee_id])->merge($task->watchers()->pluck('memberships.id')));
    }

    /** Project members who may triage requests. @return Collection<int, Membership> */
    private function triagers(Project $project): Collection
    {
        return $this->members($project->activeMembers()->pluck('membership_id'))->filter(fn (Membership $m) => $m->hasPermission('task.triage'));
    }

    /** @return Collection<int, Membership> */
    private function projectMembers(Project $project): Collection
    {
        return $this->members($project->activeMembers()->pluck('membership_id'))->reject(fn (Membership $m) => $m->isExternal());
    }

    // --- sending -----------------------------------------------------------------------------------

    /** @param Collection<int, Membership> $recipients */
    private function deliver(Collection $recipients, AppNotification $notification): void
    {
        $actorId = $this->current->membership?->id;
        $recipients = $recipients->reject(fn (Membership $m) => $m->id === $actorId)->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Notification::send($recipients, $notification);
        } catch (Throwable $e) {
            report($e); // never let a mail problem undo what the person just did
        }
    }

    /**
     * A daily reminder: sent at most once a day per person and task.
     *
     * @param  Collection<int, Membership>  $recipients
     */
    private function remind(Collection $recipients, AppNotification $notification): int
    {
        $fresh = $recipients->reject(fn (Membership $m) => $m->notifications()->where('type', $notification::class)
            ->where('data', 'like', '%"task_id":'.$notification->taskId().'}%')->where('created_at', '>=', today())->exists())->values();

        $this->deliver($fresh, $notification);

        return $fresh->count();
    }

    /** A short plain-text taste of a comment: mentions as names, tags stripped. */
    private function excerpt(string $body): string
    {
        $text = preg_replace('/@\[([^\]]+)\]\(m:\d+\)/', '@$1', $body) ?? $body;

        return Str::limit(trim(html_entity_decode(strip_tags($text))), 200);
    }
}
