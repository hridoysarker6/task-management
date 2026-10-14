<?php

namespace Tests\Feature\People;

use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class ImportTest extends TestCase
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

    private function csv(array $lines, string $header = 'name,email,role,department,reports_to_email,type,designation,employee_code'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('people.csv', $header."\n".implode("\n", $lines)."\n");
    }

    /** Upload and return the preview token. */
    private function preview(UploadedFile $file): string
    {
        $response = $this->actingAsMember($this->admin)->post('/people/import', ['file' => $file]);
        $response->assertSessionHasNoErrors();

        return basename($response->headers->get('Location'));
    }

    public function test_the_upload_page_and_template_are_available(): void
    {
        $this->actingAsMember($this->admin)->get('/people/import')->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Import')->where('stage', 'upload'));

        $this->actingAsMember($this->admin)->get('/people/import/template')->assertOk()->assertDownload('people-import-template.csv');
    }

    public function test_only_people_with_the_import_permission_can_use_it(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead');

        $this->actingAsMember($tl)->get('/people/import')->assertForbidden();
        $this->actingAsMember($tl)->post('/people/import', ['file' => $this->csv(['A,a@example.com,Developer,,,,,'])])->assertForbidden();
    }

    public function test_the_preview_marks_valid_and_invalid_rows(): void
    {
        $token = $this->preview($this->csv([
            'Alice,alice@example.com,Developer,,,internal,Dev,E-1',
            'Bad,not-an-email,Developer,,,,,',
            'NoRole,norole@example.com,Wizard,,,,,',
            'Client,client@example.com,External Stakeholder,,,external,,',
        ]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Import')->where('stage', 'preview')
                ->where('validCount', 2)->where('invalidCount', 2)
                ->where('rows.0.errors', [])
                ->where('rows.1.errors.0', 'Email is missing or not valid.')
                ->where('rows.2.errors.0', 'Unknown role "Wizard".')
                ->where('rows.3.data.user_type', 'external'));
    }

    public function test_the_preview_changes_nothing(): void
    {
        Notification::fake();
        $this->preview($this->csv(['Alice,alice@example.com,Developer,,,,,']));

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
        Notification::assertNothingSent();
    }

    public function test_confirming_invites_the_valid_rows_and_reports_the_rest(): void
    {
        Notification::fake();
        $token = $this->preview($this->csv([
            'Alice,alice@example.com,Developer,,,,Backend,E-1',
            'Broken,,Developer,,,,,',
            'Bob,bob@example.com,team-lead,,,,,',
        ]));

        $this->actingAsMember($this->admin)->post("/people/import/{$token}")->assertRedirect(route('people.import.show', $token));

        $this->assertSame(2, Invitation::withoutGlobalScopes()->count());
        $alice = Invitation::withoutGlobalScopes()->where('email', 'alice@example.com')->first();
        $this->assertSame('Alice', $alice->name);
        $this->assertSame('E-1', $alice->employee_code);
        $this->assertSame('Backend', $alice->designation);
        $this->assertSame($this->org->id, $alice->organization_id);
        Notification::assertSentOnDemandTimes(UserInvited::class, 2);

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('stage', 'result')->where('created', 2)->has('failed', 1)->where('failed.0.line', 3));

        // A finished import cannot be run twice.
        $this->actingAsMember($this->admin)->post("/people/import/{$token}")->assertNotFound();
        $this->assertSame(2, Invitation::withoutGlobalScopes()->count());
    }

    public function test_the_error_report_lists_the_failed_rows_with_reasons(): void
    {
        $token = $this->preview($this->csv(['Broken,,Developer,,,,,', 'Alice,alice@example.com,Developer,,,,,']));

        $response = $this->actingAsMember($this->admin)->get("/people/import/{$token}/errors");
        $response->assertOk()->assertDownload('people-import-errors.csv');

        $body = $response->streamedContent();
        $this->assertStringContainsString('errors', $body);
        $this->assertStringContainsString('Email is missing or not valid.', $body);
        $this->assertStringNotContainsString('alice@example.com', $body);
    }

    public function test_duplicates_in_the_file_and_existing_people_are_caught(): void
    {
        $existing = $this->makeMember($this->org, 'developer');
        Notification::fake();
        $this->actingAsMember($this->admin)->post('/people/invitations', ['email' => 'pending@example.com', 'role_id' => $this->role('developer')->id]);

        $token = $this->preview($this->csv([
            'A,dup@example.com,Developer,,,,,',
            'B,DUP@example.com,Developer,,,,,',
            "C,{$existing->user->email},Developer,,,,,",
            'D,pending@example.com,Developer,,,,,',
        ]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('validCount', 1)
                ->where('rows.1.errors.0', 'Email repeated (first on line 2).')
                ->where('rows.2.errors.0', 'This person is already a member.')
                ->where('rows.3.errors.0', 'This email already has a pending invitation.'));
    }

    public function test_former_employees_are_pointed_to_rehire(): void
    {
        $former = $this->makeMember($this->org, 'developer');
        $former->forceFill(['status' => Membership::EXITED])->save();
        $former->delete();

        $token = $this->preview($this->csv(["Old,{$former->user->email},Developer,,,,,"]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('rows.0.errors.0', 'This is a former employee. Use Rehire instead.'));
    }

    public function test_role_department_and_type_must_match(): void
    {
        Department::factory()->create(['organization_id' => $this->org->id, 'name' => 'Software']);

        $token = $this->preview($this->csv([
            'A,a@example.com,Developer,Software,,internal,,',
            'B,b@example.com,Developer,Nowhere,,,,',
            'C,c@example.com,Developer,,,external,,',
            'D,d@example.com,Developer,,,sideways,,',
        ]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('rows.0.errors', [])->where('rows.0.data.department', 'Software')
                ->where('rows.1.errors.0', 'Unknown department "Nowhere".')
                ->where('rows.2.errors.0', 'Type "external" does not match the role "Developer".')
                ->where('rows.3.errors.0', 'Type must be "internal" or "external".'));
    }

    public function test_a_manager_can_be_an_existing_member_or_someone_else_in_the_file(): void
    {
        Notification::fake();
        $dm = $this->makeMember($this->org, 'dm');

        $token = $this->preview($this->csv([
            "TL,tl@example.com,Team Lead,,{$dm->user->email},,,",
            'Dev,dev@example.com,Developer,,tl@example.com,,,',
            'Stray,stray@example.com,Developer,,nobody@example.com,,,',
        ]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('rows.0.errors', [])->where('rows.0.data.reports_to_id', $dm->id)
                ->where('rows.1.errors', [])->where('rows.1.data.reports_to_email', 'tl@example.com')
                ->where('rows.2.errors.0', 'Manager nobody@example.com is not a member and not in this file.'));

        $this->actingAsMember($this->admin)->post("/people/import/{$token}");
        $this->assertSame($dm->id, Invitation::withoutGlobalScopes()->where('email', 'tl@example.com')->value('reports_to_id'));
        $this->assertSame('tl@example.com', Invitation::withoutGlobalScopes()->where('email', 'dev@example.com')->value('reports_to_email'));
    }

    public function test_a_manager_listed_in_the_file_is_linked_once_they_accept(): void
    {
        Notification::fake();
        $token = $this->preview($this->csv([
            'TL,tl@example.com,Team Lead,,,,,',
            'Dev,dev@example.com,Developer,,tl@example.com,,,',
        ]));
        $this->actingAsMember($this->admin)->post("/people/import/{$token}");

        $tokens = [];
        Notification::assertSentOnDemand(UserInvited::class, function ($n, $c, $notifiable) use (&$tokens) {
            $tokens[$notifiable->routes['mail']] = $n->invitation->plainToken;

            return true;
        });
        auth()->logout();
        $pw = ['name' => 'X', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'];

        // The manager joins first, then the report: the link resolves.
        $this->post("/invitations/{$tokens['tl@example.com']}/accept", $pw);
        auth()->logout();
        $this->post("/invitations/{$tokens['dev@example.com']}/accept", $pw);

        $tl = Membership::withoutGlobalScopes()->where('user_id', User::where('email', 'tl@example.com')->value('id'))->first();
        $dev = Membership::withoutGlobalScopes()->where('user_id', User::where('email', 'dev@example.com')->value('id'))->first();
        $this->assertSame($tl->id, $dev->reports_to_id);
        $this->assertSame([$dev->id => 0, $tl->id => 1], $this->closureOf($dev));
    }

    public function test_a_manager_must_outrank_the_row(): void
    {
        $dev = $this->makeMember($this->org, 'developer');

        $token = $this->preview($this->csv([
            "TL,tl@example.com,Team Lead,,{$dev->user->email},,,",
            'DevA,a@example.com,Developer,,b@example.com,,,',
            'DevB,b@example.com,Developer,,,,,',
        ]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->has('rows.0.errors', 1)->has('rows.1.errors', 1));
    }

    public function test_external_rows_cannot_name_a_manager(): void
    {
        $dm = $this->makeMember($this->org, 'dm');
        $token = $this->preview($this->csv(["Client,c@example.com,External Stakeholder,,{$dm->user->email},external,,"]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('rows.0.errors.0', 'External people cannot report to anyone.'));
    }

    public function test_the_user_limit_stops_the_import_part_way(): void
    {
        Notification::fake();
        $this->org->forceFill(['user_limit' => 3])->save(); // admin + two seats

        $token = $this->preview($this->csv([
            'A,a@example.com,Developer,,,,,',
            'B,b@example.com,Developer,,,,,',
            'C,c@example.com,Developer,,,,,',
        ]));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('validCount', 2)->where('rows.2.errors.0', 'User limit (3) reached.'));

        $this->actingAsMember($this->admin)->post("/people/import/{$token}");
        $this->assertSame(2, Invitation::withoutGlobalScopes()->count());
    }

    public function test_employee_codes_must_be_unique(): void
    {
        $this->makeMember($this->org, 'developer', ['employee_code' => 'E-1']);

        $token = $this->preview($this->csv(['A,a@example.com,Developer,,,,,E-1', 'B,b@example.com,Developer,,,,,E-2', 'C,c@example.com,Developer,,,,,E-2']));

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('rows.0.errors.0', 'Employee code is already in use.')->where('rows.1.errors', [])
                ->where('rows.2.errors.0', 'Employee code repeated (first on line 3).'));
    }

    public function test_you_cannot_import_into_a_more_senior_role(): void
    {
        $dm = $this->makeMember($this->org, 'team-lead');
        $response = $this->actingAsMember($dm)->post('/people/import', ['file' => $this->csv(['A,a@example.com,HOD,,,,,'])]);

        // Team leads do not hold user.import by default...
        $response->assertForbidden();

        // ...so grant it and check the rank rule.
        app(PermissionService::class)->override($dm, 'user.import', 'grant');
        $token = basename($this->actingAsMember($dm)->post('/people/import', ['file' => $this->csv(['A,a@example.com,HOD,,,,,'])])->headers->get('Location'));

        $this->actingAsMember($dm)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('rows.0.errors.0', 'You cannot invite into the more senior role "HOD".'));
    }

    public function test_bad_files_are_rejected_with_a_message(): void
    {
        $this->actingAsMember($this->admin)->post('/people/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "name,phone\nA,1\n")])
            ->assertSessionHasErrors('file');
        $this->actingAsMember($this->admin)->post('/people/import', ['file' => UploadedFile::fake()->createWithContent('x.csv', "email,role\n")])
            ->assertSessionHasErrors('file');
        $this->actingAsMember($this->admin)->post('/people/import', ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('file');
        $this->actingAsMember($this->admin)->post('/people/import', [])->assertSessionHasErrors('file');
    }

    public function test_column_names_are_flexible_and_a_bom_is_tolerated(): void
    {
        $file = UploadedFile::fake()->createWithContent('x.csv', "\xEF\xBB\xBFE-mail,Role,Reports To,Job Title\na@example.com,Developer,,Engineer\n");
        $token = $this->preview($file);

        $this->actingAsMember($this->admin)->get("/people/import/{$token}")
            ->assertInertia(fn ($page) => $page->where('rows.0.errors', [])->where('rows.0.data.designation', 'Engineer'));
    }

    public function test_an_expired_preview_sends_you_back_to_upload(): void
    {
        $this->actingAsMember($this->admin)->get('/people/import/does-not-exist')->assertRedirect(route('people.import.create'));
    }

    public function test_one_company_cannot_open_anothers_preview(): void
    {
        $token = $this->preview($this->csv(['A,a@example.com,Developer,,,,,']));
        [, $otherAdmin] = $this->makeCompany();

        $this->actingAsMember($otherAdmin)->get("/people/import/{$token}")->assertRedirect(route('people.import.create'));
        $this->actingAsMember($otherAdmin)->post("/people/import/{$token}")->assertNotFound();
    }

    private function role(string $slug): Role
    {
        return Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', $slug)->firstOrFail();
    }
}
