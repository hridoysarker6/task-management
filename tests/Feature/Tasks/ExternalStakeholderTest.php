<?php

namespace Tests\Feature\Tasks;

use App\Models\Comment;
use App\Models\Task;
use App\Services\TaskLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** The hard rules for external stakeholders (WORKFLOW.md 4.5): no internal comments, no estimates, no directory, no other projects. */
class ExternalStakeholderTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    private Task $own; // ECP-1: raised by the client, worked on by the team

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
        $this->own = $this->task($this->ecp, $this->client, [
            'assignee_id' => $this->dev1->id, 'story_points' => 8, 'original_estimate_min' => 480, 'remaining_estimate_min' => 240,
        ]);
        $this->own->forceFill(['time_spent_min' => 240])->save(); // a cached total, never mass-assigned
    }

    private function page(string $key = 'ECP-1', $as = null): array
    {
        return $this->actingAsMember($as ?? $this->client)->get("/tasks/{$key}")->assertOk()->viewData('page')['props'];
    }

    public function test_internal_comments_are_hidden_from_clients(): void
    {
        foreach ([['Team secret', true], ['Public update', false]] as [$body, $internal]) {
            $this->actingAsMember($this->tlA)->post('/tasks/ECP-1/comments', ['body' => $body, 'is_internal' => $internal]);
        }

        $client = $this->page();
        $this->assertSame(['Public update'], collect($client['comments'])->pluck('body')->all());
        $this->assertFalse($client['abilities']['internal_comments']);

        $team = $this->page('ECP-1', $this->tlA);
        $this->assertCount(2, $team['comments']);
    }

    public function test_an_internal_comment_cannot_be_reached_by_id_either(): void
    {
        $this->actingAsMember($this->tlA)->post('/tasks/ECP-1/comments', ['body' => 'Team secret', 'is_internal' => true]);
        $secret = Comment::sole();

        $this->actingAsMember($this->client)->put("/comments/{$secret->id}", ['body' => 'x'])->assertNotFound();
        $this->actingAsMember($this->client)->delete("/comments/{$secret->id}")->assertNotFound();
        $this->assertSame('Team secret', $secret->fresh()->body);
    }

    public function test_estimates_story_points_and_time_spent_are_never_sent_to_clients(): void
    {
        $task = $this->page()['task'];

        foreach (['story_points', 'original_estimate_min', 'remaining_estimate_min', 'time_spent_min'] as $field) {
            $this->assertNull($task[$field], "{$field} must not reach an external stakeholder");
        }
        $this->assertFalse($this->page()['abilities']['effort']);

        $team = $this->page('ECP-1', $this->tlA)['task'];
        $this->assertEquals(8, $team['story_points']);
        $this->assertSame(480, $team['original_estimate_min']);
        $this->assertSame(240, $team['time_spent_min']);
    }

    public function test_the_raw_page_data_does_not_contain_effort_numbers_anywhere(): void
    {
        $html = $this->actingAsMember($this->client)->get('/tasks/ECP-1')->getContent();

        // The Inertia page object is embedded in the HTML: the secret values must not be anywhere in it.
        $this->assertStringNotContainsString('&quot;original_estimate_min&quot;:480', $html);
        $this->assertStringNotContainsString('&quot;remaining_estimate_min&quot;:240', $html);
        $this->assertStringNotContainsString('&quot;time_spent_min&quot;:240', $html);
    }

    public function test_estimate_changes_are_left_out_of_a_clients_history(): void
    {
        $this->actingAsMember($this->tlA)->patch('/tasks/ECP-1', ['original_estimate_min' => 600, 'story_points' => 13, 'priority' => 5]);

        $fields = collect($this->page()['activities'])->pluck('field')->filter()->all();
        $this->assertContains('priority', $fields);
        $this->assertNotContains('original_estimate_min', $fields);
        $this->assertNotContains('story_points', $fields);

        $teamFields = collect($this->page('ECP-1', $this->tlA)['activities'])->pluck('field')->filter()->all();
        $this->assertContains('original_estimate_min', $teamFields);
    }

    public function test_internal_comments_leave_no_trace_in_a_clients_history(): void
    {
        $this->actingAsMember($this->tlA)->post('/tasks/ECP-1/comments', ['body' => 'Secret', 'is_internal' => true]);
        $this->actingAsMember($this->tlA)->post('/tasks/ECP-1/comments', ['body' => 'Public', 'is_internal' => false]);

        $this->assertSame(1, collect($this->page()['activities'])->where('event', 'commented')->count());
        $this->assertSame(2, collect($this->page('ECP-1', $this->tlA)['activities'])->where('event', 'commented')->count());
    }

    public function test_clients_cannot_set_effort_on_their_own_tasks(): void
    {
        $this->actingAsMember($this->client)->patch('/tasks/ECP-1', ['title' => 'Better title', 'story_points' => 99, 'original_estimate_min' => 1]);

        $t = $this->own->fresh();
        $this->assertSame('Better title', $t->title);
        $this->assertEquals(8, $t->story_points);
        $this->assertSame(480, $t->original_estimate_min);
    }

    public function test_clients_do_not_see_the_company_directory_in_the_task_page(): void
    {
        $props = $this->page();

        $this->assertSame([], $props['options']['mentionable']);
        $this->assertSame([], $props['options']['assignees']);
        $this->assertSame([], $props['watchers']);
        $this->assertFalse($props['abilities']['assign']);
    }

    public function test_clients_cannot_open_the_people_pages_or_the_org_chart(): void
    {
        $this->actingAsMember($this->client)->get('/people')->assertForbidden();
        $this->actingAsMember($this->client)->get('/org-chart')->assertForbidden();
        $this->actingAsMember($this->client)->get('/settings/roles')->assertForbidden();
    }

    public function test_clients_see_other_projects_not_at_all(): void
    {
        $this->actingAsMember($this->client)->get("/projects/{$this->oth->id}")->assertForbidden();
        $this->actingAsMember($this->client)->get('/projects')->assertInertia(fn ($p) => $p->has('projects.data', 1)->where('projects.data.0.key', 'ECP'));
        $this->actingAsMember($this->client)->get('/tasks/OTH-1')->assertNotFound(); // no such task
    }

    public function test_only_the_clients_own_tasks_open_unless_the_project_shows_everything(): void
    {
        $teams = $this->task($this->ecp, $this->tlA, ['title' => 'Internal refactoring']);

        $this->actingAsMember($this->client)->get('/tasks/ECP-2')->assertForbidden();

        $this->ecp->forceFill(['client_can_see_all' => true])->save();
        $this->actingAsMember($this->client)->get('/tasks/ECP-2')->assertOk();
        $this->assertNotNull($teams);
    }

    public function test_links_to_tasks_a_client_cannot_see_are_not_listed(): void
    {
        $internal = $this->task($this->ecp, $this->tlA, ['title' => 'Internal refactoring']);
        app(TaskLinkService::class)->link($this->own, $internal, 'relates_to', $this->tlA);

        $this->assertSame([], $this->page()['links']);
        $this->assertCount(1, $this->page('ECP-1', $this->tlA)['links']);
    }

    public function test_the_childrens_list_hides_sub_tasks_clients_cannot_see(): void
    {
        $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->own->id, 'title' => 'Hidden sub-task']);

        $this->assertSame([], $this->page()['children']);
        $this->assertCount(1, $this->page('ECP-1', $this->tlA)['children']);
    }

    public function test_clients_can_comment_attach_and_reopen_but_not_close_or_assign(): void
    {
        $this->actingAsMember($this->client)->post('/tasks/ECP-1/comments', ['body' => 'Any news?'])->assertSessionHasNoErrors();
        $this->actingAsMember($this->client)->post('/tasks/ECP-1/assign', ['assignee_id' => $this->dev1->id])->assertForbidden();

        $done = $this->ecp->statuses()->where('name', 'Done')->first();
        $this->own->forceFill(['status_id' => $done->id, 'completed_at' => now()])->save();
        $progress = $this->ecp->statuses()->where('name', 'In Progress')->first();
        $this->actingAsMember($this->client)->post('/tasks/ECP-1/status', ['status_id' => $progress->id])->assertSessionHasNoErrors(); // "not fixed"
        $this->actingAsMember($this->client)->post('/tasks/ECP-1/status', ['status_id' => $done->id])->assertSessionHasErrors('status_id'); // cannot close
    }

    public function test_clients_comments_are_flagged_as_public_in_the_team_view(): void
    {
        $this->actingAsMember($this->client)->post('/tasks/ECP-1/comments', ['body' => 'From the client', 'is_internal' => true]);

        $this->assertFalse($this->page('ECP-1', $this->tlA)['comments'][0]['is_internal']);
    }
}
