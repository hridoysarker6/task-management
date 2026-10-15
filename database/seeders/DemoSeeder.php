<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Label;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Role;
use App\Models\Scopes\OrganizationScope;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use App\Services\CompanyProvisioningService;
use App\Services\EmploymentService;
use App\Services\HierarchyService;
use App\Services\ProjectService;
use App\Services\SprintService;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A complete demo company for showing the product (MVP review):
 *
 *   HOD > DGM > AGM > DM > 2 Team Leads > 6 Developers, plus a Project Manager, an internal stakeholder and a client.
 *   Two projects: "E-Commerce Portal" (Scrum: one finished, one active and one planned sprint) and "Mobile App" (Kanban).
 *   About 60 tasks in every state.
 *
 * Run it on demand (it is not part of the normal seeding):  php artisan db:seed --class=DemoSeeder
 * Safe to run twice: it does nothing if the demo company already exists. Every demo login uses the password "password";
 * the sign-in names are printed when it finishes. Never run it against production.
 */
class DemoSeeder extends Seeder
{
    private const SLUG = 'acme-demo';

    private Organization $org;

    /** @var array<string, Membership> */
    private array $people = [];

    private int $emailSeq = 0;

    private Department $dept;

    public function run(): void
    {
        if (Organization::where('slug', self::SLUG)->exists()) {
            $this->command?->warn('The demo company already exists; nothing to do.');

            return;
        }

        // The permission list and the role template normally exist already; only fill them in on an empty database.
        if (Permission::count() === 0) {
            $this->call(PermissionSeeder::class);
        }
        if (Role::withoutGlobalScopes()->whereNull('organization_id')->doesntExist()) {
            $this->call(RoleTemplateSeeder::class);
        }

        $admin = $this->company();
        $this->team($admin);
        $ecp = $this->shop($admin);
        $mob = $this->mobile($admin);

        $this->command?->info('Demo company "Acme Technologies" created. Sign in with any of these (password: password):');
        foreach ($this->people as $label => $m) {
            $this->command?->line(sprintf('  %-22s %s', $label, $m->user->email));
        }
        $this->command?->line("  Projects: {$ecp->key} (sprints), {$mob->key} (Kanban)");
    }

    // --- the company and the people ---------------------------------------------------------------

    private function company(): Membership
    {
        $owner = User::create(['name' => 'Ada Admin', 'email' => 'ada.admin@acme-demo.test', 'password' => Hash::make('password'), 'email_verified_at' => now()]);

        $this->org = Organization::create([
            'name' => 'Acme Technologies', 'slug' => self::SLUG, 'industry' => 'Software', 'size' => '11-50',
            'address' => '12 Demo Street, Dhaka', 'phone' => '+880 1700 000000', 'email' => 'hello@acme-demo.test',
            'website' => 'https://acme-demo.test', 'registration_no' => 'DEMO-0001', 'timezone' => 'Asia/Dhaka',
            'working_days' => [0, 1, 2, 3, 4], 'status' => Organization::ACTIVE, 'approved_at' => now(), 'applicant_user_id' => $owner->id,
        ]);

        $admin = app(CompanyProvisioningService::class)->provision($this->org);
        $this->people['Company Admin'] = $admin->load('user');

        return $admin;
    }

    private function team(Membership $admin): void
    {
        $this->dept = $dept = Department::create(['organization_id' => $this->org->id, 'name' => 'Engineering']);

        $hod = $this->person('HOD', 'hod', 'Hasan Rahman', ['department_id' => $dept->id]);
        $dgm = $this->person('DGM', 'dgm', 'Dina Chowdhury', ['department_id' => $dept->id, 'reports_to_id' => $hod->id]);
        $agm = $this->person('AGM', 'agm', 'Arif Hossain', ['department_id' => $dept->id, 'reports_to_id' => $dgm->id]);
        $dm = $this->person('DM', 'dm', 'Dilruba Karim', ['department_id' => $dept->id, 'reports_to_id' => $agm->id]);
        $tlA = $this->person('Team Lead A', 'team-lead', 'Tanvir Ahmed', ['department_id' => $dept->id, 'reports_to_id' => $dm->id]);
        $tlB = $this->person('Team Lead B', 'team-lead', 'Tania Sultana', ['department_id' => $dept->id, 'reports_to_id' => $dm->id]);

        foreach (['Rafi Islam', 'Nusrat Jahan', 'Imran Khan'] as $i => $name) {
            $this->person('Developer '.($i + 1), 'developer', $name, ['department_id' => $dept->id, 'reports_to_id' => $tlA->id]);
        }
        foreach (['Sadia Afrin', 'Kamal Uddin', 'Mitu Das'] as $i => $name) {
            $this->person('Developer '.($i + 4), 'developer', $name, ['department_id' => $dept->id, 'reports_to_id' => $tlB->id]);
        }

        $this->person('Project Manager', 'project-manager', 'Priya Nair');
        $this->person('Internal Stakeholder', 'internal-stakeholder', 'Sam Okafor');
        $this->person('Client (external)', 'external-stakeholder', 'Chris Walker');
    }

