<?php

namespace Tests\Feature\Permissions;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RoleMatrixTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $organization;

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->organization, $this->admin] = $this->makeCompany();
    }

    private function role(string $slug, ?Organization $organization = null): Role
    {
        return Role::withoutGlobalScopes()->where('organization_id', ($organization ?? $this->organization)->id)->where('slug', $slug)->firstOrFail();
    }

    public function test_company_admin_sees_the_matrix(): void
    {
        $this->actingAsMember($this->admin)->get('/settings/roles')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Roles/Index')
                ->has('roles', 10)
                ->has('modules')
                ->where('abilities.assign', true));
    }

    public function test_only_the_companys_own_roles_are_listed(): void
    {
        $this->makeCompany();

        $this->actingAsMember($this->admin)->get('/settings/roles')
            ->assertInertia(fn ($page) => $page->has('roles', 10));
    }

    public function test_people_without_role_permissions_cannot_open_or_change_roles(): void
    {
        $dev = $this->makeMember($this->organization, 'developer');
        $devRole = $this->role('developer');

        $this->actingAsMember($dev)->get('/settings/roles')->assertForbidden();
        $this->actingAsMember($dev)->put('/settings/roles/matrix', ['roles' => [['id' => $devRole->id, 'data_scope' => 'organization', 'permissions' => ['task.assign']]]])->assertForbidden();
        $this->actingAsMember($dev)->post('/settings/roles', ['name' => 'X', 'level' => 55, 'data_scope' => 'own'])->assertForbidden();
        $this->actingAsMember($dev)->delete('/settings/roles/'.$devRole->id)->assertForbidden();

        $this->assertFalse($devRole->fresh()->permissions->contains('name', 'task.assign'));
    }

    public function test_the_matrix_can_be_saved(): void
    {
        $dev = $this->role('developer');

        $this->actingAsMember($this->admin)->put('/settings/roles/matrix', [
            'roles' => [['id' => $dev->id, 'data_scope' => 'team', 'permissions' => ['task.view', 'task.assign']]],
        ])->assertSessionHasNoErrors();

        $dev->refresh();
        $this->assertSame('team', $dev->data_scope);
        $this->assertEqualsCanonicalizing(['task.view', 'task.assign'], $dev->permissions->pluck('name')->all());
    }

    public function test_saving_takes_effect_immediately(): void
    {
        $devMember = $this->makeMember($this->organization, 'developer');
        $this->assertTrue($devMember->hasPermission('task.create'));

        $this->actingAsMember($this->admin)->put('/settings/roles/matrix', [
            'roles' => [['id' => $devMember->role_id, 'data_scope' => 'own', 'permissions' => ['task.view']]],
        ]);

        $this->assertFalse($devMember->fresh()->hasPermission('task.create'));
    }

    public function test_the_company_admin_role_is_locked(): void
    {
        $adminRole = $this->role('company-admin');
        $before = $adminRole->permissions()->count();

        $this->actingAsMember($this->admin)->put('/settings/roles/matrix', [
            'roles' => [['id' => $adminRole->id, 'data_scope' => 'own', 'permissions' => []]],
        ]);

        $this->assertSame('organization', $adminRole->fresh()->data_scope);
        $this->assertSame($before, $adminRole->permissions()->count());
        $this->assertTrue($this->admin->fresh()->hasPermission('role.edit'));

        $this->actingAsMember($this->admin)->put('/settings/roles/'.$adminRole->id, ['name' => 'Renamed', 'level' => 5, 'data_scope' => 'own'])
            ->assertSessionHasErrors('role');
        $this->actingAsMember($this->admin)->delete('/settings/roles/'.$adminRole->id)->assertSessionHasErrors('role');
        $this->assertSame('Company Admin', $adminRole->fresh()->name);
    }

    public function test_unknown_permissions_and_scopes_are_rejected(): void
    {
        $dev = $this->role('developer');

        $this->actingAsMember($this->admin)->put('/settings/roles/matrix', [
            'roles' => [['id' => $dev->id, 'data_scope' => 'galaxy', 'permissions' => ['made.up']]],
        ])->assertSessionHasErrors(['roles.0.data_scope', 'roles.0.permissions.0']);
    }

    public function test_another_companys_roles_cannot_be_touched(): void
    {
        [$other] = $this->makeCompany();
        $foreign = $this->role('developer', $other);
        $before = $foreign->permissions()->pluck('name')->sort()->values()->all();

        $this->actingAsMember($this->admin)->put('/settings/roles/matrix', [
            'roles' => [['id' => $foreign->id, 'data_scope' => 'organization', 'permissions' => ['task.assign']]],
        ])->assertSessionHasNoErrors();

        $foreign->refresh();
        $this->assertSame('own', $foreign->data_scope);
        $this->assertSame($before, $foreign->permissions()->pluck('name')->sort()->values()->all());

        $this->actingAsMember($this->admin)->put('/settings/roles/'.$foreign->id, ['name' => 'Hacked', 'level' => 70, 'data_scope' => 'own'])->assertNotFound();
        $this->actingAsMember($this->admin)->delete('/settings/roles/'.$foreign->id)->assertNotFound();
        $this->assertNotSame('Hacked', $foreign->fresh()->name);
    }

    public function test_the_default_template_cannot_be_edited_from_a_company(): void
    {
        $template = Role::template()->where('slug', 'developer')->first();

        $this->actingAsMember($this->admin)->put('/settings/roles/'.$template->id, ['name' => 'Hacked', 'level' => 70, 'data_scope' => 'own'])->assertNotFound();
        $this->assertSame('Developer', $template->fresh()->name);
    }

    public function test_a_custom_role_can_be_created_by_copying_another(): void
    {
        $lead = $this->role('team-lead');

        $this->actingAsMember($this->admin)->post('/settings/roles', [
            'name' => 'QA Lead', 'level' => 65, 'data_scope' => 'team', 'description' => 'Leads QA', 'copy_from' => $lead->id,
        ])->assertSessionHasNoErrors();

        $qa = $this->role('qa-lead');
        $this->assertSame($this->organization->id, $qa->organization_id);
        $this->assertSame(65, $qa->level);
        $this->assertFalse($qa->is_locked);
        $this->assertEqualsCanonicalizing($lead->permissions->pluck('name')->all(), $qa->permissions->pluck('name')->all());
    }

    public function test_a_custom_role_can_be_created_empty_and_slugs_stay_unique(): void
    {
        foreach ([1, 2] as $_) {
            $this->actingAsMember($this->admin)->post('/settings/roles', ['name' => 'Auditor', 'level' => 75, 'data_scope' => 'own']);
        }

        $this->assertSame(0, $this->role('auditor')->permissions()->count());
        $this->assertNotNull($this->role('auditor-2'));
    }

    public function test_copying_from_another_companys_role_is_rejected(): void
    {
        [$other] = $this->makeCompany();

        $this->actingAsMember($this->admin)->post('/settings/roles', [
            'name' => 'Spy', 'level' => 65, 'data_scope' => 'team', 'copy_from' => $this->role('team-lead', $other)->id,
        ])->assertSessionHasErrors('copy_from');
    }

    public function test_role_input_is_validated(): void
    {
        $this->actingAsMember($this->admin)->post('/settings/roles', ['name' => '', 'level' => 0, 'data_scope' => 'x'])
            ->assertSessionHasErrors(['name', 'level', 'data_scope']);
    }

    public function test_a_role_can_be_renamed_and_its_level_and_scope_changed(): void
    {
        $dev = $this->role('developer');

        $this->actingAsMember($this->admin)->put('/settings/roles/'.$dev->id, [
            'name' => 'Engineer', 'level' => 72, 'data_scope' => 'team', 'description' => 'Writes code',
        ])->assertSessionHasNoErrors();

        $dev->refresh();
        $this->assertSame(['Engineer', 72, 'team'], [$dev->name, $dev->level, $dev->data_scope]);
    }

    public function test_a_role_in_use_cannot_be_deleted_but_an_unused_one_can(): void
    {
        $member = $this->makeMember($this->organization, 'developer');
        $this->actingAsMember($this->admin)->delete('/settings/roles/'.$member->role_id)->assertSessionHasErrors('role');
        $this->assertNotNull(Role::withoutGlobalScopes()->find($member->role_id));

        $unused = $this->role('agm');
        $this->actingAsMember($this->admin)->delete('/settings/roles/'.$unused->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted($unused);
    }

    public function test_permissions_are_shared_with_the_frontend(): void
    {
        $dev = $this->makeMember($this->organization, 'developer');

        $this->actingAsMember($dev)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('auth.role', 'Developer')
                ->where('currentOrganization.id', $this->organization->id)
                ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('task.create') && ! collect($permissions)->contains('task.assign')));
    }

    // ---- System Admin: default template ------------------------------------------------------

    public function test_system_admin_edits_the_default_template_and_new_companies_get_the_change(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $template = Role::template()->where('slug', 'developer')->first();

        $this->actingAs($sysadmin)->get('/admin/role-template')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/RoleTemplate')->has('roles', 10));

        $this->actingAs($sysadmin)->put('/admin/role-template/matrix', [
            'roles' => [['id' => $template->id, 'data_scope' => 'team', 'permissions' => ['task.view', 'task.assign']]],
        ])->assertSessionHasNoErrors();

        $this->assertEqualsCanonicalizing(['task.view', 'task.assign'], $template->fresh()->permissions->pluck('name')->all());

        // The already-approved company keeps its own copy...
        $this->assertTrue($this->role('developer')->permissions->contains('name', 'task.create'));

        // ...while a newly approved company gets the edited template.
        [$fresh] = $this->makeCompany();
        $copy = $this->role('developer', $fresh);
        $this->assertSame('team', $copy->data_scope);
        $this->assertEqualsCanonicalizing(['task.view', 'task.assign'], $copy->permissions->pluck('name')->all());
    }

    public function test_system_admin_can_add_and_remove_template_roles(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $lead = Role::template()->where('slug', 'team-lead')->first();

        $this->actingAs($sysadmin)->post('/admin/role-template/roles', [
            'name' => 'Scrum Master', 'level' => 55, 'data_scope' => 'team', 'copy_from' => $lead->id,
        ])->assertSessionHasNoErrors();

        $scrum = Role::template()->where('slug', 'scrum-master')->first();
        $this->assertNull($scrum->organization_id);
        $this->assertSame($lead->permissions()->count(), $scrum->permissions()->count());

        $this->actingAs($sysadmin)->put('/admin/role-template/roles/'.$scrum->id, ['name' => 'Scrum Lead', 'level' => 56, 'data_scope' => 'team'])->assertSessionHasNoErrors();
        $this->assertSame('Scrum Lead', $scrum->fresh()->name);

        $this->actingAs($sysadmin)->delete('/admin/role-template/roles/'.$scrum->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted($scrum);
    }

    public function test_template_routes_cannot_touch_company_roles(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $companyRole = $this->role('developer');

        $this->actingAs($sysadmin)->put('/admin/role-template/roles/'.$companyRole->id, ['name' => 'Hacked', 'level' => 70, 'data_scope' => 'own'])->assertNotFound();
        $this->actingAs($sysadmin)->delete('/admin/role-template/roles/'.$companyRole->id)->assertNotFound();

        $this->actingAs($sysadmin)->put('/admin/role-template/matrix', [
            'roles' => [['id' => $companyRole->id, 'data_scope' => 'organization', 'permissions' => []]],
        ]);
        $this->assertSame('own', $companyRole->fresh()->data_scope);
    }

    public function test_the_template_company_admin_role_is_locked_too(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $locked = Role::template()->where('slug', 'company-admin')->first();

        $this->actingAs($sysadmin)->delete('/admin/role-template/roles/'.$locked->id)->assertSessionHasErrors('role');
        $this->assertNotSoftDeleted($locked);
    }
}
