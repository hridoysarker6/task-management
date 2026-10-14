<?php

namespace Tests\Feature\People;

use App\Models\Department;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class PeopleTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
    }

    private function role(string $slug, ?Organization $org = null): Role
    {
        return Role::withoutGlobalScopes()->where('organization_id', ($org ?? $this->org)->id)->where('slug', $slug)->firstOrFail();
    }

    // ---- list & detail ---------------------------------------------------------------------

    public function test_the_people_list_shows_active_members_only(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $gone = $this->makeMember($this->org, 'developer');
        $gone->forceFill(['status' => Membership::EXITED])->save();
        $gone->delete();

        $this->actingAsMember($this->admin)->get('/people')->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Index')
                ->where('tab', 'active')
                ->has('rows.data', 2) // the admin and the developer
                ->where('counts.active', 2)->where('counts.former', 1));
    }

    public function test_other_companies_people_never_appear(): void
    {
        [$other] = $this->makeCompany();
        $this->makeMember($other, 'developer');

        $this->actingAsMember($this->admin)->get('/people')
            ->assertInertia(fn ($page) => $page->has('rows.data', 1));
    }

    public function test_search_and_filters(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id, 'name' => 'Software']);
        $a = $this->makeMember($this->org, 'developer', ['department_id' => $dept->id, 'employee_code' => 'E-100']);
        $a->user->forceFill(['name' => 'Alice Aminul'])->save();
        $b = $this->makeMember($this->org, 'team-lead');
        $b->user->forceFill(['name' => 'Bob Bhuiyan'])->save();

        $this->actingAsMember($this->admin)->get('/people?q=alice')->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.name', 'Alice Aminul'));
        $this->actingAsMember($this->admin)->get('/people?q=E-100')->assertInertia(fn ($p) => $p->has('rows.data', 1));
        $this->actingAsMember($this->admin)->get('/people?role_id='.$this->role('team-lead')->id)->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.name', 'Bob Bhuiyan'));
        $this->actingAsMember($this->admin)->get('/people?department_id='.$dept->id)->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.name', 'Alice Aminul'));
    }

    public function test_tabs_are_permission_checked(): void
    {
        $dm = $this->makeMember($this->org, 'dm'); // can view and invite, but not view former employees
        $dev = $this->makeMember($this->org, 'developer'); // cannot view people at all

        $this->actingAsMember($dm)->get('/people?tab=former')->assertForbidden();
        $this->actingAsMember($dm)->get('/people?tab=invited')->assertOk();
        $this->actingAsMember($dev)->get('/people')->assertForbidden();
        $this->actingAsMember($this->admin)->get('/people?tab=former')->assertOk();
        $this->actingAsMember($this->admin)->get('/people?tab=nope')->assertNotFound();
    }

    public function test_a_manager_only_sees_their_own_team_in_the_list(): void
    {
        $dm = $this->makeMember($this->org, 'dm');
        $tl = $this->makeMember($this->org, 'team-lead', ['reports_to_id' => $dm->id]);
        $dev = $this->makeMember($this->org, 'developer', ['reports_to_id' => $tl->id]);
        $outsider = $this->makeMember($this->org, 'developer');

        $this->actingAsMember($dm)->get('/people')
            ->assertInertia(fn ($page) => $page->has('rows.data', 3)); // themself, the TL and the developer

        $this->actingAsMember($dm)->get("/people/{$dev->id}")->assertOk();
        $this->actingAsMember($dm)->get("/people/{$outsider->id}")->assertForbidden();
    }

    public function test_the_detail_page_shows_history_and_reports(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead');
        $dev = $this->makeMember($this->org, 'developer', ['reports_to_id' => $tl->id]);

        $this->actingAsMember($this->admin)->get("/people/{$tl->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Show')
                ->where('person.id', $tl->id)->has('reports', 1)->has('history', 1)
                ->where('abilities.edit', true)->where('abilities.offboard', true)->where('abilities.rehire', false));
    }

    public function test_former_employees_are_visible_only_with_the_permission(): void
    {
        $dm = $this->makeMember($this->org, 'dm');
        $former = $this->makeMember($this->org, 'developer');
        $former->forceFill(['status' => Membership::EXITED])->save();
        $former->delete();

        $this->actingAsMember($this->admin)->get("/people/{$former->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('person.is_former', true)->where('abilities.rehire', true)->where('abilities.offboard', false));
        $this->actingAsMember($dm)->get("/people/{$former->id}")->assertForbidden();
    }

    public function test_people_of_another_company_are_not_found(): void
    {
        [$other] = $this->makeCompany();
        $foreign = $this->makeMember($other, 'developer');

        $this->actingAsMember($this->admin)->get("/people/{$foreign->id}")->assertNotFound();
        $this->actingAsMember($this->admin)->put("/people/{$foreign->id}", ['designation' => 'x'])->assertNotFound();
    }

    // ---- editing ---------------------------------------------------------------------------

    public function test_a_member_can_be_edited(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $dm = $this->makeMember($this->org, 'dm');
        $dev = $this->makeMember($this->org, 'developer');

        $this->actingAsMember($this->admin)->put("/people/{$dev->id}", [
            'role_id' => $this->role('team-lead')->id,
            'department_id' => $dept->id,
            'reports_to_id' => $dm->id,
            'designation' => 'Lead',
            'employee_code' => 'E-5',
        ])->assertSessionHasNoErrors();

        $dev->refresh();
        $this->assertSame('team-lead', $dev->role->slug);
        $this->assertSame($dept->id, $dev->department_id);
        $this->assertSame($dm->id, $dev->reports_to_id);
        $this->assertSame('E-5', $dev->employee_code);
        $this->assertSame([$dev->id => 0, $dm->id => 1], $this->closureOf($dev));

        // The open employment period was updated to the new snapshot.
        $period = $dev->employmentPeriods()->first();
        $this->assertSame($dev->role_id, $period->role_id);
        $this->assertSame($dm->id, $period->reports_to_id);
        $this->assertSame('Lead', $period->designation);
        $this->assertSame(1, $dev->employmentPeriods()->count());
    }

    public function test_each_kind_of_change_needs_its_own_permission(): void
    {
        $dgm = $this->makeMember($this->org, 'dgm');
        $agm = $this->makeMember($this->org, 'agm', ['reports_to_id' => $dgm->id]); // can edit users, but not change roles or managers
        $dm = $this->makeMember($this->org, 'dm');
        $dev = $this->makeMember($this->org, 'developer', ['reports_to_id' => $agm->id]);

        $this->actingAsMember($agm)->put("/people/{$dev->id}", ['designation' => 'ok'])->assertSessionHasNoErrors();
        $this->actingAsMember($agm)->put("/people/{$dev->id}", ['role_id' => $this->role('team-lead')->id])->assertForbidden();
        $this->actingAsMember($agm)->put("/people/{$dev->id}", ['reports_to_id' => $dm->id])->assertForbidden();
        $this->actingAsMember($dgm)->put("/people/{$dev->id}", ['reports_to_id' => $dm->id])->assertSessionHasNoErrors();

        $this->assertSame('ok', $dev->fresh()->designation);
        $this->assertSame($dm->id, $dev->fresh()->reports_to_id);
    }

    public function test_people_without_user_view_cannot_edit(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $other = $this->makeMember($this->org, 'developer');

        $this->actingAsMember($dev)->put("/people/{$other->id}", ['designation' => 'x'])->assertForbidden();
    }

    public function test_you_cannot_edit_someone_more_senior(): void
    {
        $dm = $this->makeMember($this->org, 'dm');
        $hod = $this->makeMember($this->org, 'hod');

        // Out of the DM's team scope, so it is not even visible to them.
        $this->actingAsMember($dm)->put("/people/{$hod->id}", ['designation' => 'x'])->assertForbidden();

        // Someone whose scope does cover the more senior person is stopped by the rank rule.
        $hod2 = $this->makeMember($this->org, 'hod');
        $custom = Role::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'name' => 'Wide DM', 'slug' => 'wide-dm', 'level' => 40, 'data_scope' => 'organization',
        ]);
        $custom->permissions()->sync(Permission::whereIn('name', ['user.view', 'user.edit'])->pluck('id'));
        $wide = $this->makeMember($this->org, 'dm');
        $wide->forceFill(['role_id' => $custom->id])->save();

        $this->actingAsMember($wide)->put("/people/{$hod2->id}", ['designation' => 'x'])->assertSessionHasErrors('member');
    }

    public function test_you_cannot_change_your_own_role_or_manager(): void
    {
        $dgm = $this->makeMember($this->org, 'dgm');

        $this->actingAsMember($dgm)->put("/people/{$dgm->id}", ['role_id' => $this->role('hod')->id])->assertSessionHasErrors();
        $this->assertSame('dgm', $dgm->fresh()->role->slug);
    }

    public function test_you_cannot_hand_out_a_role_more_senior_than_your_own(): void
    {
        $dgm = $this->makeMember($this->org, 'dgm');
        $dev = $this->makeMember($this->org, 'developer', ['reports_to_id' => $dgm->id]);

        $this->actingAsMember($dgm)->put("/people/{$dev->id}", ['role_id' => $this->role('hod')->id])->assertSessionHasErrors('role_id');
    }

    public function test_internal_and_external_roles_cannot_be_swapped(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $client = $this->makeMember($this->org, 'external-stakeholder');

        $this->actingAsMember($this->admin)->put("/people/{$dev->id}", ['role_id' => $this->role('external-stakeholder')->id])->assertSessionHasErrors('role_id');
        $this->actingAsMember($this->admin)->put("/people/{$client->id}", ['role_id' => $this->role('developer')->id])->assertSessionHasErrors('role_id');
    }

    public function test_one_of_two_company_admins_can_step_down(): void
    {
        $second = $this->makeMember($this->org, 'company-admin');

        $this->actingAsMember($this->admin)->put("/people/{$second->id}", ['role_id' => $this->role('hod')->id])->assertSessionHasNoErrors();
        $this->assertSame('hod', $second->fresh()->role->slug);
    }

    public function test_an_admin_cannot_change_their_own_role_so_the_company_always_keeps_one(): void
    {
        $this->actingAsMember($this->admin)->put("/people/{$this->admin->id}", ['role_id' => $this->role('hod')->id])->assertSessionHasErrors('member');
        $this->assertSame('company-admin', $this->admin->fresh()->role->slug);
    }

    public function test_demoting_the_last_admin_is_refused_by_the_service(): void
    {
        // Another level-0 member exists only to act; make them non-locked so the admin is the last locked role holder.
        $actorRole = Role::withoutGlobalScopes()->create([
            'organization_id' => $this->org->id, 'name' => 'Super', 'slug' => 'super', 'level' => 0, 'data_scope' => 'organization',
        ]);
        $actor = $this->makeMember($this->org, 'hod');
        $actor->forceFill(['role_id' => $actorRole->id])->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('last Company Admin');

        app(MemberService::class)->update($this->admin, $actor->fresh(), ['role_id' => $this->role('hod')->id]);
    }

    public function test_department_and_employee_code_are_validated(): void
    {
        [$other] = $this->makeCompany();
        $foreignDept = Department::factory()->create(['organization_id' => $other->id]);
        $dev = $this->makeMember($this->org, 'developer');
        $this->makeMember($this->org, 'developer', ['employee_code' => 'TAKEN']);

        $this->actingAsMember($this->admin)->put("/people/{$dev->id}", ['department_id' => $foreignDept->id])->assertSessionHasErrors('department_id');
        $this->actingAsMember($this->admin)->put("/people/{$dev->id}", ['employee_code' => 'TAKEN'])->assertSessionHasErrors('employee_code');
    }

    // ---- org chart -------------------------------------------------------------------------

    public function test_the_org_chart_is_a_tree_of_internal_people(): void
    {
        $dm = $this->makeMember($this->org, 'dm');
        $tl1 = $this->makeMember($this->org, 'team-lead', ['reports_to_id' => $dm->id]);
        $tl2 = $this->makeMember($this->org, 'team-lead', ['reports_to_id' => $dm->id]);
        $this->makeMember($this->org, 'developer', ['reports_to_id' => $tl1->id]);
        $this->makeMember($this->org, 'external-stakeholder'); // never in the chart

        $this->actingAsMember($this->admin)->get('/org-chart')->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/OrgChart')
                ->where('total', 5) // admin, dm, 2 TLs, 1 developer
                ->has('tree', 2) // two roots: the admin and the DM
                ->where('tree.1.id', $dm->id)
                ->has('tree.1.children', 2)
                ->has('tree.1.children.0.children', 1)
                ->where('tree.1.children.0.open_tasks', null));
    }

    public function test_every_internal_role_can_see_the_org_chart_but_external_cannot(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $client = $this->makeMember($this->org, 'external-stakeholder');

        $this->actingAsMember($dev)->get('/org-chart')->assertOk();
        $this->actingAsMember($client)->get('/org-chart')->assertForbidden();
    }

    // ---- departments -----------------------------------------------------------------------

    public function test_departments_can_be_created_edited_and_deleted(): void
    {
        $hod = $this->makeMember($this->org, 'hod');

        $this->actingAsMember($this->admin)->post('/settings/departments', ['name' => 'Software', 'code' => 'SW', 'head_membership_id' => $hod->id])
            ->assertSessionHasNoErrors();
        $dept = Department::sole();
        $this->assertSame($this->org->id, $dept->organization_id);
        $this->assertSame($hod->id, $dept->head_membership_id);

        $this->actingAsMember($this->admin)->post('/settings/departments', ['name' => 'QA', 'parent_id' => $dept->id])->assertSessionHasNoErrors();
        $this->actingAsMember($this->admin)->put("/settings/departments/{$dept->id}", ['name' => 'Engineering', 'head_membership_id' => null])->assertSessionHasNoErrors();
        $this->assertSame('Engineering', $dept->fresh()->name);
        $this->assertNull($dept->fresh()->head_membership_id);

        $this->actingAsMember($this->admin)->get('/settings/departments')->assertOk()
            ->assertInertia(fn ($page) => $page->has('departments', 2));

        $qa = Department::where('name', 'QA')->first();
        $this->actingAsMember($this->admin)->delete("/settings/departments/{$qa->id}")->assertSessionHasNoErrors();
        $this->assertSoftDeleted($qa);
    }

    public function test_department_names_are_unique_per_company(): void
    {
        [$other] = $this->makeCompany();
        Department::factory()->create(['organization_id' => $other->id, 'name' => 'Software']);
        Department::factory()->create(['organization_id' => $this->org->id, 'name' => 'QA']);

        $this->actingAsMember($this->admin)->post('/settings/departments', ['name' => 'Software'])->assertSessionHasNoErrors(); // another company's name is fine
        $this->actingAsMember($this->admin)->post('/settings/departments', ['name' => 'QA'])->assertSessionHasErrors('name');
    }

    public function test_a_department_with_people_or_children_cannot_be_deleted(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);
        $child = Department::factory()->create(['organization_id' => $this->org->id, 'parent_id' => $dept->id]);
        $this->makeMember($this->org, 'developer', ['department_id' => $child->id]);

        $this->actingAsMember($this->admin)->delete("/settings/departments/{$dept->id}")->assertSessionHasErrors('department');
        $this->actingAsMember($this->admin)->delete("/settings/departments/{$child->id}")->assertSessionHasErrors('department');
        $this->assertNotSoftDeleted($dept);
        $this->assertNotSoftDeleted($child);
    }

    public function test_a_department_cannot_sit_under_its_own_descendant(): void
    {
        $a = Department::factory()->create(['organization_id' => $this->org->id, 'name' => 'A']);
        $b = Department::factory()->create(['organization_id' => $this->org->id, 'name' => 'B', 'parent_id' => $a->id]);

        $this->actingAsMember($this->admin)->put("/settings/departments/{$a->id}", ['name' => 'A', 'parent_id' => $b->id])->assertSessionHasErrors('parent_id');
        $this->actingAsMember($this->admin)->put("/settings/departments/{$a->id}", ['name' => 'A', 'parent_id' => $a->id])->assertSessionHasErrors('parent_id');
    }

    public function test_the_department_head_must_be_an_active_member_of_the_company(): void
    {
        [$other] = $this->makeCompany();
        $foreigner = $this->makeMember($other, 'hod');

        $this->actingAsMember($this->admin)->post('/settings/departments', ['name' => 'X', 'head_membership_id' => $foreigner->id])->assertSessionHasErrors('head_membership_id');
    }

    public function test_department_permissions_and_tenant_isolation(): void
    {
        $dev = $this->makeMember($this->org, 'developer'); // view only
        $hod = $this->makeMember($this->org, 'hod'); // manages departments
        [$other] = $this->makeCompany();
        $foreign = Department::factory()->create(['organization_id' => $other->id]);

        $this->actingAsMember($dev)->get('/settings/departments')->assertOk();
        $this->actingAsMember($dev)->post('/settings/departments', ['name' => 'X'])->assertForbidden();
        $this->actingAsMember($hod)->post('/settings/departments', ['name' => 'X'])->assertSessionHasNoErrors();
        $this->actingAsMember($hod)->put("/settings/departments/{$foreign->id}", ['name' => 'Hacked'])->assertNotFound();
        $this->actingAsMember($hod)->delete("/settings/departments/{$foreign->id}")->assertNotFound();
        $this->assertNotSame('Hacked', $foreign->fresh()->name);
    }

    // ---- company settings ------------------------------------------------------------------

    public function test_company_settings_can_be_saved_with_a_logo(): void
    {
        Storage::fake('public');

        $this->actingAsMember($this->admin)->get('/settings/company')->assertOk()->assertInertia(fn ($page) => $page->component('Settings/Company'));

        $this->actingAsMember($this->admin)->post('/settings/company', [
            'timezone' => 'Europe/London', 'date_format' => 'Y-m-d', 'working_days' => [1, 2, 3, 4, 5],
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ])->assertSessionHasNoErrors();

        $this->org->refresh();
        $this->assertSame('Europe/London', $this->org->timezone);
        $this->assertSame('Y-m-d', $this->org->date_format);
        $this->assertSame([1, 2, 3, 4, 5], $this->org->working_days);
        Storage::disk('public')->assertExists($this->org->logo_path);

        $old = $this->org->logo_path;
        $this->actingAsMember($this->admin)->post('/settings/company', [
            'timezone' => 'Asia/Dhaka', 'date_format' => 'd M Y', 'working_days' => [0], 'remove_logo' => true,
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->org->fresh()->logo_path);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_company_settings_are_validated_and_permission_checked(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $payload = ['timezone' => 'Asia/Dhaka', 'date_format' => 'd M Y', 'working_days' => [0, 1]];

        $this->actingAsMember($dev)->get('/settings/company')->assertForbidden();
        $this->actingAsMember($dev)->post('/settings/company', $payload)->assertForbidden();

        $this->actingAsMember($this->admin)->post('/settings/company', ['timezone' => 'Mars/Base', 'date_format' => 'zzz', 'working_days' => []])
            ->assertSessionHasErrors(['timezone', 'date_format', 'working_days']);
        $this->actingAsMember($this->admin)->post('/settings/company', $payload + ['logo' => UploadedFile::fake()->create('x.exe', 10)])
            ->assertSessionHasErrors('logo');
    }

    public function test_the_logo_is_shared_with_the_frontend(): void
    {
        Storage::fake('public');
        $this->actingAsMember($this->admin)->post('/settings/company', [
            'timezone' => 'Asia/Dhaka', 'date_format' => 'd M Y', 'working_days' => [0, 1], 'logo' => UploadedFile::fake()->image('l.png'),
        ]);

        $this->actingAsMember($this->admin)->get('/org-chart')
            ->assertInertia(fn ($page) => $page->whereNot('currentOrganization.logo_url', null));
    }

    // ---- onboarding ------------------------------------------------------------------------

    public function test_the_wizard_starts_once_and_steps_can_be_skipped_or_finished(): void
    {
        $this->actingAsMember($this->admin)->get('/dashboard')->assertRedirect(route('onboarding.show'));

        $this->actingAsMember($this->admin)->get('/settings/onboarding')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Settings/Onboarding')->has('steps', 6)->where('status', 'in_progress'));

        $this->actingAsMember($this->admin)->post('/settings/onboarding', ['step' => 'roles', 'status' => 'skipped'])->assertSessionHasNoErrors();
        $this->actingAsMember($this->admin)->get('/settings/onboarding')
            ->assertInertia(fn ($page) => $page->where('steps.2.status', 'skipped'));

        $this->actingAsMember($this->admin)->post('/settings/onboarding', ['finish' => true])->assertRedirect(route('dashboard'));
        $this->actingAsMember($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('onboarding', 'done'));
    }

    public function test_wizard_steps_tick_themselves_when_the_work_is_done(): void
    {
        $this->actingAsMember($this->admin)->get('/dashboard');
        Department::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAsMember($this->admin)->get('/settings/onboarding')
            ->assertInertia(fn ($page) => $page->where('steps.1.key', 'departments')->where('steps.1.status', 'done')->where('steps.0.status', 'todo'));
    }

    public function test_only_people_who_edit_company_settings_get_the_wizard(): void
    {
        $dev = $this->makeMember($this->org, 'developer');

        $this->actingAsMember($dev)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('onboarding', null));
        $this->actingAsMember($dev)->get('/settings/onboarding')->assertForbidden();
        $this->actingAsMember($dev)->post('/settings/onboarding', ['finish' => true])->assertForbidden();
    }

    public function test_the_profile_shows_employment_history(): void
    {
        $this->actingAsMember($this->admin)->get('/profile')->assertOk()
            ->assertInertia(fn ($page) => $page->has('employment.periods', 1)->where('employment.role', 'Company Admin'));
    }
}