    private function person(string $label, string $roleSlug, string $name, array $extra = []): Membership
    {
        $role = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', $roleSlug)->firstOrFail();
        $email = strtolower(str_replace(' ', '.', $name)).'@acme-demo.test';

        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password'), 'email_verified_at' => now()]);
        $m = Membership::withoutGlobalScopes()->create($extra + [
            'organization_id' => $this->org->id, 'user_id' => $user->id, 'role_id' => $role->id,
            'user_type' => $role->is_external ? 'external' : 'internal', 'status' => Membership::ACTIVE,
            'joined_at' => now()->subYear()->toDateString(), 'employee_code' => 'E'.str_pad((string) ++$this->emailSeq, 3, '0', STR_PAD_LEFT),
        ]);

        app(EmploymentService::class)->open($m);
        app(HierarchyService::class)->rebuildSubtree($m);

        return $this->people[$label] = $m->load('user');
    }

    // --- projects --------------------------------------------------------------------------------

    private function shop(Membership $admin): Project
    {
        $projects = app(ProjectService::class);
        $project = $projects->create($this->org, $admin, ['name' => 'E-Commerce Portal', 'key' => 'ECP', 'department_id' => $this->dept->id, 'description' => 'The customer storefront and checkout.', 'manager_membership_id' => $this->people['Project Manager']->id]);
        $projects->updateSettings($project, ['uses_sprints' => true, 'triage_mode' => 'external_only']);

        foreach (['Team Lead A', 'Team Lead B', 'Developer 1', 'Developer 2', 'Developer 3', 'Developer 4', 'Developer 5', 'Developer 6'] as $label) {
            $projects->addMember($project, $this->people[$label], 'member');
        }
        $projects->addMember($project, $this->people['Internal Stakeholder'], 'stakeholder');
        $projects->addMember($project, $this->people['Client (external)'], 'stakeholder');

        $labels = collect(['Frontend' => '#3b82f6', 'Backend' => '#22c55e', 'Payments' => '#f59e0b', 'Urgent' => '#ef4444'])
            ->map(fn ($color, $name) => Label::create(['project_id' => $project->id, 'name' => $name, 'color' => $color]));

        $devs = collect(range(1, 6))->map(fn ($i) => $this->people["Developer {$i}"]);

        // Three epics, then stories / tasks / bugs under them.
        $epics = collect(['Checkout', 'Catalogue', 'Accounts'])->map(fn ($t) => $this->make($project, ['type' => 'epic', 'title' => $t.' revamp', 'status' => 'In Progress', 'priority' => 4]));

        $work = [
            ['Guest checkout flow', 'story', 0], ['Card payment form', 'task', 0], ['Order confirmation email', 'task', 0], ['Fix double charge on retry', 'bug', 0],
            ['Apply discount codes', 'story', 0], ['Shipping cost calculator', 'task', 0], ['Address autocomplete', 'task', 0], ['Saved payment methods', 'story', 0],
            ['Product search with filters', 'story', 1], ['Product image zoom', 'task', 1], ['Out-of-stock badges', 'task', 1], ['Fix price rounding on variants', 'bug', 1],
            ['Category landing pages', 'story', 1], ['Wishlist', 'story', 1], ['Related products carousel', 'task', 1], ['Sort by rating', 'task', 1],
            ['Sign in with Google', 'story', 2], ['Password reset flow', 'task', 2], ['Order history page', 'story', 2], ['Fix session timeout loop', 'bug', 2],
            ['Profile photo upload', 'task', 2], ['Two-factor option', 'story', 2], ['Email preferences', 'task', 2], ['Delete my account', 'task', 2],
            ['Speed up homepage', 'task', 1], ['Fix mobile menu overlap', 'bug', 1], ['Add sitemap', 'task', 1], ['Accessibility audit fixes', 'story', 2],
            ['Set up error monitoring', 'task', 0], ['Nightly database backup', 'task', 0], ['Update Laravel dependencies', 'task', 0], ['Write API docs', 'task', 2],
        ];

        $tasks = collect($work)->map(function ($row, $i) use ($project, $epics, $devs, $labels) {
            [$title, $type, $epic] = $row;

            return $this->make($project, [
                'title' => $title, 'type' => $type, 'parent_id' => $epics[$epic]->id,
                'priority' => [3, 2, 4, 5, 3, 1][$i % 6], 'story_points' => [1, 2, 3, 5, 8][$i % 5],
                'assignee_id' => $i % 7 === 6 ? null : $devs[$i % 6]->id, 'reporter_id' => $this->people['Project Manager']->id,
                'original_estimate_min' => [120, 240, 480, 360, 720][$i % 5], 'status' => 'Backlog',
                'due_date' => $i % 9 === 0 ? now()->addDays(3 + $i) : null,
                'label' => $labels->values()[$i % 4]->id,
            ]);
        })->values();

        // A request from the client, waiting in triage.
        $this->make($project, ['title' => 'Can we show prices in two currencies?', 'type' => 'story', 'status' => 'Triage', 'reporter_id' => $this->people['Client (external)']->id, 'priority' => 3]);

        // Sprint 1 (finished): ten tasks, most done, the rest carried over. Sprint 2 (active). Sprint 3 (planned).
        $sprints = app(SprintService::class);
        $s1 = $this->sprint($project, 'Sprint 1', 'Take a first payment', -28, -15);
        $s2 = $this->sprint($project, 'Sprint 2', 'Browse, search and save for later', -14, -1, 'planned');
        $s3 = $this->sprint($project, 'Sprint 3', 'Accounts and hardening', 0, 13);

        $this->current();
        foreach ($tasks->slice(0, 10) as $t) {
            $sprints->addTask($s1, $t);
        }
        $sprints->start($s1);
        $tasks->slice(0, 8)->each(fn ($t) => $this->setStatus($t, 'Done'));
        $this->setStatus($tasks[8], 'In Progress');
        $sprints->complete($s1->refresh(), $s2);

        // Sprint 2 now holds the two carried over, plus new work. It is the one running.
        $s2->update(['start_date' => today()->subDays(6), 'end_date' => today()->addDays(7)]);
        foreach ($tasks->slice(10, 10) as $t) {
            $sprints->addTask($s2->refresh(), $t);
        }
        $sprints->start($s2->refresh());
        $tasks->slice(10, 4)->each(fn ($t) => $this->setStatus($t, 'Done'));
        $tasks->slice(14, 3)->each(fn ($t) => $this->setStatus($t, 'In Progress'));
        $this->setStatus($tasks[17], 'In Review');
        $this->setStatus($tasks[18], 'Blocked');

        foreach ($tasks->slice(20, 6) as $t) {
            $sprints->addTask($s3, $t);
        }

        // A sub-task or two, and a few comments so the pages are not bare.
        $this->make($project, ['title' => 'Design the form', 'type' => 'subtask', 'parent_id' => $tasks[1]->id, 'status' => 'Done', 'assignee_id' => $devs[0]->id, 'reporter_id' => $devs[0]->id]);
        $this->make($project, ['title' => 'Validate card numbers', 'type' => 'subtask', 'parent_id' => $tasks[1]->id, 'status' => 'To Do', 'assignee_id' => $devs[0]->id, 'reporter_id' => $devs[0]->id]);

        // The rest stay in the backlog, ranked as created. Two tasks go overdue to light up the red dates.
        $tasks[12]->update(['due_date' => today()->subDays(2)]);
        $tasks[15]->update(['due_date' => today()->subDays(1)]);

        return $project;
    }

