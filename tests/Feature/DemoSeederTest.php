<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_company_has_the_full_hierarchy_two_projects_and_about_sixty_tasks(): void
    {
        $this->seed(DemoSeeder::class);

        $org = Organization::where('slug', 'acme-demo')->firstOrFail();
        $tasks = Task::withoutGlobalScopes()->where('organization_id', $org->id)->count();

        $this->assertSame(16, Membership::withoutGlobalScopes()->where('organization_id', $org->id)->count()); // admin, 6 managers, 6 devs, PM, 2 stakeholders
        $this->assertSame(2, Project::withoutGlobalScopes()->where('organization_id', $org->id)->count());
        $this->assertGreaterThanOrEqual(55, $tasks);
        $this->assertLessThanOrEqual(75, $tasks);
        $this->assertSame(
            ['active' => 1, 'completed' => 1, 'planned' => 1],
            Sprint::withoutGlobalScopes()->where('organization_id', $org->id)->orderBy('status')->pluck('status')->countBy()->all(),
        );
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seed(DemoSeeder::class);
        $before = Task::withoutGlobalScopes()->count();

        $this->seed(DemoSeeder::class);

        $this->assertSame($before, Task::withoutGlobalScopes()->count());
        $this->assertSame(1, Organization::where('slug', 'acme-demo')->count());
    }
}
