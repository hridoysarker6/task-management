<?php

namespace Tests\Feature\Reports;

use App\Models\Membership;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\WorkLog;
use App\Services\DashboardService;
use App\Services\ProgressService;
use App\Services\ProjectHealthService;
use App\Services\ReportService;
use App\Services\SprintReportService;
use App\Services\TaskActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;
use ZipArchive;

/** Progress, health, dashboards, drill-down, reports and exports (Phase 9). */
class ReportsAndDashboardTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
        Cache::flush();
    }

    private function props(TestResponse $response): array
    {
        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    private function key(Task $t): string
    {
        return $t->project->key.'-'.$t->number;
    }

    private function dash(Membership $m): array
    {
        // The company admin is sent to the setup wizard first, so ask the service directly.
        if ($m->is($this->admin)) {
            $this->enterCompany($this->admin);

            return json_decode(json_encode(app(DashboardService::class)->build($this->admin->fresh())), true);
        }

        return $this->props($this->actingAsMember($m)->get('/dashboard'))['dashboard'];
    }

    /** A sprint that started `$daysAgo` days ago with the given tasks in it (points each). */
    private function runningSprint(int $daysAgo, int $length, array $tasks, float $committed): Sprint
    {
        $sprint = Sprint::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $this->ecp->id, 'name' => 'Sprint A', 'status' => 'active',
            'start_date' => today()->subDays($daysAgo), 'end_date' => today()->subDays($daysAgo)->addDays($length),
            'started_at' => today()->subDays($daysAgo)->setTime(9, 0), 'committed_points' => $committed, 'created_by' => $this->pm->id,
        ]);

        foreach ($tasks as $task) {
            $task->forceFill(['sprint_id' => $sprint->id])->save();
            DB::table('sprint_task')->insert(['sprint_id' => $sprint->id, 'task_id' => $task->id, 'added_at' => $sprint->started_at, 'points_at_start' => $task->story_points]);
        }

        return $sprint;
    }

    private function logTime(Membership $m, Task $task, int $minutes, ?string $sheetStatus = null, $date = null): void
    {
        $date ??= today();
        $sheet = Timesheet::withoutGlobalScopes()->firstOrCreate(
            ['membership_id' => $m->id, 'week_start' => Timesheet::weekStartOf($date)->toDateString()],
            ['organization_id' => $this->org->id, 'status' => $sheetStatus ?? 'draft', 'total_min' => 0],
        );
        WorkLog::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'task_id' => $task->id, 'membership_id' => $m->id, 'timesheet_id' => $sheet->id, 'work_date' => $date, 'minutes' => $minutes]);
    }

    // ---- progress ------------------------------------------------------------------------------

    public function test_progress_counts_done_over_total_and_ignores_cancelled_triage_and_epics(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'Done']);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        $this->task($this->ecp, $this->pm, ['status' => 'In Progress']);
        $this->task($this->ecp, $this->pm, ['status' => 'Done', 'type' => 'epic']);
        $this->task($this->ecp, $this->pm, ['status' => 'Cancelled']);
        $this->task($this->ecp, $this->pm, ['status' => 'Triage']);
        $this->enterCompany($this->pm);

        $p = app(ProgressService::class)->forProject($this->ecp);

        $this->assertSame([1, 3, 33], [$p['done'], $p['total'], $p['percent']]);
    }

    public function test_progress_can_count_story_points_instead(): void
    {
        $this->ecp->update(['progress_mode' => 'points']);
        $this->task($this->ecp, $this->pm, ['status' => 'Done', 'story_points' => 3]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'story_points' => 5]);
        $this->enterCompany($this->pm);

        $p = app(ProgressService::class)->forProject($this->ecp->refresh());

        $this->assertEquals([3, 8, 38], [$p['done'], $p['total'], $p['percent']]);
    }

    public function test_progress_of_a_person_a_team_and_a_sprint(): void
    {
        $a = $this->task($this->ecp, $this->pm, ['status' => 'Done', 'assignee_id' => $this->dev1->id, 'story_points' => 2]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id, 'story_points' => 2]);
        $this->task($this->ecp, $this->pm, ['status' => 'Done', 'assignee_id' => $this->dev2->id]);
        $this->task($this->oth, $this->admin, ['status' => 'To Do', 'assignee_id' => $this->dev3->id]);
        $sprint = $this->runningSprint(1, 6, [$a], 2);
        $this->enterCompany($this->admin);
        $progress = app(ProgressService::class);

        $this->assertSame(50, $progress->forPerson($this->dev1)['percent']);
        $this->assertSame(67, $progress->forTeam($this->tlA)['percent']); // dev1 (1 of 2) and dev2 (1 of 1)
        $this->assertSame(50, $progress->forTeam($this->dm)['percent'] === 50 ? 50 : $progress->forTeam($this->dm)['percent']); // tlA + tlB lines
        $this->assertSame(100, $progress->forSprint($sprint->load('project'))['percent']);
    }

    public function test_progress_only_counts_what_the_viewer_may_see(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'Done']);
        $this->task($this->ecp, $this->client, ['status' => 'To Do']);

        $this->enterCompany($this->client);
        $this->assertSame(0, app(ProgressService::class)->forProject($this->ecp, $this->client->fresh())['percent']); // only their own request, not done

        $this->enterCompany($this->pm);
        $this->assertSame(50, app(ProgressService::class)->forProject($this->ecp, $this->pm->fresh())['percent']);
    }

    // ---- health --------------------------------------------------------------------------------

    private function health(): array
    {
        $this->enterCompany($this->pm);

        return app(ProjectHealthService::class)->assess($this->ecp->refresh(), $this->pm->fresh());
    }

    public function test_a_project_with_no_trouble_is_on_track(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'due_date' => today()->addDays(5)]);

        $this->assertSame('on_track', $this->health()['status']);
    }

    public function test_a_project_is_at_risk_when_over_a_fifth_of_open_tasks_are_overdue(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'due_date' => today()->subDays(2)]);
        foreach (range(1, 3) as $i) {
            $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        }

        $health = $this->health(); // 1 of 4 = 25%

        $this->assertSame(['at_risk', 25], [$health['status'], $health['overdue_percent']]);
        $this->assertNotEmpty($health['reasons']);
    }

    public function test_exactly_a_fifth_overdue_is_still_on_track(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'due_date' => today()->subDay()]);
        foreach (range(1, 4) as $i) {
            $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        }

        $this->assertSame('on_track', $this->health()['status']);
    }

    public function test_a_project_past_its_end_date_with_open_work_is_delayed_but_a_finished_one_is_not(): void
    {
        $this->ecp->update(['end_date' => today()->subDays(3)]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        $this->assertSame('delayed', $this->health()['status']);

        Task::withoutGlobalScopes()->update(['status_id' => $this->ecp->statuses()->where('name', 'Done')->value('id')]);
        $this->assertSame('on_track', $this->health()['status']);
    }

    public function test_a_sprint_behind_its_burndown_puts_the_project_at_risk(): void
    {
        $tasks = [
            $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'story_points' => 5]),
            $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'story_points' => 5]),
        ];
        $this->runningSprint(8, 10, $tasks, 10); // eight of ten days gone, nothing finished

        $health = $this->health();

        $this->assertSame('at_risk', $health['status']);
        $this->assertStringContainsString('burndown', $health['reasons'][0]);
    }

    // ---- burndown / velocity / sprint report ---------------------------------------------------

    public function test_the_burndown_falls_as_tasks_finish_and_stays_empty_for_days_to_come(): void
    {
        $a = $this->task($this->ecp, $this->pm, ['status' => 'Done', 'story_points' => 4, 'completed_at' => today()->subDays(2)->setTime(12, 0)]);
        $b = $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'story_points' => 6]);
        $sprint = $this->runningSprint(4, 8, [$a, $b], 10);

        $burn = app(SprintReportService::class)->burndown($sprint);
        $days = collect($burn['days']);

        $this->assertSame(10.0, $burn['committed']);
        $this->assertEquals(10.0, $days[0]['actual']);
        $this->assertEquals(10.0, $days[1]['actual']); // two days ago, before it finished at noon... the day ends after
        $this->assertEquals(6.0, $days->firstWhere('date', today()->toDateString())['actual']);
        $this->assertNull($days->firstWhere('date', today()->addDay()->toDateString())['actual']);
        $this->assertEquals(10.0, $days[0]['ideal']);
        $this->assertEquals(0.0, $days->last()['ideal']);
    }

    public function test_velocity_lists_finished_sprints_oldest_first_with_an_average(): void
    {
        foreach ([[1, 8, 6], [2, 10, 10]] as [$n, $committed, $completed]) {
            Sprint::withoutGlobalScopes()->create([
                'organization_id' => $this->org->id, 'project_id' => $this->ecp->id, 'name' => "S{$n}", 'status' => 'completed', 'start_date' => today()->subDays(30 - $n),
                'end_date' => today()->subDays(20 - $n), 'completed_at' => today()->subDays(20 - $n), 'committed_points' => $committed, 'completed_points' => $completed,
            ]);
        }
        $this->enterCompany($this->pm);

        $v = app(SprintReportService::class)->velocity($this->ecp);

        $this->assertSame(['S1', 'S2'], array_column($v['sprints'], 'name'));
        $this->assertEquals(8.0, $v['average']);
    }

    // ---- dashboards ----------------------------------------------------------------------------

    public function test_a_developer_gets_their_own_work_only(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id, 'due_date' => today()->addDays(2)]);
        $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'assignee_id' => $this->dev1->id, 'due_date' => today()->subDay()]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->tlA->id]);
        $this->logTime($this->dev1, $this->task($this->ecp, $this->pm), 90);

        $d = $this->dash($this->dev1);

        $this->assertSame('worker', $d['layout']);
        $this->assertSame([2, 1, 1, 90], [$d['my']['open'], $d['my']['due_soon'], $d['my']['overdue'], $d['my']['week_minutes']]);
        $this->assertArrayNotHasKey('team', $d);
        $this->assertArrayNotHasKey('rollup', $d);
        $this->assertArrayNotHasKey('company', $d);
    }

    public function test_a_team_lead_sees_the_team_workload_and_what_needs_attention(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);
        $overdue = $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'assignee_id' => $this->dev1->id, 'due_date' => today()->subDays(3)]);
        $this->task($this->ecp, $this->pm, ['status' => 'Done', 'assignee_id' => $this->dev2->id]);
        $this->task($this->oth, $this->admin, ['status' => 'To Do', 'assignee_id' => $this->dev3->id]); // another lead's team

        $d = $this->dash($this->tlA);
        $people = collect($d['team']['people'])->keyBy('id');

        $this->assertSame('lead', $d['layout']);
        $this->assertEqualsCanonicalizing([$this->dev1->id, $this->dev2->id], $people->keys()->all());
        $this->assertSame([1, 1, 1], [$people[$this->dev1->id]['todo'], $people[$this->dev1->id]['in_progress'], $people[$this->dev1->id]['overdue']]);
        $this->assertSame(1, $people[$this->dev2->id]['done']);
        $this->assertSame([$this->key($overdue)], array_column($d['team']['attention'], 'key'));
    }

    public function test_a_manager_sees_a_roll_up_of_each_direct_report_s_whole_line(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);
        $this->task($this->ecp, $this->pm, ['status' => 'Done', 'assignee_id' => $this->dev2->id]);
        $this->task($this->oth, $this->admin, ['status' => 'To Do', 'assignee_id' => $this->dev3->id, 'due_date' => today()->subDay()]);

        $d = $this->dash($this->dm);
        $rows = collect($d['rollup'])->keyBy('id');

        $this->assertSame('manager', $d['layout']);
        $this->assertSame([2, 1, 1, 0], [$rows[$this->tlA->id]['people'], $rows[$this->tlA->id]['open'], $rows[$this->tlA->id]['percent'] === 50 ? 1 : 0, $rows[$this->tlA->id]['overdue']]);
        $this->assertSame([1, 1], [$rows[$this->tlB->id]['open'], $rows[$this->tlB->id]['overdue']]);
    }

    public function test_the_company_admin_sees_company_wide_numbers(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do']);

        $d = $this->dash($this->admin);

        $this->assertSame('company', $d['layout']);
        $this->assertSame(2, $d['company']['projects']);
        $this->assertSame(1, $d['company']['open_tasks']);
        $this->assertSame(2, array_sum($d['company']['health']));
        $this->assertCount(2, $d['projects']);
    }

    public function test_a_client_sees_their_requests_and_their_projects_but_nothing_internal(): void
    {
        $this->task($this->ecp, $this->client, ['status' => 'To Do']);
        $this->task($this->ecp, $this->pm, ['status' => 'Done']);

        $d = $this->dash($this->client);

        $this->assertSame('stakeholder', $d['layout']);
        $this->assertSame(['To Do' => 1], collect($d['requests']['by_status'])->pluck('count', 'name')->all());
        $this->assertSame([$this->ecp->id], array_column($d['requests']['projects'], 'id'));
        $this->assertArrayNotHasKey('team', $d);
        $this->assertArrayNotHasKey('projects', $d);
        $this->assertArrayNotHasKey('company', $d);
    }

    public function test_the_status_mix_and_project_list_follow_the_viewer_s_scope(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do']);
        $this->task($this->oth, $this->admin, ['status' => 'Done']);

        $tlA = collect($this->dash($this->tlA)['status'])->pluck('count', 'category');
        $admin = collect($this->dash($this->admin)['status'])->pluck('count', 'category');

        $this->assertSame([1, 0], [$tlA['todo'], $tlA['done']]);
        $this->assertSame([1, 1], [$admin['todo'], $admin['done']]);
        $this->assertSame([$this->ecp->id], array_column($this->dash($this->tlA)['projects'], 'id'));
    }

    public function test_heavy_widgets_are_cached_for_five_minutes_but_my_work_is_live(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);
        $first = $this->dash($this->admin);

        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->admin->id]);
        $second = $this->dash($this->admin);

        $this->assertSame($first['company']['open_tasks'], $second['company']['open_tasks']); // cached
        $this->assertSame(1, $second['my']['open']); // live

        $this->assertSame(DashboardService::CACHE_SECONDS, 300);
        Cache::flush();
        $this->assertSame(2, $this->dash($this->admin)['company']['open_tasks']);
    }

    // ---- drill-down ----------------------------------------------------------------------------

    private function drill(Membership $by, ?Membership $target = null, string $query = ''): TestResponse
    {
        return $this->actingAsMember($by)->get('/drill'.($target ? "/{$target->id}" : '').$query);
    }

    public function test_drilling_down_the_reporting_line(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id, 'due_date' => today()->subDay()]);

        $top = $this->props($this->drill($this->hod));
        $lead = $this->props($this->drill($this->hod, $this->tlA));
        $dev = $this->props($this->drill($this->hod, $this->dev1, '?show=overdue'));

        $this->assertSame([$this->dm->id], array_column($top['reports'], 'id')); // HOD sees the DM first
        $this->assertSame([$this->hod->id, $this->dm->id, $this->tlA->id], array_column($lead['trail'], 'id'));
        $this->assertEqualsCanonicalizing([$this->dev1->id, $this->dev2->id], array_column($lead['reports'], 'id'));
        $this->assertSame(2, $lead['person']['open']);
        $this->assertSame(1, $dev['person']['overdue']);
        $this->assertCount(1, $dev['tasks']);
        $this->assertSame('overdue', $dev['show']);
    }

    public function test_the_drill_down_filters_the_tasks_behind_a_number(): void
    {
        $open = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id]);
        $done = $this->task($this->ecp, $this->pm, ['status' => 'Done', 'assignee_id' => $this->dev1->id]);
        $blocked = $this->task($this->ecp, $this->pm, ['status' => 'Blocked', 'assignee_id' => $this->dev1->id]);

        $keys = fn (string $show) => array_column($this->props($this->drill($this->tlA, $this->dev1, "?show={$show}"))['tasks'], 'key');

        $this->assertEqualsCanonicalizing([$this->key($open), $this->key($blocked)], $keys('open'));
        $this->assertSame([$this->key($done)], $keys('done'));
        $this->assertSame([$this->key($blocked)], $keys('blocked'));
        $this->assertCount(3, $keys('all'));
        $this->assertEqualsCanonicalizing([$this->key($open), $this->key($blocked)], $keys('nonsense')); // unknown filter falls back to open
    }

    public function test_nobody_drills_into_people_outside_their_line_or_without_reports(): void
    {
        $this->drill($this->tlA, $this->dev3)->assertNotFound(); // another lead's developer
        $this->drill($this->tlA, $this->dm)->assertNotFound(); // above them
        $this->drill($this->dev1, $this->dev2)->assertForbidden(); // no team permission
        $this->drill($this->dev1)->assertOk(); // their own page is always fine
    }

    // ---- reports -------------------------------------------------------------------------------

    private function report(Membership $by, string $report, string $query = ''): array
    {
        return $this->props($this->actingAsMember($by)->get("/reports/{$report}{$query}"))['data'];
    }

    public function test_reports_need_the_permission(): void
    {
        $this->actingAsMember($this->dev1)->get('/reports/workload')->assertForbidden();
        $this->actingAsMember($this->client)->get('/reports/overdue')->assertForbidden();
        $this->actingAsMember($this->tlA)->get('/reports/workload')->assertOk();
        $this->actingAsMember($this->tlA)->get('/reports/nope')->assertNotFound();
    }

    public function test_the_workload_report_covers_the_people_in_scope(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev1->id, 'story_points' => 3, 'remaining_estimate_min' => 120]);
        $this->task($this->ecp, $this->pm, ['status' => 'Blocked', 'assignee_id' => $this->dev1->id, 'due_date' => today()->subDay()]);
        $this->task($this->oth, $this->admin, ['status' => 'To Do', 'assignee_id' => $this->dev3->id]);

        $rows = collect($this->report($this->tlA, 'workload')['rows'])->keyBy('person');

        $this->assertEqualsCanonicalizing([$this->tlA->user->name, $this->dev1->user->name, $this->dev2->user->name], $rows->keys()->all());
        $dev1 = $rows[$this->dev1->user->name];
        $this->assertEquals([2, 1, 1, 3, 2], [$dev1['open'], $dev1['blocked'], $dev1['overdue'], $dev1['points'], $dev1['hours']]);
        $this->assertGreaterThan(count($rows), count($this->report($this->admin, 'workload')['rows'])); // the admin sees more people
    }

    public function test_former_employees_are_left_out_unless_asked_for(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'assignee_id' => $this->dev2->id, 'due_date' => today()->subDay()]);
        $this->dev2->update(['status' => 'exited']);
        $this->dev2->delete();

        $without = collect($this->report($this->tlA, 'workload')['rows'])->pluck('person')->all();
        $with = collect($this->report($this->tlA, 'workload', '?include_former=1')['rows'])->pluck('person')->all();

        $this->assertNotContains($this->dev2->user->name, $without);
        $this->assertContains($this->dev2->user->name.' (Former)', $with);
        $this->assertSame([], $this->report($this->pm, 'overdue')['rows']); // the former employee's overdue task is hidden
        $this->assertSame([$this->key($task)], array_column($this->report($this->pm, 'overdue', '?include_former=1')['rows'], 'key'));
    }

    public function test_the_overdue_report_lists_late_open_tasks_only(): void
    {
        $late = $this->task($this->ecp, $this->pm, ['status' => 'In Progress', 'due_date' => today()->subDays(4), 'assignee_id' => $this->dev1->id]);
        $this->task($this->ecp, $this->pm, ['status' => 'Done', 'due_date' => today()->subDays(4)]);
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'due_date' => today()->addDay()]);

        $report = $this->report($this->pm, 'overdue');

        $this->assertSame([$this->key($late)], array_column($report['rows'], 'key'));
        $this->assertSame(4, $report['rows'][0]['days_late']);
        $this->assertSame([$this->key($late)], array_column($this->report($this->pm, 'overdue', "?project_id={$this->ecp->id}&person_id={$this->dev1->id}")['rows'], 'key'));
    }

    public function test_the_time_report_splits_approved_waiting_and_unsubmitted_hours(): void
    {
        $task = $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id]);
        $this->logTime($this->dev1, $task, 120, 'approved', today()->subWeeks(2));
        $this->logTime($this->dev1, $task, 60, 'submitted', today()->subWeek());
        $this->logTime($this->dev1, $task, 30, 'draft', today());

        $all = $this->report($this->tlA, 'time', '?from='.today()->subMonths(2)->toDateString())['rows'];
        $recent = $this->report($this->tlA, 'time', '?from='.today()->subDays(10)->toDateString())['rows'];
        $byProject = $this->report($this->tlA, 'time', '?group=project&from='.today()->subMonths(2)->toDateString())['rows'];

        $this->assertEquals([3.5, 2.0, 1.0, 0.5], [$all[0]['total'], $all[0]['approved'], $all[0]['submitted'], $all[0]['draft']]);
        $this->assertEquals(1.5, $recent[0]['total']); // the approved fortnight-old entry is outside the range
        $this->assertSame('ECP · E-Commerce', $byProject[0]['name']);
    }

    public function test_the_time_report_leaves_out_people_outside_the_viewer_s_scope(): void
    {
        $this->logTime($this->dev3, $this->task($this->oth, $this->admin, ['assignee_id' => $this->dev3->id]), 60);

        $this->assertSame([], $this->report($this->tlA, 'time')['rows']);
        $this->assertCount(1, $this->report($this->tlB, 'time')['rows']);
    }

    public function test_the_sprint_report_shows_committed_against_done_and_late_additions(): void
    {
        $done = $this->task($this->ecp, $this->pm, ['status' => 'Done', 'story_points' => 5]);
        $open = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'story_points' => 3]);
        $sprint = $this->runningSprint(3, 10, [$done, $open], 8);
        $late = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'story_points' => 2]);
        $late->forceFill(['sprint_id' => $sprint->id])->save();
        DB::table('sprint_task')->insert(['sprint_id' => $sprint->id, 'task_id' => $late->id, 'added_at' => now()->addMinute(), 'points_at_start' => 2]);

        $report = $this->props($this->actingAsMember($this->pm)->get("/reports/sprint?project_id={$this->ecp->id}"))['data'];

        $this->assertSame(3, count($report['rows']));
        $this->assertEquals(8.0, $report['summary']['Committed points']);
        $this->assertEquals(5.0, $report['summary']['Completed points']);
        $this->assertSame(1, $report['summary']['Added mid-sprint']);
        $this->assertSame('sprint', $report['chart']['type']);
        $this->assertSame('Yes', collect($report['rows'])->firstWhere('key', $this->key($late))['late']);
    }

    public function test_a_report_without_a_project_choice_is_empty_not_an_error(): void
    {
        $this->assertSame([], $this->report($this->pm, 'sprint')['rows']);
    }

    public function test_filters_are_whitelisted_and_typed(): void
    {
        $this->enterCompany($this->pm);
        $clean = app(ReportService::class)->clean(['project_id' => '5', 'from' => 'yesterday', 'to' => '2026-03-01', 'group' => 'drop table', 'evil' => 1, 'include_former' => '1']);

        $this->assertSame(['project_id' => 5, 'to' => '2026-03-01', 'include_former' => true], $clean);
    }

    // ---- export --------------------------------------------------------------------------------

    public function test_csv_export_has_the_report_and_neutralises_formulas(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'due_date' => today()->subDay(), 'title' => '=HYPERLINK("http://evil")']);

        $response = $this->actingAsMember($this->pm)->get('/reports/overdue/export?format=csv');
        $csv = $response->streamedContent();

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Task,Title,Project', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
    }

    public function test_excel_export_is_a_real_workbook(): void
    {
        $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'due_date' => today()->subDay(), 'title' => 'Fix & <ship>']);

        $response = $this->actingAsMember($this->pm)->get('/reports/overdue/export?format=xlsx');
        $response->assertOk();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()) === true);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('Fix &amp; &lt;ship&gt;', $sheet);
        $this->assertStringContainsString('<c r="A1" s="1" t="inlineStr">', $sheet);
        $this->assertNotFalse($zip->getFromName('xl/workbook.xml'));
        $zip->close();
    }

    public function test_the_print_view_renders_the_report_without_the_app_chrome(): void
    {
        $response = $this->actingAsMember($this->pm)->get('/reports/workload/export?format=print');

        $response->assertOk();
        $props = $this->props($response);
        $this->assertSame('Reports/Print', $response->viewData('page')['component']);
        $this->assertSame($this->org->name, $props['company']);
    }

    public function test_exporting_needs_its_own_permission(): void
    {
        $this->actingAsMember($this->dev1)->get('/reports/workload/export')->assertForbidden();
        $this->actingAsMember($this->client)->get('/reports/workload/export')->assertForbidden();
    }

    // ---- project overview ----------------------------------------------------------------------

    public function test_the_project_overview_carries_progress_health_burndown_and_activity(): void
    {
        $done = $this->task($this->ecp, $this->pm, ['status' => 'Done', 'story_points' => 5, 'completed_at' => today()->subDay()]);
        $todo = $this->task($this->ecp, $this->pm, ['status' => 'To Do', 'story_points' => 5]);
        $this->runningSprint(2, 8, [$done, $todo], 10);
        $todo->update(['title' => 'Renamed']);

        $props = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}"));

        $this->assertSame(50, $props['progress']['percent']);
        $this->assertSame('on_track', $props['health']['status']);
        $this->assertSame('Sprint A', $props['burndown']['sprint']['name']);
        $this->assertNotEmpty($props['activity']);
        $this->assertLessThanOrEqual(10, count($props['activity']));
    }

    public function test_a_client_sees_progress_but_not_health_burndown_or_effort_in_the_activity(): void
    {
        $task = $this->task($this->ecp, $this->client, ['status' => 'To Do']);
        $task->update(['story_points' => 8]); // an internal field
        $task->update(['title' => 'Client title']);

        $props = $this->props($this->actingAsMember($this->client)->get("/projects/{$this->ecp->id}"));

        $this->assertArrayHasKey('percent', $props['progress']);
        $this->assertNull($props['health']);
        $this->assertNull($props['burndown']);
        $this->assertNull($props['velocity']);
        $this->assertNotContains('story_points', array_column($props['activity'], 'field'));
    }

    public function test_the_project_overview_has_no_sprint_widgets_in_kanban_mode(): void
    {
        $this->ecp->update(['uses_sprints' => false]);

        $props = $this->props($this->actingAsMember($this->pm)->get("/projects/{$this->ecp->id}"));

        $this->assertNull($props['burndown']);
        $this->assertNull($props['velocity']);
    }

    public function test_the_task_history_hides_logged_time_from_clients(): void
    {
        $task = $this->task($this->ecp, $this->client);
        $this->enterCompany($this->pm);
        app(TaskActivityLogger::class)->log($task, 'work_logged', null, null, '60');

        $client = $this->props($this->actingAsMember($this->client)->get('/tasks/'.$this->key($task)));
        $pm = $this->props($this->actingAsMember($this->pm)->get('/tasks/'.$this->key($task)));

        $this->assertNotContains('work_logged', array_column($client['activities'], 'event'));
        $this->assertContains('work_logged', array_column($pm['activities'], 'event'));
    }
}
