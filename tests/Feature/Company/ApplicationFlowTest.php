<?php

namespace Tests\Feature\Company;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\CompanyRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ApplicationFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function applicant(string $status): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->status($status)->create(['applicant_user_id' => $user->id]);

        return [$user, $organization];
    }

    public function test_unverified_applicants_are_asked_to_verify_first(): void
    {
        $user = User::factory()->unverified()->create();
        Organization::factory()->create(['applicant_user_id' => $user->id]);

        $this->actingAs($user)->get('/awaiting-approval')->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('verification.notice'));
    }

    public function test_pending_applicants_land_on_the_awaiting_page(): void
    {
        [$user] = $this->applicant(Organization::PENDING);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('application.show'));
        $this->actingAs($user)->get('/awaiting-approval')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Company/Awaiting')->where('organization.status', 'pending')->where('organization.canEdit', false));
    }

    public function test_pending_applicants_cannot_use_company_screens(): void
    {
        [$user] = $this->applicant(Organization::PENDING);

        $this->actingAs($user)->get('/settings/roles')->assertRedirect(route('dashboard'));
    }

    public function test_rejected_applicants_see_the_reason_and_may_edit(): void
    {
        [$user, $organization] = $this->applicant(Organization::REJECTED);
        $organization->reviews()->create(['action' => 'rejected', 'note' => 'License unreadable', 'acted_by' => $user->id]);

        $this->actingAs($user)->get('/awaiting-approval')
            ->assertInertia(fn ($page) => $page->where('note', 'License unreadable')->where('organization.canEdit', true));

        $this->actingAs($user)->get('/application/edit')->assertOk();
    }

    public function test_pending_applicants_cannot_edit(): void
    {
        [$user] = $this->applicant(Organization::PENDING);

        $this->actingAs($user)->get('/application/edit')->assertRedirect(route('application.show'));
        $this->actingAs($user)->put('/application', ['name' => 'X', 'slug' => 'x'])->assertForbidden();
    }

    public function test_applicant_can_edit_and_resubmit_after_info_is_requested(): void
    {
        Notification::fake();
        $admin = User::factory()->systemAdmin()->create();
        [$user, $organization] = $this->applicant(Organization::INFO_REQUESTED);

        $this->actingAs($user)->put('/application', [
            'name' => 'Better Name Ltd',
            'slug' => $organization->slug,
            'registration_no' => 'TL-999',
            'note' => 'Added the licence number',
        ])->assertRedirect(route('application.show'));

        $organization->refresh();
        $this->assertSame(Organization::PENDING, $organization->status);
        $this->assertSame('Better Name Ltd', $organization->name);
        $this->assertSame('resubmitted', $organization->reviews()->first()->action);
        Notification::assertSentTo($admin, CompanyRegistered::class, fn ($n) => $n->resubmitted);
    }

    public function test_resubmitting_requires_valid_data(): void
    {
        [$user] = $this->applicant(Organization::REJECTED);

        $this->actingAs($user)->put('/application', ['name' => '', 'slug' => 'Bad Slug'])
            ->assertSessionHasErrors(['name', 'slug']);
    }

    public function test_active_company_members_go_to_the_dashboard_not_the_awaiting_page(): void
    {
        $this->seedRoleTemplate();
        [, $admin] = $this->makeCompany();

        $this->actingAsMember($admin)->get('/dashboard')->assertOk();
        $this->actingAsMember($admin)->get('/awaiting-approval')->assertRedirect(route('dashboard'));
    }
}
