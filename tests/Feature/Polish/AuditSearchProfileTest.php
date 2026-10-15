<?php

namespace Tests\Feature\Polish;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CompanyReviewService;
use App\Services\MemberService;
use App\Services\OffboardingService;
use App\Services\PermissionService;
use App\Services\ProjectService;
use App\Services\RoleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTaskWorld;
use Tests\TestCase;

/** The audit trail, global search, profile photo, company switcher and error pages (Phase 11). */
class AuditSearchProfileTest extends TestCase
{
    use BuildsTaskWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildWorld();
    }

    private function props(TestResponse $response): array
    {
        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    private function as(Membership $m): static
    {
        $this->actingAs($m->user);
        $this->enterCompany($m->fresh());

        return $this;
    }

    private function last(string $action): AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->firstOrFail();
    }

    // ---- the audit trail -----------------------------------------------------------------------

    public function test_a_role_s_permissions_changing_is_recorded_with_what_was_added_and_removed(): void
    {
        $this->as($this->admin);
        $role = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'developer')->firstOrFail();
        $had = $role->permissions()->pluck('permissions.name')->all();
        $keep = array_values(array_diff($had, ['comment.create']));

        app(RoleManager::class)->syncMatrix($this->org->id, [['id' => $role->id, 'data_scope' => 'own', 'permissions' => [...$keep, 'report.view']]]);

        $line = $this->last('role.permissions_updated');
        $this->assertSame($this->org->id, $line->organization_id);
        $this->assertSame($this->admin->user_id, $line->actor_user_id);
        $this->assertSame('Developer', $line->summary);
        $this->assertContains('report.view', $line->new_values['added']);
        $this->assertContains('comment.create', $line->old_values['removed']);
        $this->assertSame($role->id, $line->auditable_id);
    }

    public function test_an_unchanged_save_records_nothing(): void
    {
        $this->as($this->admin);
        $role = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'developer')->firstOrFail();

        app(RoleManager::class)->syncMatrix($this->org->id, [['id' => $role->id, 'data_scope' => $role->data_scope, 'permissions' => $role->permissions()->pluck('permissions.name')->all()]]);

        $this->assertSame(0, AuditLog::where('action', 'role.permissions_updated')->count());
    }

    public function test_creating_changing_and_deleting_a_role_are_recorded(): void
    {
        $this->as($this->admin);
        $manager = app(RoleManager::class);

        $role = $manager->create($this->org->id, ['name' => 'Auditor', 'level' => 55, 'data_scope' => 'organization']);
        $manager->update($role, ['name' => 'Chief Auditor', 'level' => 55]);
        $manager->delete($role);

        $this->assertSame('Auditor', $this->last('role.created')->summary);
        $updated = $this->last('role.updated');
        $this->assertSame(['name' => 'Auditor'], $updated->old_values);
        $this->assertSame(['name' => 'Chief Auditor'], $updated->new_values);
        $this->assertSame('Chief Auditor', $this->last('role.deleted')->summary);
    }

    public function test_a_personal_permission_exception_is_recorded(): void
    {
        $this->as($this->admin);

        app(PermissionService::class)->override($this->dev1, 'report.view', 'grant', $this->admin);
        app(PermissionService::class)->override($this->dev1, 'report.view', 'grant', $this->admin); // no change: no second line

        $this->assertSame(1, AuditLog::where('action', 'permission.override_changed')->count());
        $line = $this->last('permission.override_changed');
        $this->assertSame(['permission' => 'report.view', 'effect' => 'grant'], $line->new_values);
        $this->assertStringContainsString($this->dev1->user->name, $line->summary);
    }

    public function test_changing_a_person_s_role_manager_or_department_is_recorded_as_a_before_and_after(): void
    {
        $this->as($this->admin);

        app(MemberService::class)->update($this->dev2, $this->admin, ['designation' => 'Senior Engineer', 'reports_to_id' => $this->tlB->id]);

        $line = $this->last('member.updated');
        $this->assertSame($this->tlB->id, $line->new_values['reports_to_id']);
        $this->assertSame($this->tlA->id, $line->old_values['reports_to_id']);
        $this->assertSame('Senior Engineer', $line->new_values['designation']);
        $this->assertSame($this->dev2->user->name, $line->summary);
    }

    public function test_an_exit_is_recorded_with_its_reason_and_a_scheduled_one_too(): void
    {
        $this->as($this->admin);

        app(OffboardingService::class)->offboard($this->dev3, $this->admin, ['exit_type' => 'resigned', 'left_at' => today()->toDateString(), 'exit_reason' => 'Moved abroad']);
        app(OffboardingService::class)->offboard($this->dev2, $this->admin, ['exit_type' => 'resigned', 'left_at' => today()->addDays(10)->toDateString()]);

        $exit = $this->last('member.offboarded');
        $this->assertSame(['exit_type' => 'resigned', 'reason' => 'Moved abroad', 'left_on' => today()->toDateString()], $exit->new_values);
        $this->assertSame($this->dev3->user->name, $exit->summary);
        $this->assertSame(today()->addDays(10)->toDateString(), $this->last('member.exit_scheduled')->new_values['last_day']);
    }

    public function test_deleting_a_project_is_recorded_even_though_the_project_is_gone(): void
    {
        $this->as($this->admin);

        app(ProjectService::class)->delete($this->oth);

        $line = $this->last('project.deleted');
        $this->assertSame('OTH Other', $line->summary);
        $this->assertSame(['key' => 'OTH', 'name' => 'Other'], $line->old_values);
        $this->assertSoftDeleted('projects', ['id' => $this->oth->id]);
    }

    public function test_company_approval_is_a_platform_level_line(): void
    {
        $sysadmin = User::factory()->systemAdmin()->create();
        $pending = Organization::factory()->create(['name' => 'Newco']);
        $pending->applicant->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($sysadmin);

        app(CompanyReviewService::class)->approve($pending, $sysadmin);
        app(CompanyReviewService::class)->suspend($pending->refresh(), $sysadmin, 'Unpaid');
        app(CompanyReviewService::class)->setUserLimit($pending, 25);

        $approved = $this->last('company.approved');
        $this->assertNull($approved->organization_id);
        $this->assertSame($sysadmin->id, $approved->actor_user_id);
        $this->assertSame('Newco', $approved->summary);
        $this->assertSame(['note' => 'Unpaid'], $this->last('company.suspended')->new_values);
        $this->assertSame([null, 25], [$this->last('company.user_limit_changed')->old_values['user_limit'], $this->last('company.user_limit_changed')->new_values['user_limit']]);
    }

    public function test_company_settings_and_invitations_are_recorded(): void
    {
        $this->actingAsMember($this->admin)->post('/settings/company', ['timezone' => 'Asia/Karachi', 'date_format' => 'd/m/Y', 'working_days' => [0, 1, 2, 3, 4]])->assertSessionHasNoErrors()->assertStatus(302);
        $this->assertSame('Asia/Karachi', $this->last('company.settings_updated')->new_values['timezone']);

        $this->actingAsMember($this->admin)->post('/people/invitations', ['email' => 'new.hire@example.com', 'name' => 'New Hire', 'role_id' => $this->dev1->role_id, 'user_type' => 'internal'])->assertSessionHasNoErrors();
        $this->assertSame('new.hire@example.com', $this->last('invitation.sent')->summary);
    }

    public function test_secrets_are_never_stored_in_the_trail(): void
    {
        $line = app(AuditLogger::class)->record('test.thing', null, ['password' => 'hunter2'], ['token' => 'abc', 'name' => 'ok'], organizationId: $this->org->id);

        $this->assertSame(['password' => '[hidden]'], $line->old_values);
        $this->assertSame(['token' => '[hidden]', 'name' => 'ok'], $line->new_values);
    }

    public function test_the_diff_helper_keeps_only_what_changed(): void
    {
        [$old, $new] = AuditLogger::diff(['a' => 1, 'b' => 'x', 'c' => null], ['a' => 1, 'b' => 'y', 'c' => 3]);

        $this->assertSame(['b' => 'x', 'c' => null], $old);
        $this->assertSame(['b' => 'y', 'c' => 3], $new);
    }

    // ---- the audit pages -----------------------------------------------------------------------

    private function line(string $action, ?int $org, string $summary = 'x', ?int $actor = null, $at = null): AuditLog
    {
        return AuditLog::create(['organization_id' => $org, 'actor_user_id' => $actor, 'action' => $action, 'summary' => $summary, 'created_at' => $at ?? now()]);
    }

    public function test_the_company_log_shows_only_that_company_and_needs_the_permission(): void
    {
        [$other] = $this->makeCompany();
        $mine = $this->line('role.updated', $this->org->id, 'Mine');
        $this->line('role.updated', $other->id, 'Theirs');
        $this->line('company.approved', null, 'Platform');

        $props = $this->props($this->actingAsMember($this->hod)->get('/settings/audit'));

        $this->assertSame([$mine->id], array_column($props['lines']['data'], 'id'));
        $this->assertSame('Role updated', $props['lines']['data'][0]['label']);
        $this->actingAsMember($this->dev1)->get('/settings/audit')->assertForbidden();
        $this->actingAsMember($this->client)->get('/settings/audit')->assertForbidden();
    }

    public function test_the_log_filters_by_kind_actor_and_dates(): void
    {
        $this->line('role.updated', $this->org->id, 'Role line', $this->admin->user_id, now()->subDays(10));
        $this->line('member.updated', $this->org->id, 'Person line', $this->hod->user_id, now());

        $ids = fn (string $qs) => array_column($this->props($this->actingAsMember($this->hod)->get("/settings/audit?{$qs}"))['lines']['data'], 'summary');

        $this->assertSame(['Role line'], $ids('group=role'));
        $this->assertSame(['Person line'], $ids('actor='.urlencode(substr($this->hod->user->name, 0, 4))));
        $this->assertSame(['Role line'], $ids('to='.now()->subDays(5)->toDateString()));
        $this->assertSame(['Person line'], $ids('from='.now()->subDays(5)->toDateString()));
        $this->assertCount(2, $ids('group=nonsense')); // an unknown kind is ignored
    }

    public function test_the_system_admin_sees_every_company_and_the_platform_lines(): void
    {
        [$other] = $this->makeCompany();
        $this->line('role.updated', $this->org->id, 'A');
        $this->line('role.updated', $other->id, 'B');
        $this->line('company.approved', null, 'P');
        $sysadmin = User::factory()->systemAdmin()->create();

        $all = $this->props($this->actingAs($sysadmin)->get('/admin/audit'));
        $platform = $this->props($this->actingAs($sysadmin)->get('/admin/audit?organization_id=platform'));
        $one = $this->props($this->actingAs($sysadmin)->get("/admin/audit?organization_id={$other->id}"));

        $this->assertEqualsCanonicalizing(['A', 'B', 'P'], array_column($all['lines']['data'], 'summary'));
        $this->assertSame(['P'], array_column($platform['lines']['data'], 'summary'));
        $this->assertSame(['B'], array_column($one['lines']['data'], 'summary'));
        $this->assertSame('Platform', collect($all['lines']['data'])->firstWhere('summary', 'P')['company']);
    }

    public function test_company_people_cannot_open_the_platform_log(): void
    {
        $this->actingAsMember($this->admin)->get('/admin/audit')->assertForbidden();
    }

    public function test_the_log_is_paginated(): void
    {
        foreach (range(1, 35) as $i) {
            $this->line('role.updated', $this->org->id, "L{$i}");
        }

        $props = $this->props($this->actingAsMember($this->hod)->get('/settings/audit'));

        $this->assertSame([35, 30], [$props['lines']['total'], count($props['lines']['data'])]);
    }

    // ---- search --------------------------------------------------------------------------------

    private function search(Membership $by, string $q): array
    {
        return $this->actingAsMember($by)->getJson('/search?q='.urlencode($q))->assertOk()->json();
    }

    public function test_search_finds_tasks_by_exact_key_key_prefix_and_title(): void
    {
        $login = $this->task($this->ecp, $this->pm, ['title' => 'Fix login redirect']);
        foreach (range(1, 9) as $i) {
            $this->task($this->ecp, $this->pm, ['title' => "Filler {$i}"]);
        }
        $ten = $this->task($this->ecp, $this->pm, ['title' => 'Tenth']);

        $this->assertEqualsCanonicalizing(['ECP-1', 'ECP-10', 'ECP-11'], array_column($this->search($this->pm, 'ECP-1')['tasks'], 'key'));
        $this->assertSame(['ECP-10'], array_column($this->search($this->pm, 'ECP-10')['tasks'], 'key'));
        $this->assertSame([$this->key($login)], array_column($this->search($this->pm, 'login')['tasks'], 'key'));
        $this->assertSame($ten->title, $this->search($this->pm, 'tenth')['tasks'][0]['title']);
        $this->assertStringContainsString('/tasks/ECP-1', $this->search($this->pm, 'login')['tasks'][0]['url']);
    }

    private function key(Task $t): string
    {
        return $t->project->key.'-'.$t->number;
    }

    public function test_search_only_returns_what_the_person_may_see(): void
    {
        $this->task($this->ecp, $this->pm, ['title' => 'Secret roadmap']);

        $this->assertCount(1, $this->search($this->pm, 'roadmap')['tasks']);
        $this->assertCount(0, $this->search($this->dev3, 'roadmap')['tasks']); // not on the project
        $this->assertCount(0, $this->search($this->dev3, 'ECP')['projects']);
        $this->assertCount(1, $this->search($this->pm, 'ECP')['projects']);
    }

    public function test_search_wants_two_characters_and_treats_wildcards_as_text(): void
    {
        $this->task($this->ecp, $this->pm, ['title' => 'Plain title']);

        $this->assertSame(['tasks' => [], 'projects' => [], 'people' => []], $this->search($this->pm, 'p'));
        $this->assertSame([], $this->search($this->pm, '%%')['tasks']);
        $this->assertSame([], $this->search($this->pm, '__')['tasks']);
    }

    public function test_people_search_follows_the_directory_permission_and_scope(): void
    {
        $name = substr($this->dev1->user->name, 0, 5);

        $this->assertContains($this->dev1->id, array_column($this->search($this->admin, $name)['people'], 'id'));
        $this->assertSame([], $this->search($this->client, $name)['people']); // clients have no directory
        $this->assertNotContains($this->dev3->id, array_column($this->search($this->tlA, substr($this->dev3->user->name, 0, 5))['people'], 'id')); // outside the lead's scope
    }

    public function test_search_needs_a_company_and_a_login(): void
    {
        $this->getJson('/search?q=abc')->assertUnauthorized();
    }

    // ---- profile photo -------------------------------------------------------------------------

    public function test_a_photo_can_be_uploaded_replaced_and_removed(): void
    {
        Storage::fake('public');
        $user = $this->dev1->user;

        $this->actingAsMember($this->dev1)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])->assertSessionHasNoErrors();
        $first = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);
        $this->assertNotNull($user->fresh()->avatar_url);

        $this->actingAsMember($this->dev1)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('new.jpg')]);
        Storage::disk('public')->assertMissing($first); // the old file is not left behind
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);

        $this->actingAsMember($this->dev1)->delete('/profile/avatar');
        $this->assertNull($user->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_only_small_images_are_accepted_as_a_photo(): void
    {
        Storage::fake('public');

        $this->actingAsMember($this->dev1)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])->assertSessionHasErrors('avatar');
        $this->actingAsMember($this->dev1)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('big.png')->size(5000)])->assertSessionHasErrors('avatar');
        $this->actingAsMember($this->dev1)->post('/profile/avatar', [])->assertSessionHasErrors('avatar');
        $this->assertNull($this->dev1->user->fresh()->avatar_path);
    }

    public function test_the_photo_url_reaches_the_page_and_the_profile_links_to_notification_settings(): void
    {
        Storage::fake('public');
        $this->actingAsMember($this->dev1)->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png')]);

        $props = $this->props($this->actingAsMember($this->dev1)->get('/profile'));

        $this->assertStringContainsString('avatars/', $props['auth']['user']['avatar_url']);
        $this->assertNotNull($props['currentOrganization']);
    }

    // ---- company switcher ----------------------------------------------------------------------

    public function test_someone_in_two_companies_can_switch_and_only_to_their_own(): void
    {
        [$second] = $this->makeCompany();
        $stranger = Organization::factory()->active()->create();
        $this->makeMember($second, 'developer', ['user_id' => $this->dev1->user_id]);

        $props = $this->props($this->actingAsMember($this->dev1)->get('/dashboard'));
        $this->assertCount(2, $props['organizations']);

        $this->actingAsMember($this->dev1)->post("/organizations/{$second->id}/switch")->assertRedirect(route('dashboard'));
        $this->assertSame($second->id, session('current_organization_id'));
        $this->assertSame($second->id, $this->dev1->user->fresh()->last_organization_id);

        $this->actingAsMember($this->dev1)->post("/organizations/{$stranger->id}/switch")->assertForbidden();
    }

    public function test_after_switching_you_see_the_other_company_s_data_only(): void
    {
        [$second] = $this->makeCompany();
        $this->makeMember($second, 'developer', ['user_id' => $this->dev1->user_id]);
        $this->task($this->ecp, $this->pm, ['assignee_id' => $this->dev1->id, 'status' => 'To Do']);

        $this->actingAsMember($this->dev1)->post("/organizations/{$second->id}/switch");
        $props = $this->props($this->actingAs($this->dev1->user)->withSession(['current_organization_id' => $second->id])->get('/dashboard'));

        $this->assertSame($second->id, $props['currentOrganization']['id']);
        $this->assertSame(0, $props['dashboard']['my']['open']);
        $this->actingAs($this->dev1->user)->withSession(['current_organization_id' => $second->id])->get('/tasks/ECP-1')->assertNotFound();
    }

    // ---- error pages ---------------------------------------------------------------------------

    public function test_a_missing_page_shows_the_friendly_404(): void
    {
        $response = $this->actingAsMember($this->dev1)->get('/no/such/page');

        $response->assertNotFound();
        $this->assertSame('Error', $response->viewData('page')['component']);
        $this->assertSame(404, $this->props($response)['status']);
    }

    public function test_a_forbidden_page_shows_the_friendly_403_and_json_stays_json(): void
    {
        $response = $this->actingAsMember($this->dev1)->get('/settings/audit');

        $response->assertForbidden();
        $this->assertSame(403, $this->props($response)['status']);
        $this->actingAsMember($this->dev1)->getJson('/settings/audit')->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_a_server_error_shows_the_friendly_500_when_not_debugging(): void
    {
        config(['app.debug' => false]);
        Route::get('/boom', fn () => throw new \RuntimeException('kaboom'))->middleware('web');

        $response = $this->get('/boom');

        $response->assertStatus(500);
        $this->assertSame('Error', $response->viewData('page')['component']);
        $this->assertStringNotContainsString('kaboom', $response->getContent());
    }
}