    private function mobile(Membership $admin): Project
    {
        $projects = app(ProjectService::class);
        $project = $projects->create($this->org, $admin, ['name' => 'Mobile App', 'key' => 'MOB', 'department_id' => $this->dept->id, 'description' => 'iOS and Android client, run as a continuous board.', 'manager_membership_id' => $this->people['Project Manager']->id]);
        $projects->updateSettings($project, ['uses_sprints' => false]);

        foreach (['Team Lead B', 'Developer 4', 'Developer 5', 'Developer 6'] as $label) {
            $projects->addMember($project, $this->people[$label], 'member');
        }
        $projects->addMember($project, $this->people['Internal Stakeholder'], 'stakeholder');

        $devs = collect(['Developer 4', 'Developer 5', 'Developer 6'])->map(fn ($l) => $this->people[$l]);
        $rows = [
            ['Push notifications', 'story', 'In Progress'], ['Biometric login', 'story', 'To Do'], ['Offline cart', 'story', 'Backlog'], ['Crash on rotate (Android)', 'bug', 'In Review'],
            ['Dark mode', 'task', 'Done'], ['Deep links', 'task', 'To Do'], ['App store screenshots', 'task', 'Done'], ['Fix keyboard covering input', 'bug', 'Blocked'],
            ['Pull to refresh', 'task', 'Done'], ['Share product', 'task', 'In Progress'], ['Tablet layout', 'story', 'Backlog'], ['Analytics events', 'task', 'To Do'],
            ['Onboarding carousel', 'story', 'Backlog'], ['Fix splash flicker', 'bug', 'Done'], ['Localise to Bangla', 'story', 'To Do'], ['Reduce app size', 'task', 'Backlog'],
            ['Rate the app prompt', 'task', 'Backlog'], ['Accessibility labels', 'task', 'In Progress'], ['Update SDKs', 'task', 'To Do'], ['Release checklist', 'task', 'Done'],
        ];
        foreach ($rows as $i => [$title, $type, $status]) {
            $this->make($project, [
                'title' => $title, 'type' => $type, 'status' => $status, 'priority' => [3, 4, 2, 5][$i % 4], 'story_points' => [1, 2, 3, 5][$i % 4],
                'assignee_id' => $devs[$i % 3]->id, 'reporter_id' => $this->people['Team Lead B']->id,
            ]);
        }

        return $project;
    }

