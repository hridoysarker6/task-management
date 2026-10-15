<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Invitation;
use App\Models\Label;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\SavedFilter;
use App\Models\Scopes\OrganizationScope;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\CommentService;
use App\Services\CompanyProvisioningService;
use App\Services\CompanyReviewService;
use App\Services\EmploymentService;
use App\Services\HierarchyService;
use App\Services\InvitationService;
use App\Services\MemberService;
use App\Services\NotificationPreferenceService;
use App\Services\OffboardingService;
use App\Services\PermissionService;
use App\Services\ProjectService;
use App\Services\RoleManager;
use App\Services\SprintService;
use App\Services\TaskLinkService;
use App\Services\TaskService;
use App\Services\TimesheetService;
use App\Services\TriageService;
use App\Services\WorkLogService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Fills the demo company with data for every feature, so each screen can be tried straight away:
 * requests in triage (open, accepted, rejected), comments with @mentions, links, attachments, three weeks of logged time
 * with timesheets in every state, a returned timesheet, over-estimate tasks, overdue and due-tomorrow work, a delayed
 * and an at-risk project, an archived project, a finished-sprint history, a former employee, a pending invitation,
 * saved filters, notification preferences, notifications in the bell, an audit trail, a second company (the company
 * switcher), and a pending and a suspended company for the System Admin.
 *
 * Run it on demand (never in production):  php artisan db:seed --class=FeatureTestSeeder
 * It builds on DemoSeeder (run first automatically) and does nothing if it already ran. Every login uses the password "password".
 */
class FeatureTestSeeder extends Seeder
{
    private Organization $org;

    private Department $dept;

    /** @var array<string, Membership> */
    private array $m = [];

    /** @var array<string, string> */
    private array $report = [];

    public function run(): void
    {
        // Notifications are delivered at once (they land in the bell) and mail goes nowhere.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        $this->call(DemoSeeder::class);

        $this->org = Organization::where('slug', 'acme-demo')->firstOrFail();

        if (Project::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('key', 'HRP')->exists()) {
            $this->command?->warn('The feature test data already exists; nothing to do.');

            return;
        }

        $this->dept = Department::withoutGlobalScopes()->where('organization_id', $this->org->id)->firstOrFail();
        $this->loadPeople();

        $this->step('Roles, people and permission exceptions', fn () => $this->peopleAndRoles());
        $this->step('Projects (at risk, delayed, archived)', fn () => $this->projects());
        $this->step('Requests and triage', fn () => $this->requests());
        $this->step('Comments, mentions, links, attachments', fn () => $this->collaboration());
        $this->step('Time tracking and timesheets', fn () => $this->time());
        $this->step('Due-tomorrow and overdue reminders', fn () => $this->reminders());
        $this->step('Saved filters and notification settings', fn () => $this->settings());
        $this->step('Invitation and former employee', fn () => $this->hrEvents());
        $this->step('Second company and platform companies', fn () => $this->otherCompanies());

        Auth::logout();
        $this->summary();
    }

    // --- people ----------------------------------------------------------------------------------

    private function loadPeople(): void
    {
        $emails = [
            'admin' => 'ada.admin', 'hod' => 'hasan.rahman', 'dgm' => 'dina.chowdhury', 'agm' => 'arif.hossain', 'dm' => 'dilruba.karim',
            'tlA' => 'tanvir.ahmed', 'tlB' => 'tania.sultana', 'd1' => 'rafi.islam', 'd2' => 'nusrat.jahan', 'd3' => 'imran.khan',
            'd4' => 'sadia.afrin', 'd5' => 'kamal.uddin', 'd6' => 'mitu.das', 'pm' => 'priya.nair', 'sh' => 'sam.okafor', 'client' => 'chris.walker',
        ];

        foreach ($emails as $key => $local) {
            $user = User::where('email', "{$local}@acme-demo.test")->firstOrFail();
            $this->m[$key] = Membership::withoutGlobalScopes()->with(['user', 'role'])->where('organization_id', $this->org->id)->where('user_id', $user->id)->firstOrFail();
        }
    }

