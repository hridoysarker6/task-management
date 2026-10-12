<?php

namespace Tests\Feature\Permissions;

use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\RoleManager;
use App\Support\DefaultRoleTemplate;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        $this->service = app(PermissionService::class);
    }

    public function test_the_catalog_is_seeded_in_full(): void
    {
        $this->assertSame(count(PermissionCatalog::names()), Permission::count());
        $this->assertSame(count(PermissionCatalog::names()), count(array_unique(PermissionCatalog::names())), 'permission names must be unique');
    }

    public function test_the_default_template_has_ten_roles_with_the_documented_levels_and_scopes(): void
    {
        $roles = Role::template()->orderBy('level')->orderBy('id')->get();

        $this->assertSame(
            ['company-admin', 'hod', 'dgm', 'agm', 'dm', 'project-manager', 'team-lead', 'developer', 'internal-stakeholder', 'external-stakeholder'],
            $roles->pluck('slug')->all()
        );
        $this->assertSame([0, 10, 20, 30, 40, 50, 60, 70, 80, 80], $roles->pluck('level')->all());
        $this->assertSame('organization', $roles->firstWhere('slug', 'company-admin')->data_scope);
        $this->assertSame('department', $roles->firstWhere('slug', 'hod')->data_scope);
        $this->assertSame('team', $roles->firstWhere('slug', 'team-lead')->data_scope);
        $this->assertSame('own', $roles->firstWhere('slug', 'developer')->data_scope);
        $this->assertTrue($roles->firstWhere('slug', 'external-stakeholder')->is_external);
    }

    public function test_seeding_again_keeps_system_admin_edits(): void
    {
        $dev = Role::template()->where('slug', 'developer')->first();
        $dev->permissions()->detach();

        $this->seedRoleTemplate();

        $this->assertSame(0, $dev->permissions()->count());
        $this->assertSame(10, Role::template()->count());
    }

    public function test_default_matrix_matches_the_workflow_document(): void
    {
        [$organization] = $this->makeCompany();

        $dev = $this->makeMember($organization, 'developer');
        $this->assertTrue($this->service->can($dev, 'task.create'));
        $this->assertTrue($this->service->can($dev, 'task.change_status'));
        $this->assertFalse($this->service->can($dev, 'task.assign'));
        $this->assertFalse($this->service->can($dev, 'task.edit_any'));
        $this->assertFalse($this->service->can($dev, 'user.invite'));

        $lead = $this->makeMember($organization, 'team-lead');
        $this->assertTrue($this->service->can($lead, 'task.assign'));
        $this->assertTrue($this->service->can($lead, 'task.close'));
        $this->assertTrue($this->service->can($lead, 'timesheet.approve'));
        $this->assertFalse($this->service->can($lead, 'user.invite'));

        $external = $this->makeMember($organization, 'external-stakeholder');
        $this->assertTrue($this->service->can($external, 'task.create'));
        $this->assertTrue($this->service->can($external, 'task.reopen'));
        $this->assertFalse($this->service->can($external, 'task.close'));
        $this->assertFalse($this->service->can($external, 'orgchart.view'));
        $this->assertFalse($this->service->can($external, 'worklog.create'));

        $hod = $this->makeMember($organization, 'hod');
        $this->assertTrue($this->service->can($hod, 'user.offboard'));
        $this->assertTrue($this->service->can($hod, 'audit.view'));
        $this->assertFalse($this->service->can($hod, 'role.edit'));
    }

    public function test_every_template_permission_exists_in_the_catalog(): void
    {
        $catalog = PermissionCatalog::names();

        foreach (DefaultRoleTemplate::permissions() as $slug => $names) {
            $this->assertSame([], array_diff($names, $catalog), "unknown permission granted to {$slug}");
        }
    }

    public function test_company_admin_holds_the_whole_catalog(): void
    {
        [, $admin] = $this->makeCompany();

        $this->assertEqualsCanonicalizing(PermissionCatalog::names(), $this->service->forMembership($admin));
    }

    public function test_grant_override_adds_a_permission_the_role_lacks(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');
        $this->assertFalse($this->service->can($dev, 'task.assign'));

        $this->service->override($dev, 'task.assign', 'grant');

        $this->assertTrue($this->service->can($dev, 'task.assign'));
    }

    public function test_revoke_override_removes_a_permission_the_role_has(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');
        $this->assertTrue($this->service->can($dev, 'task.create'));

        $this->service->override($dev, 'task.create', 'revoke');

        $this->assertFalse($this->service->can($dev, 'task.create'));
    }

    public function test_revoke_wins_over_the_role_and_clearing_restores_it(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');

        $this->service->override($dev, 'task.create', 'revoke');
        $this->service->override($dev, 'task.create', null);

        $this->assertTrue($this->service->can($dev, 'task.create'));
    }

    public function test_overrides_apply_to_the_locked_company_admin_role_too(): void
    {
        [, $admin] = $this->makeCompany();

        $this->service->override($admin, 'audit.view', 'revoke');

        $this->assertFalse($this->service->can($admin, 'audit.view'));
        $this->assertTrue($this->service->can($admin, 'task.create'));
    }

    public function test_permissions_are_cached_per_membership(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');

        $this->service->forMembership($dev);
        $this->assertTrue(Cache::has("permissions:membership:{$dev->id}"));

        // A raw change behind the service's back is not visible until the cache is cleared.
        $dev->role->permissions()->detach();
        $this->assertTrue($this->service->can($dev, 'task.create'));

        $this->service->forget($dev);
        $this->assertFalse($this->service->can($dev, 'task.create'));
    }

    public function test_changing_a_role_matrix_clears_the_cache_of_its_members(): void
    {
        [$organization, $admin] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');
        $other = $this->makeMember($organization, 'team-lead');
        $this->assertTrue($this->service->can($dev, 'task.create'));
        $this->assertTrue($this->service->can($other, 'task.create'));

        app(RoleManager::class)->syncMatrix($organization->id, [
            ['id' => $dev->role_id, 'data_scope' => 'own', 'permissions' => ['task.view']],
        ]);

        $this->assertFalse($this->service->can($dev, 'task.create'));
        $this->assertTrue($this->service->can($dev, 'task.view'));
        $this->assertTrue($this->service->can($other, 'task.create'), 'other roles are unaffected');
    }

    public function test_changing_a_members_role_serves_the_new_permissions(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');
        $this->assertFalse($this->service->can($dev, 'task.assign'));

        $lead = Role::withoutGlobalScopes()->where('organization_id', $organization->id)->where('slug', 'team-lead')->first();
        $dev->update(['role_id' => $lead->id]);

        $this->assertTrue($this->service->can($dev->fresh(), 'task.assign'));
    }

    public function test_an_exited_member_loses_cached_permissions_when_soft_deleted_and_restored(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');
        $this->service->forMembership($dev);

        $dev->delete();
        $this->assertFalse(Cache::has("permissions:membership:{$dev->id}"));

        $dev->restore();
        $this->assertTrue($this->service->can($dev, 'task.create'));
    }

    public function test_gate_resolves_permissions_for_the_current_company_member(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');
        $this->enterCompany($dev);

        $this->actingAs($dev->user);

        $this->assertTrue(Gate::allows('task.create'));
        $this->assertTrue($dev->user->can('task.create'));
        $this->assertFalse(Gate::allows('task.assign'));
        $this->assertFalse(Gate::allows('not.a.permission'));
    }

    public function test_gate_denies_everything_outside_a_company_context(): void
    {
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('task.create'));
    }

    public function test_gate_never_grants_a_membership_of_a_different_user(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');
        $this->enterCompany($dev);

        $stranger = User::factory()->create();

        $this->assertFalse(Gate::forUser($stranger)->allows('task.create'));
    }

    public function test_membership_helper_reports_effective_permissions(): void
    {
        [$organization] = $this->makeCompany();
        $dev = $this->makeMember($organization, 'developer');

        $this->assertContains('task.create', $dev->permissionNames());
        $this->assertTrue($dev->hasPermission('task.create'));
        $this->assertInstanceOf(Membership::class, $dev);
    }
}