    // --- helpers ---------------------------------------------------------------------------------

    /** Make the company current so activity lines and sprint rules behave as they do in the app. */
    private function current(): void
    {
        app(CurrentOrganization::class)->set($this->org, $this->people['Project Manager']);
    }

    private function sprint(Project $project, string $name, string $goal, int $startOffset, int $endOffset, string $status = 'planned'): Sprint
    {
        return Sprint::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'project_id' => $project->id, 'name' => $name, 'goal' => $goal,
            'start_date' => today()->addDays($startOffset), 'end_date' => today()->addDays($endOffset), 'status' => $status,
            'created_by' => $this->people['Project Manager']->id,
        ]);
    }

    /** @param array<string, mixed> $a */
    private function make(Project $project, array $a): Task
    {
        $status = ProjectStatus::where('project_id', $project->id)->where('name', $a['status'] ?? 'Backlog')->firstOrFail();
        $number = $project->task_counter + 1;
        $project->forceFill(['task_counter' => $number])->save();
        $labelId = $a['label'] ?? null;

        $task = Task::withoutGlobalScope(OrganizationScope::class)->create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'number' => $number,
            'parent_id' => $a['parent_id'] ?? null, 'type' => $a['type'] ?? 'task', 'title' => $a['title'],
            'description' => '<p>'.$a['title'].' — demo data.</p>', 'status_id' => $status->id, 'priority' => $a['priority'] ?? 3,
            'reporter_id' => $a['reporter_id'] ?? $this->people['Project Manager']->id, 'assignee_id' => $a['assignee_id'] ?? null,
            'story_points' => $a['story_points'] ?? null, 'original_estimate_min' => $a['original_estimate_min'] ?? null,
            'remaining_estimate_min' => $a['original_estimate_min'] ?? null, 'due_date' => $a['due_date'] ?? null,
            'board_position' => $number * 1000, 'backlog_rank' => $number * 1000,
            'completed_at' => $status->category === 'done' ? now() : null,
        ]);

        if ($labelId) {
            $task->labels()->attach($labelId);
        }

        return $task;
    }

    private function setStatus(Task $task, string $name): void
    {
        $status = ProjectStatus::where('project_id', $task->project_id)->where('name', $name)->firstOrFail();
        $task->forceFill(['status_id' => $status->id, 'completed_at' => $status->category === 'done' ? now() : null])->save();
    }
}