    /** Act as someone in the company: the person the audit trail, notifications and permission checks see. */
    private function as(string $key): Membership
    {
        $member = $this->m[$key];
        Auth::setUser($member->user);
        app(CurrentOrganization::class)->set($this->org, $member);

        return $member;
    }

    private function addPerson(string $key, string $roleSlug, string $name, array $extra = []): Membership
    {
        $role = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', $roleSlug)->firstOrFail();
        $user = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@acme-demo.test', 'password' => Hash::make('password'), 'email_verified_at' => now()]);

        $member = Membership::withoutGlobalScopes()->create($extra + [
            'organization_id' => $this->org->id, 'user_id' => $user->id, 'role_id' => $role->id, 'user_type' => $role->is_external ? 'external' : 'internal',
            'status' => Membership::ACTIVE, 'joined_at' => now()->subMonths(8)->toDateString(), 'department_id' => $this->dept->id,
        ]);
        app(EmploymentService::class)->open($member);
        app(HierarchyService::class)->rebuildSubtree($member);

        return $this->m[$key] = $member->load(['user', 'role']);
    }

    private function peopleAndRoles(): void
    {
        $this->as('admin');

        // A custom role (copied from Developer) and someone who holds it.
        $developer = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'developer')->firstOrFail();
        app(RoleManager::class)->create($this->org->id, ['name' => 'QA Engineer', 'level' => 70, 'data_scope' => 'own', 'description' => 'Tests the product'], $developer);
        $qa = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('name', 'QA Engineer')->firstOrFail();
        $this->addPerson('qa', $qa->slug, 'Farah Qureshi', ['reports_to_id' => $this->m['tlB']->id]);

        // A person who is about to leave: assigned work, then offboarded further down.
        $this->addPerson('old', 'developer', 'Omar Oldhand', ['reports_to_id' => $this->m['tlA']->id]);

        // Personal permission exception (shows in the audit log and on the person's page).
        app(PermissionService::class)->override($this->m['d6'], 'report.view', 'grant', $this->m['admin']);

