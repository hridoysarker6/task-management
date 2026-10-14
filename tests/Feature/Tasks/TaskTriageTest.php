<?php

namespace Tests\Feature\Tasks;

use App\Models\Comment;
use App\Models\Membership;
use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** The triage queue: accept, reject (with a reason) or ask for more information. */
class TaskTriageTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    private Task $request; // ECP-1: raised by the client, waiting in triage

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
        $this->request = $this->task($this->ecp, $this->client, ['status' => 'Triage', 'title' => 'Payment page shows wrong total']);
    }

    private function accept(array $data = [], ?Membership $by = null, string $key = 'ECP-1')
    {
        return $this->actingAsMember($by ?? $this->pm)->post("/tasks/{$key}/triage/accept", $data);
    }

    private function reject(?string $reason, ?Membership $by = null, string $key = 'ECP-1')
    {
        return $this->actingAsMember($by ?? $this->pm)->post("/tasks/{$key}/triage/reject", ['reason' => $reason]);
    }

    public function test_the_queue_lists_waiting_requests(): void
    {
        $this->task($this->ecp, $this->pm); // an ordinary backlog task: not in the queue

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/triage")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Tasks/Triage')->has('requests', 1)
                ->where('requests.0.key', 'ECP-1')->where('requests.0.external', true)->where('requests.0.reporter', $this->client->user->name)
                ->has('assignees')->has('priorities', 5));
    }

    public function test_only_people_with_the_triage_permission_see_the_queue(): void
    {
        $this->actingAsMember($this->tlA)->get("/projects/{$this->ecp->id}/triage")->assertOk(); // team leads triage
        $this->actingAsMember($this->dev1)->get("/projects/{$this->ecp->id}/triage")->assertForbidden();
        $this->actingAsMember($this->client)->get("/projects/{$this->ecp->id}/triage")->assertForbidden();
        $this->actingAsMember($this->dev3)->get("/projects/{$this->ecp->id}/triage")->assertForbidden();
    }

    public function test_the_queue_is_oldest_first(): void
    {
        $this->travelTo(now()->addHour());
        $newer = $this->task($this->ecp, $this->client, ['status' => 'Triage', 'title' => 'Newer']);

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/triage")->assertInertia(fn ($p) => $p->where('requests.0.title', 'Payment page shows wrong total')->where('requests.1.id', $newer->id));
    }

    // ---- accept ----------------------------------------------------------------------------

    public function test_accepting_sets_type_priority_and_assignee_and_moves_it_to_the_backlog(): void
    {
        $this->accept(['type' => 'bug', 'priority' => 4, 'assignee_id' => $this->dev1->id])->assertSessionHasNoErrors();

        $t = $this->request->fresh();
        $this->assertSame(['bug', 4, $this->dev1->id, 'Backlog'], [$t->type, $t->priority, $t->assignee_id, $t->status->name]);
        $this->assertContains($this->dev1->id, $t->watchers()->pluck('memberships.id')->all());
        $this->assertContains('triage_accepted', $t->activities()->pluck('event')->all());
    }

    public function test_accepting_without_choices_just_moves_it_on(): void
    {
        $this->accept()->assertSessionHasNoErrors();

        $t = $this->request->fresh();
        $this->assertSame(['task', 3, null, 'Backlog'], [$t->type, $t->priority, $t->assignee_id, $t->status->name]);
    }

    public function test_an_accepted_task_leaves_the_queue_and_is_visible_to_the_team_as_before(): void
    {
        $this->accept();

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/triage")->assertInertia(fn ($p) => $p->has('requests', 0));
        $this->assertSame('Backlog', $this->request->fresh()->status->name);
    }

    public function test_accepting_follows_the_assignment_rules(): void
    {
        $this->accept(['assignee_id' => $this->dev3->id])->assertSessionHasErrors('assignee_id'); // not on the project
        $this->accept(['assignee_id' => $this->client->id])->assertSessionHasErrors('assignee_id'); // external

        $this->assertSame('Triage', $this->request->fresh()->status->name, 'a failed accept leaves the request waiting');
        $this->assertNull($this->request->fresh()->assignee_id);
    }

    public function test_accepting_validates_the_type_and_priority(): void
    {
        $this->accept(['type' => 'saga'])->assertSessionHasErrors('type');
        $this->accept(['priority' => 9])->assertSessionHasErrors('priority');
        $this->accept(['type' => 'subtask'])->assertSessionHasErrors('parent_id'); // a sub-task needs a parent
        $this->assertSame('Triage', $this->request->fresh()->status->name);
    }

    public function test_a_task_not_in_triage_cannot_be_accepted(): void
    {
        $this->accept();

        $this->accept()->assertSessionHasErrors('task');
    }

    // ---- reject ----------------------------------------------------------------------------

    public function test_rejecting_needs_a_reason_and_closes_the_request(): void
    {
        $this->reject(null)->assertSessionHasErrors('reason');
        $this->reject('   ')->assertSessionHasErrors('reason');
        $this->assertSame('Triage', $this->request->fresh()->status->name);

        $this->reject('Duplicate of the checkout work already planned')->assertSessionHasNoErrors();

        $t = $this->request->fresh();
        $this->assertSame('Rejected', $t->status->name);
        $this->assertSame('cancelled', $t->status->category);
        $this->assertSame('Duplicate of the checkout work already planned', $t->resolution_note);
        $this->assertNull($t->completed_at);
        $line = $t->activities()->where('event', 'triage_rejected')->first();
        $this->assertSame('Duplicate of the checkout work already planned', $line->new_value);
    }

    public function test_the_requester_can_see_why_it_was_rejected(): void
    {
        $this->reject('Out of scope for this release');

        $this->actingAsMember($this->client)->get('/tasks/ECP-1')->assertOk()
            ->assertInertia(fn ($p) => $p->where('task.resolution_note', 'Out of scope for this release')->where('task.status.name', 'Rejected'));
    }

    public function test_rejecting_uses_any_cancelled_status_when_there_is_no_rejected_one(): void
    {
        $this->ecp->statuses()->where('name', 'Rejected')->delete();

        $this->reject('No')->assertSessionHasNoErrors();

        $this->assertSame('Cancelled', $this->request->fresh()->status->name);
    }

    public function test_rejecting_without_any_cancelled_status_explains_what_is_missing(): void
    {
        $this->ecp->statuses()->where('category', 'cancelled')->delete();

        $this->reject('No')->assertSessionHasErrors('status');
        $this->assertSame('Triage', $this->request->fresh()->status->name);
    }

    // ---- ask for information ------------------------------------------------------------------

    public function test_asking_for_information_posts_a_public_comment_and_keeps_the_request_waiting(): void
    {
        $this->actingAsMember($this->pm)->post('/tasks/ECP-1/triage/info', ['message' => 'Which browser, and what was the total?'])->assertSessionHasNoErrors();

        $comment = Comment::sole();
        $this->assertSame(['Which browser, and what was the total?', $this->pm->id], [$comment->body, $comment->membership_id]);
        $this->assertFalse((bool) $comment->is_internal, 'the requester must be able to read it');
        $this->assertSame('Triage', $this->request->fresh()->status->name);
        $this->assertContains('triage_info_requested', $this->request->activities()->pluck('event')->all());

        // ...and the client sees it on their own request.
        $this->actingAsMember($this->client)->get('/tasks/ECP-1')->assertInertia(fn ($p) => $p->has('comments', 1));
    }

    public function test_asking_needs_a_message(): void
    {
        $this->actingAsMember($this->pm)->post('/tasks/ECP-1/triage/info', ['message' => ''])->assertSessionHasErrors('message');
        $this->assertSame(0, Comment::count());
    }

    // ---- permissions & isolation ------------------------------------------------------------------

    public function test_only_triagers_who_can_see_the_request_can_act(): void
    {
        $this->accept([], $this->dev1)->assertForbidden(); // lacks task.triage
        $this->reject('x', $this->client)->assertForbidden();
        $this->accept([], $this->dev3)->assertForbidden();
        $this->actingAsMember($this->dev1)->post('/tasks/ECP-1/triage/info', ['message' => 'hi'])->assertForbidden();

        // A triager outside the project (TL-B holds task.triage but cannot see ECP tasks).
        $this->accept([], $this->tlB)->assertForbidden();
        $this->assertSame('Triage', $this->request->fresh()->status->name);
    }

    public function test_triage_does_nothing_in_an_archived_project(): void
    {
        $this->ecp->forceFill(['status' => Project::ARCHIVED])->save();

        $this->accept()->assertForbidden();
    }

    public function test_a_deleted_request_cannot_be_triaged(): void
    {
        $this->request->delete();

        $this->accept()->assertNotFound();
    }

    public function test_requests_of_other_companies_are_not_found(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $project = Project::withoutGlobalScopes()->find(
            app(ProjectService::class)->create($other, $otherAdmin, ['name' => 'Theirs', 'key' => 'THR'])->id
        );
        $this->task($project, $otherAdmin, ['status' => 'Triage']);

        $this->accept([], $this->admin, 'THR-1')->assertNotFound();
        $this->actingAsMember($this->admin)->get("/projects/{$project->id}/triage")->assertNotFound();
    }

    public function test_the_triage_mode_decides_who_ends_up_in_the_queue_but_not_what_is_already_there(): void
    {
        $this->ecp->forceFill(['triage_mode' => 'off'])->save();

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/triage")->assertInertia(fn ($p) => $p->has('requests', 1));
    }
}
