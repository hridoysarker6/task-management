<?php

namespace Tests\Feature\Notifications;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Models\WorkLog;
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
use App\Notifications\UserInvited;
use App\Services\NotificationPreferenceService;
use App\Services\SprintService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Who is told what, the bell, preferences, reminders and failed jobs (Phase 10). */
class NotificationsTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    private function props(TestResponse $response): array
    {
        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    private function key(Task $t): string
    {
        return $t->project->key.'-'.$t->number;
    }

    private function sentTo(Membership $m, string $class): bool
    {
        return Notification::sent($m, $class)->isNotEmpty();
    }

    private function bell(Membership $m): int
    {
        return DB::table('notifications')->where('notifiable_type', Membership::class)->where('notifiable_id', $m->id)->count();
    }

    // ---- assignment ----------------------------------------------------------------------------

    public function test_the_new_assignee_is_told_and_the_one_who_lost_the_task_too(): void
    {
        Notification::fake();
        $task = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);

        $this->actingAsMember($this->tlA)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->tlA->id])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->dev1, TaskAssigned::class, fn ($n) => $n->mode === 'unassigned' && $n->newAssignee === $this->tlA->user->name);
        $this->assertFalse($this->sentTo($this->tlA, TaskAssigned::class)); // the actor is not told about their own action
    }

    public function test_assigning_someone_else_tells_them(): void
    {
        Notification::fake();
        $task = $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->tlA)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->dev1->id]);

        Notification::assertSentTo($this->dev1, TaskAssigned::class, fn ($n) => $n->mode === 'assigned' && str_contains($n->title(), $this->tlA->user->name));
    }

    public function test_assigning_a_task_to_the_same_person_again_tells_nobody(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);
        Notification::fake();

        $this->actingAsMember($this->tlA)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->dev1->id]);

        Notification::assertNothingSent();
    }

    // ---- triage --------------------------------------------------------------------------------

    public function test_a_new_request_goes_to_the_people_who_triage_and_the_outcome_back_to_the_requester(): void
    {
        $this->ecp->update(['triage_mode' => 'external_only']);
        Notification::fake();

        $this->actingAsMember($this->client)->post('/tasks', ['project_id' => $this->ecp->id, 'title' => 'Please add dark mode'])->assertSessionHasNoErrors();
        $task = Task::withoutGlobalScopes()->latest('id')->firstOrFail();

        Notification::assertSentTo($this->pm, TaskInTriage::class);
        $this->assertFalse($this->sentTo($this->client, TaskInTriage::class));
        $this->assertFalse($this->sentTo($this->dev1, TaskInTriage::class)); // developers cannot triage

        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($task)}/triage/reject", ['reason' => 'Out of scope']);
        Notification::assertSentTo($this->client, TriageDecision::class, fn ($n) => ! $n->accepted && $n->reason === 'Out of scope');

        $this->ecp->refresh();
        $other = $this->task($this->ecp, $this->client, ['status' => 'Triage']);
        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($other)}/triage/accept");
        Notification::assertSentTo($this->client, TriageDecision::class, fn ($n) => $n->accepted);
    }

    // ---- status --------------------------------------------------------------------------------

    private function move(Task $task, string $to, Membership $by)
    {
        return $this->actingAsMember($by)->post("/tasks/{$this->key($task)}/status", ['status_id' => $this->ecp->statuses()->where('name', $to)->value('id')]);
    }

    public function test_a_status_change_tells_the_reporter_assignee_and_watchers_but_not_the_mover(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);
        $task->watchers()->attach($this->stakeholder->id);
        Notification::fake();

        $this->move($task, 'To Do', $this->tlA)->assertSessionHasNoErrors();

        Notification::assertSentTo($this->pm, TaskStatusChanged::class, fn ($n) => $n->from === 'Backlog' && $n->to === 'To Do');
        Notification::assertSentTo($this->dev1, TaskStatusChanged::class);
        Notification::assertSentTo($this->stakeholder, TaskStatusChanged::class);
        $this->assertFalse($this->sentTo($this->tlA, TaskStatusChanged::class));
    }

    public function test_closing_and_reopening_are_their_own_notification(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'status' => 'In Review']);
        Notification::fake();

        $this->move($task, 'Done', $this->tlA)->assertSessionHasNoErrors();
        Notification::assertSentTo($this->dev1, TaskClosed::class, fn ($n) => $n->closed);
        Notification::assertNotSentTo($this->dev1, TaskStatusChanged::class);

        $this->move($task, 'In Progress', $this->tlA)->assertSessionHasNoErrors();
        Notification::assertSentTo($this->dev1, TaskClosed::class, fn ($n) => ! $n->closed);
    }

    // ---- comments and mentions -----------------------------------------------------------------

    public function test_watchers_hear_about_comments_and_the_mentioned_about_the_mention_only(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);
        $task->watchers()->attach([$this->dev1->id, $this->tlA->id]);
        Notification::fake();

        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($task)}/comments", ['body' => "Please look @[{$this->tlA->user->name}](m:{$this->tlA->id}) at <b>this</b>"])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->dev1, TaskCommented::class, fn ($n) => $n->excerpt === "Please look @{$this->tlA->user->name} at this");
        Notification::assertSentTo($this->tlA, TaskMentioned::class);
        Notification::assertNotSentTo($this->tlA, TaskCommented::class);
        $this->assertFalse($this->sentTo($this->pm, TaskCommented::class)); // not the author
    }

    public function test_an_internal_comment_never_reaches_a_client_watching_the_task(): void
    {
        $task = $this->task($this->ecp, $this->client);
        $task->watchers()->attach([$this->client->id, $this->dev1->id]);
        Notification::fake();

        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($task)}/comments", ['body' => 'Between us', 'is_internal' => true]);

        Notification::assertSentTo($this->dev1, TaskCommented::class);
        $this->assertFalse($this->sentTo($this->client, TaskCommented::class));

        $this->actingAsMember($this->pm)->post("/tasks/{$this->key($task)}/comments", ['body' => 'For everyone']);
        Notification::assertSentTo($this->client, TaskCommented::class);
    }

    // ---- sprints and timesheets ----------------------------------------------------------------

    public function test_sprint_start_and_completion_tell_the_project_members(): void
    {
        $sprint = Sprint::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'project_id' => $this->ecp->id, 'name' => 'Sprint 1', 'start_date' => today(), 'end_date' => today()->addDays(13), 'status' => 'planned', 'created_by' => $this->pm->id]);
        $task = $this->task($this->ecp, $this->pm, ['story_points' => 3]);
        $this->enterCompany($this->pm);
        $sprints = app(SprintService::class);
        $sprints->addTask($sprint, $task);
        Notification::fake();

        $sprints->start($sprint);
        Notification::assertSentTo($this->tlA, SprintStarted::class);
        Notification::assertSentTo($this->dev1, SprintStarted::class);
        $this->assertFalse($this->sentTo($this->client, SprintStarted::class)); // clients are not told about sprints
        $this->assertFalse($this->sentTo($this->dev3, SprintStarted::class)); // not on this project
        $this->assertFalse($this->sentTo($this->pm, SprintStarted::class)); // the one who started it

        $sprints->complete($sprint->refresh());
        Notification::assertSentTo($this->dev1, SprintCompleted::class);
    }

    private function sheetFor(Membership $m): Timesheet
    {
        $sheet = Timesheet::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'membership_id' => $m->id, 'week_start' => Timesheet::weekStartOf(today())->toDateString(), 'status' => 'draft', 'total_min' => 120]);
        WorkLog::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'task_id' => $this->task($this->ecp, $this->pm)->id, 'membership_id' => $m->id, 'timesheet_id' => $sheet->id, 'work_date' => today(), 'minutes' => 120]);

        return $sheet;
    }

    public function test_a_submitted_timesheet_reaches_the_approvers_above_and_a_returned_one_its_owner(): void
    {
        $this->sheetFor($this->dev1);
        Notification::fake();

        $this->actingAsMember($this->dev1)->post('/timesheet/submit', ['week' => Timesheet::weekStartOf(today())->toDateString()])->assertSessionHasNoErrors();

        Notification::assertSentTo($this->tlA, TimesheetSubmitted::class);
        Notification::assertSentTo($this->dm, TimesheetSubmitted::class);
        $this->assertFalse($this->sentTo($this->tlB, TimesheetSubmitted::class)); // another team
        $this->assertFalse($this->sentTo($this->dev2, TimesheetSubmitted::class));

        $sheet = Timesheet::withoutGlobalScopes()->firstOrFail();
        $this->actingAsMember($this->tlA)->post("/timesheets/{$sheet->id}/return", ['note' => 'Split the meeting']);
        Notification::assertSentTo($this->dev1, TimesheetReturned::class, fn ($n) => $n->note === 'Split the meeting');
    }

    // ---- preferences ---------------------------------------------------------------------------

    public function test_channels_follow_the_defaults_until_a_person_changes_them(): void
    {
        $prefs = app(NotificationPreferenceService::class);

        $this->assertSame(['database', 'mail'], $prefs->channels($this->dev1, 'task_assigned'));
        $this->assertSame(['database'], $prefs->channels($this->dev1, 'status_changed'));

        $prefs->save($this->dev1, ['task_assigned' => ['app' => true, 'mail' => false], 'status_changed' => ['app' => false, 'mail' => true], 'not_an_event' => ['app' => true, 'mail' => true]]);

        $this->assertSame(['database'], $prefs->channels($this->dev1, 'task_assigned'));
        $this->assertSame(['mail'], $prefs->channels($this->dev1, 'status_changed'));
        $this->assertSame(['database', 'mail'], $prefs->channels($this->dev2, 'task_assigned')); // other people unaffected
        $this->assertSame(2, DB::table('notification_preferences')->count());
    }

    public function test_switching_everything_off_for_an_event_sends_nothing(): void
    {
        app(NotificationPreferenceService::class)->save($this->dev1, ['task_assigned' => ['app' => false, 'mail' => false]]);
        $task = $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->tlA)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->dev1->id]);

        $this->assertSame(0, $this->bell($this->dev1));
    }

    public function test_the_settings_page_lists_every_event_and_saves(): void
    {
        $props = $this->props($this->actingAsMember($this->dev1)->get('/notifications/preferences'));
        $this->assertCount(11, $props['preferences']);

        $this->actingAsMember($this->dev1)->put('/notifications/preferences', ['preferences' => ['comment' => ['app' => false, 'mail' => true]]])->assertSessionHasNoErrors();

        $comment = collect($this->props($this->actingAsMember($this->dev1)->get('/notifications/preferences'))['preferences'])->firstWhere('event', 'comment');
        $this->assertSame([false, true], [$comment['app'], $comment['mail']]);

        $this->actingAsMember($this->dev1)->put('/notifications/preferences', ['preferences' => ['comment' => ['app' => 'maybe']]])->assertSessionHasErrors();
    }

    // ---- the bell ------------------------------------------------------------------------------

    private function deliverToBell(Task $task): void
    {
        $this->actingAsMember($this->tlA)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->dev1->id]);
    }

    public function test_the_bell_stores_the_notification_and_lists_it(): void
    {
        $this->deliverToBell($this->task($this->ecp, $this->pm, ['title' => 'Fix login']));

        $recent = $this->actingAsMember($this->dev1)->getJson('/notifications/recent')->assertOk()->json();

        $this->assertSame(1, $recent['unread']);
        $this->assertStringContainsString('assigned you', $recent['items'][0]['title']);
        $this->assertSame('Fix login', $recent['items'][0]['body']);
        $this->assertStringContainsString('/tasks/ECP-', $recent['items'][0]['url']);
        $this->assertSame(0, $this->actingAsMember($this->dev2)->getJson('/notifications/recent')->json('unread'));
    }

    public function test_reading_one_or_all_clears_the_unread_count(): void
    {
        $this->deliverToBell($this->task($this->ecp, $this->pm));
        $this->deliverToBell($this->task($this->ecp, $this->pm));
        $first = $this->actingAsMember($this->dev1)->getJson('/notifications/recent')->json('items.0.id');

        $this->actingAsMember($this->dev1)->postJson("/notifications/{$first}/read")->assertOk();
        $this->assertSame(1, $this->actingAsMember($this->dev1)->getJson('/notifications/recent')->json('unread'));

        $this->actingAsMember($this->dev1)->postJson('/notifications/read-all')->assertOk();
        $this->assertSame(0, $this->actingAsMember($this->dev1)->getJson('/notifications/recent')->json('unread'));
    }

    public function test_nobody_reads_someone_else_s_notification(): void
    {
        $this->deliverToBell($this->task($this->ecp, $this->pm));
        $id = DB::table('notifications')->value('id');

        $this->actingAsMember($this->dev2)->postJson("/notifications/{$id}/read")->assertNotFound();
        $this->assertNull(DB::table('notifications')->value('read_at'));
    }

    public function test_the_notifications_page_filters_unread_and_paginates(): void
    {
        foreach (range(1, 30) as $i) {
            $this->deliverToBell($this->task($this->ecp, $this->pm));
        }
        $this->actingAsMember($this->dev1)->postJson('/notifications/read-all');
        $this->deliverToBell($this->task($this->ecp, $this->pm));

        $all = $this->props($this->actingAsMember($this->dev1)->get('/notifications'));
        $unread = $this->props($this->actingAsMember($this->dev1)->get('/notifications?unread=1'));

        $this->assertSame(31, $all['notifications']['total']);
        $this->assertCount(25, $all['notifications']['data']);
        $this->assertSame(1, $unread['notifications']['total']);
        $this->assertSame(1, $all['unread']);
    }

    // ---- mail ----------------------------------------------------------------------------------

    public function test_the_email_is_branded_with_the_company_and_links_to_the_settings(): void
    {
        $this->org->update(['name' => 'Acme Software']);
        $task = $this->task($this->ecp, $this->pm, ['title' => 'Fix login']);
        $notification = new TaskAssigned($task->load('project'), $this->tlA->user->name);

        $html = (string) $notification->toMail($this->dev1)->render();

        $this->assertStringContainsString('Acme Software', $html);
        $this->assertStringContainsString('assigned you', $html);
        $this->assertStringContainsString('Fix login', $html);
        $this->assertStringContainsString(route('tasks.show', $this->key($task)), $html);
        $this->assertStringContainsString(route('notifications.preferences'), $html);
    }

    public function test_every_notification_is_queued_and_email_goes_to_the_login_address(): void
    {
        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');
            $this->assertTrue(is_subclass_of($class, ShouldQueue::class), "{$class} should be queued");
        }
        $this->assertInstanceOf(ShouldQueue::class, new UserInvited(new Invitation, $this->org));
        $this->assertSame($this->dev1->user->email, $this->dev1->routeNotificationForMail());
        $this->assertTrue(is_subclass_of(TaskAssigned::class, AppNotification::class));
    }

    // ---- reminders -----------------------------------------------------------------------------

    public function test_due_tomorrow_goes_to_the_assignee_and_overdue_to_the_assignee_and_their_manager(): void
    {
        $due = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'due_date' => today()->addDay(), 'status' => 'In Progress']);
        $late = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'due_date' => today()->subDays(2), 'status' => 'In Progress']);
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev2->id, 'due_date' => today()->addDay(), 'status' => 'Done']); // finished
        $this->task($this->ecp, $this->pm, ['due_date' => today()->subDay(), 'status' => 'To Do']); // nobody to tell
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev2->id, 'due_date' => today()->addDays(3), 'status' => 'To Do']); // not tomorrow
        Notification::fake();

        Artisan::call('tasks:send-reminders');

        Notification::assertSentTo($this->dev1, TaskDueTomorrow::class, fn ($n) => $n->task->is($due));
        Notification::assertSentTo($this->dev1, TaskOverdue::class, fn ($n) => $n->task->is($late) && ! $n->forManager);
        Notification::assertSentTo($this->tlA, TaskOverdue::class, fn ($n) => $n->task->is($late) && $n->forManager && $n->assigneeName === $this->dev1->user->name);
        Notification::assertNotSentTo($this->dev2, TaskDueTomorrow::class);
        Notification::assertSentToTimes($this->dev1, TaskOverdue::class, 1);
    }

    public function test_running_the_reminders_twice_in_a_day_does_not_repeat_them(): void
    {
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'due_date' => today()->subDay(), 'status' => 'To Do']);

        Artisan::call('tasks:send-reminders');
        $once = DB::table('notifications')->count();
        Artisan::call('tasks:send-reminders');

        $this->assertSame(2, $once); // the assignee and their lead
        $this->assertSame($once, DB::table('notifications')->count());
    }

    public function test_reminders_are_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('tasks:send-reminders')->assertSuccessful();
    }

    // ---- failed jobs ---------------------------------------------------------------------------

    private function failedJob(): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => 'App\\Notifications\\TaskAssigned']), 'exception' => "RuntimeException: Mail server down\n#0 trace", 'failed_at' => now()]);

        return $uuid;
    }

    public function test_the_system_admin_sees_failed_jobs_and_can_dismiss_them(): void
    {
        $uuid = $this->failedJob();
        $admin = User::factory()->systemAdmin()->create();

        $props = $this->props($this->actingAs($admin)->get('/admin/failed-jobs'));
        $this->assertSame([$uuid], array_column($props['jobs'], 'uuid'));
        $this->assertSame('RuntimeException: Mail server down', $props['jobs'][0]['error']);

        $this->actingAs($admin)->delete("/admin/failed-jobs/{$uuid}")->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->actingAs($admin)->delete("/admin/failed-jobs/{$uuid}")->assertNotFound();
    }

    public function test_company_people_cannot_see_failed_jobs(): void
    {
        $uuid = $this->failedJob();

        $this->actingAsMember($this->admin)->get('/admin/failed-jobs')->assertForbidden();
        $this->actingAsMember($this->admin)->delete("/admin/failed-jobs/{$uuid}")->assertForbidden();
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_mail_failure_never_breaks_the_action_that_caused_it(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));
        $task = $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->tlA)->post("/tasks/{$this->key($task)}/assign", ['assignee_id' => $this->dev1->id])->assertSessionHasNoErrors();

        $this->assertSame($this->dev1->id, $task->fresh()->assignee_id);
    }
}
