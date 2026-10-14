<?php

namespace Tests\Feature\Tasks;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Task;
use App\Services\PermissionService;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** Editing, history, delete and restore, My Work, epics and the task page. */
class TaskLifecycleTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    private Task $task; // ECP-1: reported by TL-A

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
        $this->task = $this->task($this->ecp, $this->tlA, ['title' => 'Original title']);
    }

    private function edit(array $data, ?Membership $by = null, string $key = 'ECP-1')
    {
        return $this->actingAsMember($by ?? $this->tlA)->patch("/tasks/{$key}", $data);
    }

    // ---- inline editing ---------------------------------------------------------------------

    public function test_fields_are_edited_one_at_a_time(): void
    {
        $this->edit(['title' => 'New title'])->assertSessionHasNoErrors();
        $this->edit(['priority' => 5]);
        $this->edit(['due_date' => '2026-12-24']);
        $this->edit(['story_points' => 5, 'original_estimate_min' => 120, 'remaining_estimate_min' => 60]);

        $t = $this->task->fresh();
        $this->assertSame(['New title', 5, '2026-12-24', 120, 60], [$t->title, $t->priority, $t->due_date->toDateString(), $t->original_estimate_min, $t->remaining_estimate_min]);
        $this->assertEquals(5, $t->story_points);
    }

    public function test_every_change_is_written_to_the_history(): void
    {
        $this->edit(['title' => 'New title']);
        $this->edit(['priority' => 5]);
        $this->edit(['due_date' => '2026-12-24']);

        $lines = $this->task->activities()->where('event', 'updated')->get()->keyBy('field');

        $this->assertSame(['Original title', 'New title'], [$lines['title']->old_value, $lines['title']->new_value]);
        $this->assertSame(['Medium', 'Highest'], [$lines['priority']->old_value, $lines['priority']->new_value]);
        $this->assertSame([null, '2026-12-24'], [$lines['due_date']->old_value, $lines['due_date']->new_value]);
        $this->assertSame($this->tlA->id, $lines['title']->membership_id);
    }

    public function test_the_history_notes_a_description_change_without_copying_it(): void
    {
        $this->edit(['description' => '<p>Long text</p>']);

        $line = $this->task->activities()->where('field', 'description')->first();
        $this->assertSame('', $line->new_value);
        $this->assertStringNotContainsString('Long text', (string) $line->new_value);
    }

    public function test_a_save_that_changes_nothing_adds_no_history(): void
    {
        $this->edit(['title' => 'Original title', 'priority' => 3]);

        $this->assertSame(['created'], $this->task->activities()->pluck('event')->all());
    }

    public function test_updated_by_tracks_the_last_editor(): void
    {
        $this->edit(['title' => 'Edited'], $this->pm);

        $this->assertSame($this->pm->id, $this->task->fresh()->updated_by);
    }

    public function test_labels_are_edited_and_logged(): void
    {
        $bug = $this->ecp->labels()->create(['name' => 'Bug', 'color' => '#ef4444']);
        $ui = $this->ecp->labels()->create(['name' => 'UI', 'color' => '#3b82f6']);

        $this->edit(['labels' => [$bug->id, $ui->id]])->assertSessionHasNoErrors();
        $this->edit(['labels' => [$ui->id]]);
        $this->edit(['labels' => [$ui->id]]); // unchanged: not logged again

        $this->assertSame([$ui->id], $this->task->labels()->pluck('labels.id')->all());
        $lines = $this->task->activities()->where('field', 'labels')->reorder('id')->get();
        $this->assertSame(2, $lines->count());
        $this->assertSame(['Bug, UI', 'UI'], [$lines[1]->old_value, $lines[1]->new_value]);
    }

    public function test_edits_are_validated(): void
    {
        $this->edit(['title' => ''])->assertSessionHasErrors('title');
        $this->edit(['priority' => 0])->assertSessionHasErrors('priority');
        $this->edit(['story_points' => -3])->assertSessionHasErrors('story_points');
        $this->edit(['due_date' => 'never'])->assertSessionHasErrors('due_date');
        $this->edit(['start_date' => '2026-12-01', 'due_date' => '2026-11-01'])->assertSessionHasErrors('due_date');
        $this->edit(['type' => 'saga'])->assertSessionHasErrors('type');
        $this->assertSame('Original title', $this->task->fresh()->title);
    }

    public function test_the_type_and_parent_are_checked_together(): void
    {
        $epic = $this->task($this->ecp, $this->tlA, ['type' => 'epic']);
        $other = $this->task($this->ecp, $this->tlA);

        $this->edit(['parent_id' => $epic->id])->assertSessionHasNoErrors();
        $this->assertSame($epic->id, $this->task->fresh()->parent_id);

        $this->edit(['type' => 'subtask'])->assertSessionHasErrors('parent_id'); // an epic cannot parent a sub-task
        $this->edit(['type' => 'subtask', 'parent_id' => $other->id])->assertSessionHasNoErrors();
        $this->assertSame(['subtask', $other->id], [$this->task->fresh()->type, $this->task->fresh()->parent_id]);

        $this->edit(['parent_id' => null])->assertSessionHasErrors('parent_id'); // a sub-task needs a parent
    }

    public function test_a_task_cannot_become_its_own_ancestor(): void
    {
        $child = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->task->id]);

        $this->edit(['parent_id' => $child->id])->assertSessionHasErrors('parent_id');
        $this->edit(['parent_id' => $this->task->id])->assertSessionHasErrors('parent_id');
    }

    public function test_a_task_with_children_cannot_turn_into_a_type_that_cannot_hold_them(): void
    {
        $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->task->id]);

        $this->edit(['type' => 'epic'])->assertSessionHasErrors('type'); // epics hold stories/tasks/bugs, not sub-tasks
        $this->edit(['type' => 'bug'])->assertSessionHasNoErrors();
    }

    public function test_who_may_edit(): void
    {
        $this->edit(['title' => 'x'], $this->dev1)->assertForbidden(); // someone else's task, no edit_any
        $this->edit(['title' => 'x'], $this->dev3)->assertForbidden(); // cannot see it
        $this->edit(['title' => 'By the PM'], $this->pm)->assertSessionHasNoErrors(); // project manager
        $this->edit(['title' => 'By the owner'], $this->tlA)->assertSessionHasNoErrors();

        $mine = $this->task($this->ecp, $this->dev1, ['title' => 'Mine']);
        $this->edit(['title' => 'Mine, edited'], $this->dev1, 'ECP-2')->assertSessionHasNoErrors(); // edit_own: created by them
        $this->assertSame('Mine, edited', $mine->fresh()->title);
    }

    public function test_a_developer_assigned_a_task_they_did_not_create_cannot_edit_it(): void
    {
        $this->task->forceFill(['assignee_id' => $this->dev1->id])->save();

        $this->edit(['title' => 'x'], $this->dev1)->assertForbidden();
    }

    public function test_nothing_is_editable_in_an_archived_project(): void
    {
        $this->ecp->forceFill(['status' => Project::ARCHIVED])->save();

        $this->edit(['title' => 'x'])->assertForbidden();
        $this->actingAsMember($this->tlA)->post('/tasks/ECP-1/watch')->assertSessionHasNoErrors(); // watching is not a change, so it still works
    }

    // ---- delete & restore -------------------------------------------------------------------

    public function test_a_task_can_be_deleted_and_restored(): void
    {
        $this->actingAsMember($this->pm)->delete('/tasks/ECP-1')->assertRedirect(route('projects.show', $this->ecp));
        $this->assertSoftDeleted($this->task);
        $this->assertSame('deleted', Task::withTrashed()->find($this->task->id)->activities()->first()->event);

        $this->actingAsMember($this->pm)->get('/tasks/ECP-1')->assertNotFound();

        $this->actingAsMember($this->pm)->post('/tasks/ECP-1/restore')->assertRedirect('/tasks/ECP-1');
        $this->assertNotSoftDeleted($this->task);
        $this->actingAsMember($this->pm)->get('/tasks/ECP-1')->assertOk();
    }

    public function test_who_may_delete(): void
    {
        $this->actingAsMember($this->dev1)->delete('/tasks/ECP-1')->assertForbidden(); // not theirs
        $mine = $this->task($this->ecp, $this->dev1);
        $this->actingAsMember($this->tlA)->delete('/tasks/ECP-2')->assertForbidden(); // a lead has delete_own only
        $this->actingAsMember($this->dev1)->delete('/tasks/ECP-2')->assertRedirect(); // delete_own: reported by them
        $this->assertSoftDeleted($mine);
        $this->actingAsMember($this->tlA)->delete('/tasks/ECP-1')->assertRedirect(); // their own
    }

    public function test_deleting_a_parent_needs_confirmation_and_takes_the_sub_tasks_along(): void
    {
        $child = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->task->id]);

        $this->actingAsMember($this->pm)->delete('/tasks/ECP-1')->assertSessionHasErrors('children');
        $this->assertNotSoftDeleted($this->task);

        $this->actingAsMember($this->pm)->delete('/tasks/ECP-1', ['with_children' => true])->assertRedirect();
        $this->assertSoftDeleted($this->task);
        $this->assertSoftDeleted($child);

        // Restoring the parent brings the sub-tasks back, but not ones deleted earlier on their own.
        $this->actingAsMember($this->pm)->post('/tasks/ECP-1/restore');
        $this->assertNotSoftDeleted($child);
    }

    public function test_sub_tasks_deleted_separately_stay_deleted_when_the_parent_is_restored(): void
    {
        $early = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->task->id]);
        $this->actingAsMember($this->pm)->delete('/tasks/ECP-2');
        $this->travelTo(now()->addMinute());
        $late = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->task->id]);
        $this->actingAsMember($this->pm)->delete('/tasks/ECP-1', ['with_children' => true]);

        $this->actingAsMember($this->pm)->post('/tasks/ECP-1/restore');

        $this->assertSoftDeleted($early);
        $this->assertNotSoftDeleted($late);
    }

    public function test_the_trash_lists_deleted_tasks_for_those_who_can_restore_them(): void
    {
        $this->actingAsMember($this->pm)->delete('/tasks/ECP-1');

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}/trash")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tasks/Trash')->has('tasks', 1)->where('tasks.0.key', 'ECP-1')->where('tasks.0.can_restore', true));

        $this->actingAsMember($this->dev1)->get("/projects/{$this->ecp->id}/trash")->assertForbidden();
        $this->actingAsMember($this->dev1)->post('/tasks/ECP-1/restore')->assertForbidden();
        $this->actingAsMember($this->dev3)->post('/tasks/ECP-1/restore')->assertForbidden();
        $this->assertSoftDeleted($this->task);
    }

    public function test_a_live_task_cannot_be_restored_and_a_deleted_one_cannot_be_edited(): void
    {
        $this->actingAsMember($this->pm)->post('/tasks/ECP-1/restore')->assertNotFound();

        $this->actingAsMember($this->pm)->delete('/tasks/ECP-1');
        $this->edit(['title' => 'x'], $this->pm)->assertNotFound();
        $this->actingAsMember($this->pm)->post('/tasks/ECP-1/status', ['status_id' => 1])->assertNotFound();
    }

    // ---- My Work ---------------------------------------------------------------------------

    public function test_my_work_lists_assigned_reported_and_watched_tasks(): void
    {
        $assigned = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);
        $reported = $this->task($this->ecp, $this->dev1);
        $watched = $this->task($this->ecp, $this->pm);
        $watched->watchers()->attach($this->dev1->id);

        $page = fn (string $tab) => $this->actingAsMember($this->dev1)->get("/my-work?tab={$tab}")->assertOk();

        $page('assigned')->assertInertia(fn ($p) => $p->component('Tasks/MyWork')->has('tasks.data', 1)->where('tasks.data.0.id', $assigned->id)
            ->where('counts', ['assigned' => 1, 'reported' => 1, 'watching' => 1]));
        $page('reported')->assertInertia(fn ($p) => $p->has('tasks.data', 1)->where('tasks.data.0.id', $reported->id));
        $page('watching')->assertInertia(fn ($p) => $p->has('tasks.data', 1)->where('tasks.data.0.id', $watched->id));
    }

    public function test_my_work_hides_finished_tasks_unless_asked(): void
    {
        $open = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'status' => 'Done', 'completed_at' => now()]);
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'status' => 'Cancelled']);

        $this->actingAsMember($this->dev1)->get('/my-work')->assertInertia(fn ($p) => $p->has('tasks.data', 1)->where('tasks.data.0.id', $open->id));
        $this->actingAsMember($this->dev1)->get('/my-work?done=1')->assertInertia(fn ($p) => $p->has('tasks.data', 3));
    }

    public function test_my_work_is_ordered_by_due_date_and_flags_overdue_tasks(): void
    {
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'title' => 'No date']);
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'title' => 'Late', 'due_date' => now()->subDays(3)]);
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'title' => 'Soon', 'due_date' => now()->addDays(2)]);

        $this->actingAsMember($this->dev1)->get('/my-work')->assertInertia(fn ($p) => $p
            ->where('tasks.data.0.title', 'Late')->where('tasks.data.0.overdue', true)
            ->where('tasks.data.1.title', 'Soon')->where('tasks.data.1.overdue', false)
            ->where('tasks.data.2.title', 'No date'));
    }

    public function test_my_work_only_shows_what_the_person_may_see(): void
    {
        // Assigned to Dev3 in OTH-style fashion: a task Dev1 is somehow named on in a project they cannot reach stays out of the list.
        $hidden = $this->task($this->oth, $this->tlB, ['assignee_id' => $this->dev3->id]);
        $this->actingAsMember($this->dev1)->get('/my-work')->assertInertia(fn ($p) => $p->has('tasks.data', 0));
        $this->actingAsMember($this->dev3)->get('/my-work')->assertInertia(fn ($p) => $p->has('tasks.data', 1)->where('tasks.data.0.id', $hidden->id));
    }

    public function test_my_work_tabs_are_validated_and_private(): void
    {
        $this->actingAsMember($this->dev1)->get('/my-work?tab=everyone')->assertNotFound();
        auth()->logout();
        $this->get('/my-work')->assertRedirect(route('login'));
    }

    // ---- the task page and epics -------------------------------------------------------------

    public function test_the_task_page_shows_the_task_with_its_context(): void
    {
        $epic = $this->task($this->ecp, $this->tlA, ['type' => 'epic', 'title' => 'Checkout']);
        $this->task->forceFill(['parent_id' => $epic->id, 'assignee_id' => $this->dev1->id])->save();
        $sub = $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->task->id]);

        $this->actingAsMember($this->tlA)->get('/tasks/ECP-1')->assertOk()->assertInertia(fn ($p) => $p->component('Tasks/Show')
            ->where('task.key', 'ECP-1')->where('task.title', 'Original title')->where('task.assignee.name', $this->dev1->user->name)
            ->where('breadcrumbs.0.key', 'ECP-2')->where('parent.key', 'ECP-2')
            ->has('children', 1)->where('children.0.key', $sub->project->key.'-'.$sub->number)
            ->where('abilities.update', true)->where('abilities.effort', true)->where('abilities.internal_comments', true)
            ->has('options.statuses')->has('options.priorities', 5));
    }

    public function test_the_breadcrumbs_leave_out_ancestors_the_viewer_cannot_see(): void
    {
        $epic = $this->task($this->oth, $this->tlB, ['type' => 'epic']); // OTH epic: invisible to Dev1
        $this->task->forceFill(['parent_id' => $epic->id])->save(); // (cross-project parent, only possible by direct data)

        $this->actingAsMember($this->dev1)->get('/tasks/ECP-1')->assertInertia(fn ($p) => $p->where('breadcrumbs', [])->where('parent', null));
    }

    public function test_children_the_viewer_cannot_see_are_not_listed(): void
    {
        $this->task($this->ecp, $this->tlA, ['type' => 'subtask', 'parent_id' => $this->task->id, 'assignee_id' => $this->dev2->id]);
        $this->ecp->forceFill(['client_can_see_all' => false])->save();

        // Dev2 sees only the sub-task assigned to them, not the (parent) task itself... they get the key anyway via assignment:
        $this->actingAsMember($this->dev2)->get('/tasks/ECP-1')->assertForbidden();
        $this->actingAsMember($this->dev2)->get('/tasks/ECP-2')->assertOk()->assertInertia(fn ($p) => $p->where('parent', null)->where('breadcrumbs', []));
    }

    public function test_an_epic_shows_progress_by_count_or_points(): void
    {
        $epic = $this->task($this->ecp, $this->tlA, ['type' => 'epic']);
        $this->task($this->ecp, $this->tlA, ['parent_id' => $epic->id, 'status' => 'Done', 'completed_at' => now(), 'story_points' => 3]);
        $this->task($this->ecp, $this->tlA, ['parent_id' => $epic->id, 'status' => 'In Progress', 'story_points' => 1]);
        $this->task($this->ecp, $this->tlA, ['parent_id' => $epic->id, 'status' => 'To Do']);
        $this->task($this->ecp, $this->tlA, ['parent_id' => $epic->id, 'status' => 'Cancelled', 'story_points' => 8]);
        $this->task($this->ecp, $this->tlA, ['parent_id' => $epic->id, 'status' => 'Triage', 'story_points' => 8]);

        $service = app(ProgressService::class);
        $this->assertSame(['done' => 1, 'total' => 3, 'percent' => 33, 'mode' => 'count'], $service->forEpic($epic, $this->ecp));

        $this->ecp->forceFill(['progress_mode' => 'points'])->save();
        $this->assertSame(['done' => 3.0, 'total' => 4.0, 'percent' => 75, 'mode' => 'points'], $service->forEpic($epic, $this->ecp->fresh()));

        $this->actingAsMember($this->tlA)->get('/tasks/ECP-2')->assertInertia(fn ($p) => $p->where('progress.percent', 75));
        $this->actingAsMember($this->tlA)->get('/tasks/ECP-1')->assertInertia(fn ($p) => $p->where('progress', null));
    }

    public function test_an_empty_epic_is_zero_percent(): void
    {
        $epic = $this->task($this->ecp, $this->tlA, ['type' => 'epic']);

        $this->assertSame(0, app(ProgressService::class)->forEpic($epic, $this->ecp)['percent']);
    }

    public function test_the_project_overview_lists_recent_tasks_and_counts(): void
    {
        $this->task($this->ecp, $this->pm);

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}")->assertInertia(fn ($p) => $p
            ->where('taskCount', 2)->has('recentTasks', 2)->where('canCreateTask', true)->where('triageCount', 0)->where('canSeeTrash', true));
        $this->actingAsMember($this->client)->get("/projects/{$this->ecp->id}")->assertInertia(fn ($p) => $p->where('taskCount', 0)->where('triageCount', null));
    }

    public function test_the_project_overview_shows_the_triage_count_to_triagers(): void
    {
        $this->task($this->ecp, $this->client, ['status' => 'Triage']);

        $this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}")->assertInertia(fn ($p) => $p->where('triageCount', 1));
    }

    public function test_the_default_matrix_keeps_reopen_for_leads_and_stakeholders(): void
    {
        $perms = app(PermissionService::class);

        $this->assertFalse($perms->can($this->dev1, 'task.reopen'));
        $this->assertTrue($perms->can($this->tlA, 'task.reopen'));
        $this->assertTrue($perms->can($this->client, 'task.reopen'));
        $this->assertTrue($perms->can($this->stakeholder, 'task.reopen'));
    }
}