        // A change of designation and manager, recorded in the audit trail.
        app(MemberService::class)->update($this->m['d2'], $this->m['admin'], ['designation' => 'Senior Engineer']);
    }

    // --- projects --------------------------------------------------------------------------------

    private function projects(): void
    {
        $this->as('admin');
        $projects = app(ProjectService::class);

        // HR Portal: Scrum, triage for all stakeholders, a third of its open work overdue -> "At risk".
        $hrp = $projects->create($this->org, $this->m['admin'], [
            'name' => 'HR Portal', 'key' => 'HRP', 'description' => 'Leave, payroll queries and onboarding for staff.', 'department_id' => $this->dept->id,
            'manager_membership_id' => $this->m['pm']->id, 'start_date' => today()->subDays(30)->toDateString(), 'end_date' => today()->addDays(45)->toDateString(),
        ]);
        $projects->updateSettings($hrp, ['uses_sprints' => true, 'triage_mode' => 'all_stakeholders']);
        foreach (['tlB', 'd4', 'd5', 'd6', 'qa'] as $k) {
            $projects->addMember($hrp, $this->m[$k], 'member', $this->m['admin']);
        }
        $projects->addMember($hrp, $this->m['sh'], 'stakeholder', $this->m['admin']);

        // Intranet Revamp: Kanban, end date already passed with open work -> "Delayed".
        $int = $projects->create($this->org, $this->m['admin'], [
            'name' => 'Intranet Revamp', 'key' => 'INT', 'description' => 'New staff intranet, behind schedule.', 'department_id' => $this->dept->id,
            'manager_membership_id' => $this->m['pm']->id, 'start_date' => today()->subDays(90)->toDateString(), 'end_date' => today()->subDays(10)->toDateString(),
        ]);
        $projects->updateSettings($int, ['uses_sprints' => false]);
        foreach (['tlA', 'd1', 'd2', 'd3'] as $k) {
            $projects->addMember($int, $this->m[$k], 'member', $this->m['admin']);
        }

        // Legacy CRM: finished and archived (read-only).
        $crm = $projects->create($this->org, $this->m['admin'], ['name' => 'Legacy CRM', 'key' => 'CRM', 'department_id' => $this->dept->id, 'manager_membership_id' => $this->m['pm']->id]);
        $projects->addMember($crm, $this->m['d1'], 'member', $this->m['admin']);

        $labels = collect(['Bug' => '#ef4444', 'Design' => '#ec4899', 'Docs' => '#06b6d4'])->map(fn ($c, $n) => Label::create(['project_id' => $hrp->id, 'name' => $n, 'color' => $c]));

        // HRP: a mix of statuses; three overdue open tasks of eight.
        $rows = [
            ['Leave request form', 'story', 'In Progress', 'd4', 5, 3, -4], ['Approval workflow', 'task', 'In Progress', 'd5', 8, 2, -2],
            ['Payslip download', 'task', 'To Do', 'd6', 3, 3, -1], ['Holiday calendar', 'task', 'To Do', 'd4', 2, 3, 6],
            ['Employee directory', 'story', 'In Review', 'd5', 5, 4, 3], ['Fix timezone bug', 'bug', 'Blocked', 'd6', 2, 5, 2],
            ['Onboarding checklist', 'story', 'Done', 'd4', 5, 3, null], ['Policy library', 'task', 'Done', 'd5', 3, 2, null],
            ['Manager dashboard', 'story', 'Backlog', 'qa', 8, 3, null], ['Test plan', 'task', 'Backlog', 'qa', 3, 3, null],
            ['Role-based menus', 'task', 'Backlog', null, 5, 4, null], ['Accessibility audit', 'task', 'Backlog', null, 2, 2, null],
        ];
        $hrpTasks = [];
        foreach ($rows as $i => [$title, $type, $status, $who, $points, $prio, $due]) {
            $this->as('pm');
            $task = app(TaskService::class)->create($hrp, $this->m['pm'], [
                'title' => $title, 'type' => $type, 'priority' => $prio, 'story_points' => $points, 'original_estimate_min' => $points * 120,
                'assignee_id' => $who ? $this->m[$who]->id : null, 'due_date' => $due === null ? null : today()->addDays($due)->toDateString(),
                'labels' => [$labels->values()[$i % 3]->id], 'description' => "<p>{$title}: demo task for the HR Portal.</p>",
            ]);
            $this->move($task, $status);
            $hrpTasks[] = $task;
        }
        // A task whose due date passed days ago while it is still open, created with a past date directly.
        Task::withoutGlobalScope(OrganizationScope::class)->whereKey($hrpTasks[0]->id)->update(['start_date' => today()->subDays(12)->toDateString()]);

        // HRP sprints: one finished (history for velocity), one running.
        $sprints = app(SprintService::class);
        $done = $this->sprint($hrp, 'HR Sprint 1', 'Foundations', -28, -15, Sprint::COMPLETED, 21, 18);
        $run = $sprints->create($hrp, $this->m['pm'], ['name' => 'HR Sprint 2', 'goal' => 'Leave and approvals', 'start_date' => today()->subDays(7)->toDateString(), 'end_date' => today()->addDays(6)->toDateString()]);
        foreach (array_slice($hrpTasks, 0, 8) as $t) {
            $sprints->addTask($run, $t);
        }
        $sprints->start($run);
        $this->sprint($hrp, 'HR Sprint 3', 'Dashboards', 7, 20, Sprint::PLANNED);
        unset($done);

        // INT: Kanban, open work.
        foreach ([['Rebuild the home page', 'story', 'In Progress', 'd1'], ['Staff news feed', 'task', 'To Do', 'd2'], ['Search', 'task', 'Blocked', 'd3'], ['Migrate documents', 'task', 'To Do', null], ['Old links audit', 'bug', 'Done', 'd1']] as [$title, $type, $status, $who]) {
            $this->as('pm');
            $t = app(TaskService::class)->create($int, $this->m['pm'], ['title' => $title, 'type' => $type, 'priority' => 3, 'story_points' => 3, 'assignee_id' => $who ? $this->m[$who]->id : null]);
            $this->move($t, $status);
        }

        // CRM: one finished task, then complete and archive.
        $t = app(TaskService::class)->create($crm, $this->m['pm'], ['title' => 'Export customers', 'type' => 'task', 'assignee_id' => $this->m['d1']->id]);
        $this->move($t, 'Done');
        $projects->changeStatus($crm->refresh(), Project::COMPLETED);
        $projects->changeStatus($crm->refresh(), Project::ARCHIVED);

        // A second finished ECP sprint so velocity has a trend.
        $ecp = Project::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('key', 'ECP')->firstOrFail();
        $this->sprint($ecp, 'Sprint 0', 'Project setup', -42, -29, Sprint::COMPLETED, 30, 27);
    }

    private function sprint(Project $project, string $name, string $goal, int $start, int $end, string $status, ?float $committed = null, ?float $completed = null): Sprint
    {
        return Sprint::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $project->id, 'name' => $name, 'goal' => $goal,
            'start_date' => today()->addDays($start), 'end_date' => today()->addDays($end), 'status' => $status,
            'started_at' => $status === Sprint::PLANNED ? null : today()->addDays($start)->setTime(9, 0),
            'completed_at' => $status === Sprint::COMPLETED ? today()->addDays($end)->setTime(17, 0) : null,
            'committed_points' => $committed, 'completed_points' => $completed, 'created_by' => $this->m['pm']->id,
        ]);
    }

    private function move(Task $task, string $statusName): void
    {
        $status = ProjectStatus::where('project_id', $task->project_id)->where('name', $statusName)->firstOrFail();
        app(TaskService::class)->move($task, $status);
    }

    private function task(string $key): Task
    {
        return Task::findByKey($key) ?? throw new \RuntimeException("Task {$key} not found");
    }

    // --- requests and triage -----------------------------------------------------------------------

    private function requests(): void
    {
        $ecp = Project::where('key', 'ECP')->firstOrFail();
        $hrp = Project::where('key', 'HRP')->firstOrFail();
        $tasks = app(TaskService::class);
        $triage = app(TriageService::class);

        $this->as('client');
        $open = $tasks->create($ecp, $this->m['client'], ['title' => 'Can we show prices in two currencies?', 'type' => 'story', 'priority' => 3, 'description' => '<p>Our buyers in Europe would like EUR next to USD.</p>']);
        $toAccept = $tasks->create($ecp, $this->m['client'], ['title' => 'Add an invoice PDF download', 'type' => 'story', 'priority' => 4]);
        $toReject = $tasks->create($ecp, $this->m['client'], ['title' => 'Rebuild the whole site in another framework', 'type' => 'task', 'priority' => 2]);

        $this->as('sh');
        $tasks->create($hrp, $this->m['sh'], ['title' => 'Need a way to export leave balances to Excel', 'type' => 'story', 'priority' => 3]);

        $this->as('pm');
        $triage->accept($toAccept, $this->m['pm'], ['type' => 'story', 'priority' => 4, 'assignee_id' => $this->m['tlA']->id]);
        $triage->reject($toReject, $this->m['pm'], 'Out of scope for this release; we will revisit next quarter.');
        $triage->askForInfo($open, $this->m['pm'], 'Which currencies do you need, and should prices convert live?');
    }

    // --- comments, links, attachments --------------------------------------------------------------

    private function collaboration(): void
    {
        $comments = app(CommentService::class);
        $tasks = app(TaskService::class);
        $mention = fn (string $k) => "@[{$this->m[$k]->user->name}](m:{$this->m[$k]->id})";

        $t = $this->task('ECP-12');
        $this->as('pm');
        $c = $comments->add($t, $this->m['pm'], "{$mention('d3')} can you take a look at the filter performance before Friday?");
        $this->as('d3');
        $comments->add($t, $this->m['d3'], 'On it. The slow part is the label join; I will add an index and measure.');
        $this->as('tlA');
        $comments->add($t, $this->m['tlA'], 'Internal note: do not promise the client a date until the benchmark is done.', true);
        $this->as('pm');
        $comments->add($t, $this->m['pm'], "Thanks {$mention('tlA')}. Agreed.");
        $comments->update($c, $this->m['pm'], "{$mention('d3')} can you take a look at the filter performance before Friday? It is blocking the demo.");

        $this->as('d1');
        $comments->add($this->task('ECP-13'), $this->m['d1'], 'First prototype is up on the staging site.');
        $this->as('client');
        $comments->add($this->task('ECP-4'), $this->m['client'], 'The guest checkout works well for us. Thank you!');

        foreach (['ECP-12' => ['sh', 'client'], 'ECP-13' => ['sh'], 'ECP-20' => ['tlB']] as $key => $watchers) {
            foreach ($watchers as $w) {
                $tasks->watch($this->task($key), $this->m[$w]);
            }
        }

        // Dependencies between tasks.
        $links = app(TaskLinkService::class);
        $this->as('pm');
        $links->link($this->task('ECP-20'), $this->task('ECP-21'), 'blocks', $this->m['pm']);
        $links->link($this->task('ECP-13'), $this->task('ECP-12'), 'relates_to', $this->m['pm']);
        $links->link($this->task('ECP-18'), $this->task('ECP-19'), 'relates_to', $this->m['pm']);

        // Sub-tasks on one story.
        $this->as('tlA');
        $parent = $this->task('ECP-12');
        foreach (['Write the query', 'Add the index', 'Measure before and after'] as $i => $title) {
            $sub = $tasks->create(Project::findOrFail($parent->project_id), $this->m['tlA'], ['title' => $title, 'type' => 'subtask', 'parent_id' => $parent->id, 'assignee_id' => $this->m['d3']->id]);
            $this->move($sub, $i === 0 ? 'Done' : 'In Progress');
        }

        // Attachments: a note and a CSV.
        $this->as('d1');
        $attachments = app(AttachmentService::class);
        foreach (['review-notes.txt' => "Review notes\n- Checkout works\n- Zoom needs a larger image\n", 'timings.csv' => "step,ms\nsearch,420\nfilter,980\n"] as $name => $body) {
            $path = tempnam(sys_get_temp_dir(), 'seed');
            file_put_contents($path, $body);
            $attachments->add($this->task('ECP-13'), $this->m['d1'], new UploadedFile($path, $name, 'text/plain', null, true));
        }
    }

    // --- time ------------------------------------------------------------------------------------

    private function time(): void
    {
        $logs = app(WorkLogService::class);
        $sheets = app(TimesheetService::class);

        // Three weeks of work for the developers on the tasks assigned to them.
        $minutesFor = [60, 120, 90, 180, 150];
        foreach (['d1', 'd2', 'd3', 'd4', 'd5', 'd6', 'tlA'] as $n => $key) {
            $this->as($key);
            $mine = Task::visibleTo($this->m[$key])->where('assignee_id', $this->m[$key]->id)->where('type', '!=', 'epic')
                ->whereHas('status', fn ($s) => $s->whereIn('category', ['todo', 'in_progress', 'done']))->orderBy('id')->limit(3)->get();
            if ($mine->isEmpty()) {
                continue;
            }

            foreach (range(20, 1) as $daysAgo) {
                $day = today()->subDays($daysAgo);
                if ($day->isWeekend()) {
                    continue;
                }
                $task = $mine[($daysAgo + $n) % $mine->count()];
                try {
                    $logs->create($task, $this->m[$key], ['work_date' => $day->toDateString(), 'minutes' => $minutesFor[($daysAgo + $n) % 5] + 120, 'note' => ['Pairing and review', 'Implementation', 'Fixing review comments', 'Testing', 'Planning'][($daysAgo + $n) % 5]]);
                } catch (Throwable) {
                    // A task that cannot take time (for example a finished project) is skipped.
                }
            }
        }

        // One task that ran well over its estimate (shows the red bar).
        $this->as('d1');
        $over = $this->task('ECP-13');
        Task::withoutGlobalScope(OrganizationScope::class)->whereKey($over->id)->update(['original_estimate_min' => 60, 'remaining_estimate_min' => 60]);
        $logs->create($over->refresh(), $this->m['d1'], ['work_date' => today()->subDay()->toDateString(), 'minutes' => 240, 'note' => 'Much bigger than expected']);

        // Timesheets in every state. Two weeks ago: approved, waiting, returned; last week: waiting and draft.
        $lastLast = Timesheet::weekStartOf(today()->subWeeks(2));
        $last = Timesheet::weekStartOf(today()->subWeek());

        foreach (['d1' => $lastLast, 'd2' => $lastLast, 'd3' => $lastLast, 'd4' => $last, 'd5' => $last] as $key => $week) {
            $this->as($key);
            try {
                $sheets->submit($this->m[$key], $week);
            } catch (Throwable) {
                // nothing logged that week
            }
        }

        $this->as('tlA');
        $this->review($sheets, 'd1', $lastLast, 'approve');
        $this->review($sheets, 'd3', $lastLast, 'return', 'Tuesday looks too long: please split the review time.');
        $this->as('tlB');
        $this->review($sheets, 'd5', $last, 'approve');
    }

    private function review(TimesheetService $sheets, string $key, Carbon $week, string $action, string $note = ''): void
    {
        $sheet = $sheets->find($this->m[$key], $week);
        if (! $sheet || $sheet->status !== Timesheet::SUBMITTED) {
            return;
        }

        $reviewer = app(CurrentOrganization::class)->membership;
        $action === 'approve' ? $sheets->approve($sheet, $reviewer) : $sheets->returnToOwner($sheet, $reviewer, $note);
    }

    // --- reminders ---------------------------------------------------------------------------------

    private function reminders(): void
    {
        $int = Project::where('key', 'INT')->firstOrFail();
        $this->as('pm');
        $tasks = app(TaskService::class);

        foreach ([['Review the staff news design', 'd1', 1], ['Confirm the migration plan', 'd2', 1], ['Send the weekly status', 'd3', -3], ['Update the search index', 'd1', -6]] as [$title, $who, $offset]) {
            $t = $tasks->create($int, $this->m['pm'], ['title' => $title, 'type' => 'task', 'priority' => 4, 'assignee_id' => $this->m[$who]->id, 'due_date' => today()->addDays($offset)->toDateString()]);
            $this->move($t, 'In Progress');
        }

        Auth::logout();
        app(CurrentOrganization::class)->clear();
        Artisan::call('tasks:send-reminders');
    }

    // --- settings --------------------------------------------------------------------------------

    private function settings(): void
    {
        SavedFilter::create(['membership_id' => $this->m['pm']->id, 'project_id' => Project::where('key', 'ECP')->value('id'), 'name' => 'Open bugs', 'view' => 'list', 'filters' => ['type' => ['bug'], 'priority' => [4, 5]], 'is_shared' => true]);
        SavedFilter::create(['membership_id' => $this->m['d1']->id, 'project_id' => Project::where('key', 'ECP')->value('id'), 'name' => 'My open work', 'view' => 'board', 'filters' => ['mine' => true], 'is_shared' => false]);

        app(NotificationPreferenceService::class)->save($this->m['d1'], [
            'status_changed' => ['app' => true, 'mail' => true],
            'comment' => ['app' => false, 'mail' => false],
        ]);
    }

    // --- HR events ---------------------------------------------------------------------------------

    private function hrEvents(): void
    {
        $this->as('admin');
        $developer = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'developer')->firstOrFail();
        $invitation = app(InvitationService::class)->invite($this->org, $this->m['admin'], [
            'email' => 'new.hire@example.com', 'name' => 'Nadia Newhire', 'role_id' => $developer->id, 'department_id' => $this->dept->id, 'reports_to_id' => $this->m['tlA']->id,
        ], false);
        $this->report['invite'] = route('invitations.show', $invitation->plainToken);

        // The leaver: give them some work, then let them go (their history stays, shown as "Former").
        $ecp = Project::where('key', 'ECP')->firstOrFail();
        app(\App\Services\ProjectService::class)->addMember($ecp, $this->m['old'], 'member', $this->m['admin']);
        $this->as('pm');
        $t = app(TaskService::class)->create($ecp, $this->m['pm'], ['title' => 'Document the legacy import job', 'type' => 'task', 'priority' => 2, 'assignee_id' => $this->m['old']->id]);
        $this->move($t, 'Done');
        $this->as('old');
        app(WorkLogService::class)->create($t->refresh(), $this->m['old'], ['work_date' => today()->subDays(9)->toDateString(), 'minutes' => 180, 'note' => 'Wrote up the import steps']);

        $this->as('admin');
        app(OffboardingService::class)->offboard($this->m['old'], $this->m['admin'], ['exit_type' => 'resigned', 'left_at' => today()->subDay()->toDateString(), 'exit_reason' => 'Moved to another city']);
    }

    // --- other companies ---------------------------------------------------------------------------

    private function otherCompanies(): void
    {
        Auth::logout();
        app(CurrentOrganization::class)->clear();

        // Globex: a second company. Rafi also works there, so he sees the company switcher.
        $owner = User::create(['name' => 'Gina Globex', 'email' => 'gina@globex-demo.test', 'password' => Hash::make('password'), 'email_verified_at' => now()]);
        $globex = Organization::create([
            'name' => 'Globex Corporation', 'slug' => 'globex-demo', 'industry' => 'Manufacturing', 'size' => '51-200', 'address' => '1 Industrial Way', 'phone' => '+880 1800 000000',
            'email' => 'hello@globex-demo.test', 'website' => 'https://globex-demo.test', 'registration_no' => 'DEMO-0002', 'timezone' => 'Asia/Dhaka',
            'working_days' => [0, 1, 2, 3, 4], 'status' => Organization::ACTIVE, 'approved_at' => now(), 'applicant_user_id' => $owner->id,
        ]);
        $gAdmin = app(CompanyProvisioningService::class)->provision($globex);
        $role = Role::withoutGlobalScopes()->where('organization_id', $globex->id)->where('slug', 'developer')->firstOrFail();
        $rafi = User::where('email', 'rafi.islam@acme-demo.test')->firstOrFail();
        $member = Membership::withoutGlobalScopes()->create([
            'organization_id' => $globex->id, 'user_id' => $rafi->id, 'role_id' => $role->id, 'user_type' => 'internal', 'status' => Membership::ACTIVE, 'joined_at' => now()->subMonths(2)->toDateString(),
        ]);
        app(EmploymentService::class)->open($member);
        app(HierarchyService::class)->rebuildSubtree($member);

        app(CurrentOrganization::class)->set($globex, $gAdmin);
        Auth::setUser($owner);
        $project = app(ProjectService::class)->create($globex, $gAdmin, ['name' => 'Factory Dashboard', 'key' => 'FAC', 'manager_membership_id' => $gAdmin->id]);
        app(ProjectService::class)->addMember($project, $member, 'member', $gAdmin);
        foreach (['Connect the line sensors', 'Alert on downtime', 'Weekly output report'] as $title) {
            app(TaskService::class)->create($project, $gAdmin, ['title' => $title, 'type' => 'task', 'assignee_id' => $member->id]);
        }

        // Platform companies for the System Admin: one waiting for review, one suspended.
        app(CurrentOrganization::class)->clear();
        $initech = User::create(['name' => 'Peter Initech', 'email' => 'peter@initech-demo.test', 'password' => Hash::make('password'), 'email_verified_at' => now()]);
        Organization::create([
            'name' => 'Initech (waiting for approval)', 'slug' => 'initech-demo', 'industry' => 'Software', 'size' => '11-50', 'address' => '4120 Freidrich Lane', 'phone' => '+880 1900 000000',
            'email' => 'hello@initech-demo.test', 'registration_no' => 'DEMO-0003', 'timezone' => 'Asia/Dhaka', 'working_days' => [0, 1, 2, 3, 4],
            'status' => Organization::PENDING, 'applicant_user_id' => $initech->id,
        ]);

        $hooliOwner = User::create(['name' => 'Gavin Hooli', 'email' => 'gavin@hooli-demo.test', 'password' => Hash::make('password'), 'email_verified_at' => now()]);
        $hooli = Organization::create([
            'name' => 'Hooli (to be suspended)', 'slug' => 'hooli-demo', 'industry' => 'Technology', 'size' => '200+', 'address' => '1 Hooli Road', 'phone' => '+880 1700 111111',
            'email' => 'hello@hooli-demo.test', 'registration_no' => 'DEMO-0004', 'timezone' => 'Asia/Dhaka', 'working_days' => [0, 1, 2, 3, 4],
            'status' => Organization::PENDING, 'applicant_user_id' => $hooliOwner->id,
        ]);

        // Approved and suspended by the System Admin, so the platform audit log has lines.
        $sysadmin = User::where('is_system_admin', true)->first();
        if ($sysadmin) {
            Auth::setUser($sysadmin);
            $review = app(CompanyReviewService::class);
            $review->approve($hooli, $sysadmin);
            $review->suspend($hooli->refresh(), $sysadmin, 'Unpaid invoice (demo)');
            $review->setUserLimit($globex, 25);
        }
    }

    // --- output ------------------------------------------------------------------------------------

    private function step(string $label, callable $work): void
    {
        $this->command?->info("  - {$label}");
        $work();
    }

    private function summary(): void
    {
        $sysadmin = User::where('is_system_admin', true)->value('email');

        $this->command?->newLine();
        $this->command?->info('Feature test data is ready. Every login uses the password: password');
        $this->command?->table(['Try this', 'Sign in as'], [
            ['Platform admin: companies, pending review, audit log, failed jobs', $sysadmin],
            ['Company admin: settings, roles, audit log, people', 'ada.admin@acme-demo.test'],
            ['HOD / DGM / AGM / DM: dashboards, drill-down, reports', 'hasan.rahman@acme-demo.test  (dina.chowdhury, arif.hossain, dilruba.karim)'],
            ['Team lead: team view, approving timesheets, triage', 'tanvir.ahmed@acme-demo.test  (tania.sultana)'],
            ['Developers: board, My Work, timesheet, notifications', 'rafi.islam@acme-demo.test  (also in Globex: company switcher)'],
            ['Project manager: sprints, triage queue, reports', 'priya.nair@acme-demo.test'],
            ['Internal stakeholder: raises requests in HR Portal', 'sam.okafor@acme-demo.test'],
            ['External client: sees only own requests', 'chris.walker@acme-demo.test'],
            ['Custom role (QA Engineer)', 'farah.qureshi@acme-demo.test'],
            ['Second company owner', 'gina@globex-demo.test'],
        ]);
        $this->command?->line('Pending invitation link: '.($this->report['invite'] ?? '-'));
        $this->command?->line('Projects: ECP (sprints), MOB (Kanban), HRP (at risk), INT (delayed), CRM (archived). Run "php artisan queue:work" so new notifications appear.');
    }
}
