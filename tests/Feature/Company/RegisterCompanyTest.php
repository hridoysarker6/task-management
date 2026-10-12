<?php

namespace Tests\Feature\Company;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\CompanyRegistered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegisterCompanyTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'ABC Soft Ltd',
            'slug' => 'abc-soft',
            'industry' => 'Software',
            'size' => '11-50',
            'address' => 'Dhaka',
            'phone' => '0123456789',
            'email' => 'info@abc.test',
            'website' => 'https://abc.test',
            'registration_no' => 'TL-123',
            'contact_name' => 'Rahim Uddin',
            'contact_designation' => 'CEO',
            'contact_email' => 'rahim@abc.test',
            'contact_phone' => '0111111111',
            'password' => 'password',
            'password_confirmation' => 'password',
            'accept_terms' => true,
        ], $overrides);
    }

    public function test_registration_page_renders(): void
    {
        $this->get('/register-company')->assertOk();
    }

    public function test_a_company_can_register(): void
    {
        Storage::fake('local');
        Notification::fake();

        $response = $this->post('/register-company', $this->payload([
            'trade_license' => UploadedFile::fake()->create('license.pdf', 100, 'application/pdf'),
            'other_documents' => [UploadedFile::fake()->image('tax.png')],
        ]));

        $response->assertRedirect(route('verification.notice'));
        $this->assertAuthenticated();

        $organization = Organization::where('slug', 'abc-soft')->firstOrFail();
        $this->assertSame(Organization::PENDING, $organization->status);
        $this->assertSame('CEO', $organization->settings['contact_designation']);
        $this->assertSame('rahim@abc.test', $organization->applicant->email);
        $this->assertNull($organization->applicant->email_verified_at);
        $this->assertFalse($organization->applicant->is_system_admin);

        $this->assertCount(2, $organization->documents);
        $this->assertSame(['submitted'], $organization->reviews->pluck('action')->all());
        Storage::disk('local')->assertExists($organization->documents->firstWhere('type', 'trade_license')->path);

        Notification::assertSentTo($organization->applicant, VerifyEmail::class);
        Notification::assertNothingSentTo(User::factory()->systemAdmin()->create()); // not yet: email is unverified
    }

    public function test_system_admins_are_notified_only_after_the_email_is_verified(): void
    {
        Notification::fake();
        $admin = User::factory()->systemAdmin()->create();

        $this->post('/register-company', $this->payload());
        Notification::assertNotSentTo($admin, CompanyRegistered::class);

        $applicant = User::where('email', 'rahim@abc.test')->firstOrFail();
        event(new Verified($applicant));

        Notification::assertSentTo($admin, CompanyRegistered::class);
    }

    public function test_company_name_and_contact_are_required(): void
    {
        $this->post('/register-company', [])
            ->assertSessionHasErrors(['name', 'slug', 'contact_name', 'contact_email', 'password', 'accept_terms']);

        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_slug_must_be_unique_url_safe_and_not_reserved(): void
    {
        Organization::factory()->create(['slug' => 'abc-soft']);

        $this->post('/register-company', $this->payload())->assertSessionHasErrors('slug');
        $this->post('/register-company', $this->payload(['slug' => 'Has Spaces']))->assertSessionHasErrors('slug');
        $this->post('/register-company', $this->payload(['slug' => 'admin']))->assertSessionHasErrors('slug');
    }

    public function test_contact_email_must_not_already_have_an_account(): void
    {
        User::factory()->create(['email' => 'rahim@abc.test']);

        $this->post('/register-company', $this->payload())->assertSessionHasErrors('contact_email');
    }

    public function test_terms_must_be_accepted(): void
    {
        $this->post('/register-company', $this->payload(['accept_terms' => false]))->assertSessionHasErrors('accept_terms');
    }

    public function test_documents_are_limited_by_type_and_size(): void
    {
        $this->post('/register-company', $this->payload([
            'trade_license' => UploadedFile::fake()->create('virus.exe', 10),
        ]))->assertSessionHasErrors('trade_license');

        $this->post('/register-company', $this->payload([
            'trade_license' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'),
        ]))->assertSessionHasErrors('trade_license');
    }

    public function test_signed_in_users_cannot_open_the_registration_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/register-company')->assertRedirect();
    }
}
