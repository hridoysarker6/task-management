<?php

namespace Tests\Feature\Company;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class SuspendedAccessTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
    }

    public function test_members_of_a_suspended_company_cannot_log_in(): void
    {
        [$organization, $admin] = $this->makeCompany();
        $organization->forceFill(['status' => Organization::SUSPENDED])->save();

        $this->post('/login', ['email' => $admin->user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_already_signed_in_member_is_signed_out_when_the_company_is_suspended(): void
    {
        [$organization, $admin] = $this->makeCompany();
        $organization->forceFill(['status' => Organization::SUSPENDED])->save();

        $this->actingAsMember($admin)->get('/dashboard')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_a_user_in_two_companies_can_still_use_the_active_one(): void
    {
        [$suspended, $admin] = $this->makeCompany();
        [$active] = $this->makeCompany();
        $role = Role::withoutGlobalScopes()->where('organization_id', $active->id)->where('slug', 'developer')->first();
        Membership::withoutGlobalScopes()->create([
            'organization_id' => $active->id, 'user_id' => $admin->user_id, 'role_id' => $role->id,
            'status' => Membership::ACTIVE,
        ]);
        $suspended->forceFill(['status' => Organization::SUSPENDED])->save();

        $this->post('/login', ['email' => $admin->user->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();

        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentOrganization.id', $active->id));
    }

    public function test_locked_accounts_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['status' => 'locked'])->save();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_rejected_applicants_cannot_reach_company_screens(): void
    {
        $user = User::factory()->create();
        Organization::factory()->status(Organization::REJECTED)->create(['applicant_user_id' => $user->id]);

        $this->actingAs($user)->get('/settings/roles')->assertRedirect(route('dashboard'));
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('application.show'));
    }

    public function test_login_records_the_time(): void
    {
        [, $admin] = $this->makeCompany();

        $this->post('/login', ['email' => $admin->user->email, 'password' => 'password']);

        $this->assertNotNull($admin->user->fresh()->last_login_at);
    }

    public function test_switching_company_requires_membership(): void
    {
        [, $admin] = $this->makeCompany();
        [$other] = $this->makeCompany();

        $this->actingAsMember($admin)->post(route('organizations.switch', $other))->assertForbidden();
    }
}
