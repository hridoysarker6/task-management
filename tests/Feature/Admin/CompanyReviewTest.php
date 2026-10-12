<?php

namespace Tests\Feature\Admin;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\OrganizationDocument;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CompanyApproved;
use App\Notifications\CompanyInfoRequested;
use App\Notifications\CompanyRejected;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class CompanyReviewTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        $this->admin = User::factory()->systemAdmin()->create();
    }

    private function pending(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    public function test_only_system_admins_can_open_the_admin_area(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/admin/companies')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin')->assertOk();
    }

    public function test_system_admins_land_in_the_admin_area_from_the_dashboard(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertRedirect(route('admin.dashboard'));
    }

    public function test_company_members_cannot_use_admin_actions(): void
    {
        [$organization, $companyAdmin] = $this->makeCompany();

        $this->actingAsMember($companyAdmin)->post(route('admin.companies.approve', $organization))->assertForbidden();
        $this->actingAsMember($companyAdmin)->get(route('admin.role-template.index'))->assertForbidden();
    }

    public function test_companies_list_filters_by_tab_and_search(): void
    {
        $this->pending(['name' => 'Alpha Pending']);
        $this->pending(['name' => 'Beta Pending']);
        Organization::factory()->active()->create(['name' => 'Gamma Active']);

        $this->actingAs($this->admin)->get('/admin/companies')
            ->assertInertia(fn ($page) => $page->component('Admin/Companies/Index')
                ->where('status', 'pending')
                ->has('companies.data', 2)
                ->where('tabs.0.count', 2));

        $this->actingAs($this->admin)->get('/admin/companies?status=active')
            ->assertInertia(fn ($page) => $page->has('companies.data', 1)->where('companies.data.0.name', 'Gamma Active'));

        $this->actingAs($this->admin)->get('/admin/companies?q=Beta')
            ->assertInertia(fn ($page) => $page->has('companies.data', 1)->where('companies.data.0.name', 'Beta Pending'));

        $this->actingAs($this->admin)->get('/admin/companies?status=bogus')->assertNotFound();
    }

    public function test_company_detail_shows_info_documents_and_history(): void
    {
        $organization = $this->pending();
        OrganizationDocument::factory()->create(['organization_id' => $organization->id, 'uploaded_by' => $organization->applicant_user_id]);
        $organization->reviews()->create(['action' => 'submitted', 'acted_by' => $organization->applicant_user_id]);

        $this->actingAs($this->admin)->get(route('admin.companies.show', $organization))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Companies/Show')
                ->where('company.name', $organization->name)
                ->has('documents', 1)
                ->has('reviews', 1));
    }

    public function test_documents_can_be_downloaded_only_through_their_own_company(): void
    {
        Storage::fake('local');
        $organization = $this->pending();
        $other = $this->pending();
        Storage::put('organization-documents/x.pdf', 'pdf-bytes');
        $document = OrganizationDocument::factory()->create([
            'organization_id' => $organization->id,
            'uploaded_by' => $organization->applicant_user_id,
            'path' => 'organization-documents/x.pdf',
            'original_name' => 'license.pdf',
        ]);

        $this->actingAs($this->admin)->get(route('admin.companies.documents.download', [$organization, $document]))->assertOk();
        $this->actingAs($this->admin)->get(route('admin.companies.documents.download', [$other, $document]))->assertNotFound();
    }

    public function test_approving_provisions_the_company(): void
    {
        Notification::fake();
        $organization = $this->pending();

        $this->actingAs($this->admin)->post(route('admin.companies.approve', $organization))->assertSessionHasNoErrors();

        $organization->refresh();
        $this->assertSame(Organization::ACTIVE, $organization->status);
        $this->assertSame($this->admin->id, $organization->approved_by);
        $this->assertNotNull($organization->approved_at);

        // The default role template was copied.
        $roles = Role::withoutGlobalScopes()->where('organization_id', $organization->id)->get();
        $this->assertCount(10, $roles);
        $this->assertTrue($roles->firstWhere('slug', 'company-admin')->is_locked);
        $this->assertGreaterThan(0, $roles->firstWhere('slug', 'developer')->permissions()->count());

        // The contact person is now its Company Admin.
        $membership = Membership::withoutGlobalScopes()->where('organization_id', $organization->id)->sole();
        $this->assertSame($organization->applicant_user_id, $membership->user_id);
        $this->assertSame('company-admin', $membership->role->slug);
        $this->assertSame(Membership::ACTIVE, $membership->status);
        $this->assertSame($organization->id, $organization->applicant->fresh()->last_organization_id);

        $this->assertSame('approved', $organization->reviews()->first()->action);
        Notification::assertSentTo($organization->applicant, CompanyApproved::class);
    }

    public function test_the_applicant_can_sign_in_to_the_approved_company(): void
    {
        Notification::fake();
        $organization = $this->pending();

        $this->actingAs($this->admin)->post(route('admin.companies.approve', $organization));

        $this->actingAs($organization->applicant->fresh())->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentOrganization.id', $organization->id)->where('auth.role', 'Company Admin'));
    }

    public function test_only_pending_applications_can_be_approved(): void
    {
        $active = Organization::factory()->active()->create();

        $this->actingAs($this->admin)->post(route('admin.companies.approve', $active))->assertSessionHasErrors('status');
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_rejecting_needs_a_reason_and_emails_the_applicant(): void
    {
        Notification::fake();
        $organization = $this->pending();

        $this->actingAs($this->admin)->post(route('admin.companies.reject', $organization), [])->assertSessionHasErrors('reason');
        $this->assertSame(Organization::PENDING, $organization->fresh()->status);

        $this->actingAs($this->admin)->post(route('admin.companies.reject', $organization), ['reason' => 'Licence is expired'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Organization::REJECTED, $organization->fresh()->status);
        $this->assertSame('Licence is expired', $organization->reviews()->first()->note);
        Notification::assertSentTo($organization->applicant, CompanyRejected::class);
        $this->assertDatabaseCount('memberships', 0);
    }

    public function test_requesting_info_needs_a_note_and_emails_the_applicant(): void
    {
        Notification::fake();
        $organization = $this->pending();

        $this->actingAs($this->admin)->post(route('admin.companies.request-info', $organization), [])->assertSessionHasErrors('note');

        $this->actingAs($this->admin)->post(route('admin.companies.request-info', $organization), ['note' => 'Please upload the licence'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Organization::INFO_REQUESTED, $organization->fresh()->status);
        Notification::assertSentTo($organization->applicant, CompanyInfoRequested::class);
    }

    public function test_suspend_and_reactivate(): void
    {
        [$organization] = $this->makeCompany();

        $this->actingAs($this->admin)->post(route('admin.companies.suspend', $organization), ['note' => 'Unpaid'])->assertSessionHasNoErrors();
        $this->assertSame(Organization::SUSPENDED, $organization->fresh()->status);
        $this->assertNotNull($organization->fresh()->suspended_at);

        $this->actingAs($this->admin)->post(route('admin.companies.reactivate', $organization))->assertSessionHasNoErrors();
        $this->assertSame(Organization::ACTIVE, $organization->fresh()->status);
        $this->assertNull($organization->fresh()->suspended_at);

        $this->assertSame(['reactivated', 'suspended'], $organization->reviews()->pluck('action')->take(2)->all());
    }

    public function test_pending_companies_cannot_be_suspended_and_active_ones_cannot_be_reactivated(): void
    {
        $pending = $this->pending();
        $active = Organization::factory()->active()->create();

        $this->actingAs($this->admin)->post(route('admin.companies.suspend', $pending))->assertSessionHasErrors('status');
        $this->actingAs($this->admin)->post(route('admin.companies.reactivate', $active))->assertSessionHasErrors('status');
    }

    public function test_user_limit_can_be_set_and_cleared(): void
    {
        $organization = Organization::factory()->active()->create();

        $this->actingAs($this->admin)->patch(route('admin.companies.user-limit', $organization), ['user_limit' => 25]);
        $this->assertSame(25, $organization->fresh()->user_limit);

        $this->actingAs($this->admin)->patch(route('admin.companies.user-limit', $organization), ['user_limit' => null]);
        $this->assertNull($organization->fresh()->user_limit);

        $this->actingAs($this->admin)->patch(route('admin.companies.user-limit', $organization), ['user_limit' => 0])->assertSessionHasErrors('user_limit');
    }
}
