<?php

namespace Tests\Feature\People;

use App\Models\Department;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Notifications\WelcomeBack;
use App\Services\PermissionService;
use App\Services\RehireService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class RehireTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    private Membership $dm;

    /** A developer who left last year. */
    private Membership $former;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();
        $this->dm = $this->makeMember($this->org, 'dm');

        $this->former = $this->makeMember($this->org, 'developer', ['employee_code' => 'E-7', 'designation' => 'Backend dev']);
        $this->actingAsMember($this->admin)->post("/people/{$this->former->id}/offboard", [
            'exit_type' => 'resigned', 'left_at' => today()->toDateString(), 'exit_reason' => 'Left',
        ])->assertSessionHasNoErrors();
        $this->former = Membership::withoutGlobalScopes()->onlyTrashed()->findOrFail($this->former->id);
    }

    private function role(string $slug): Role
    {
        return Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', $slug)->firstOrFail();
    }

    private function rehire(array $data = [], ?Membership $by = null)
    {
        return $this->actingAsMember($by ?? $this->admin)->post("/people/{$this->former->id}/rehire", $data + [
            'role_id' => $this->role('team-lead')->id,
            'reports_to_id' => $this->dm->id,
        ]);
    }

    private function exitAgain(Membership $m): void
    {
        $this->actingAsMember($this->admin)->post("/people/{$m->id}/offboard", ['exit_type' => 'contract_ended', 'left_at' => today()->toDateString()]);
    }

    // ---- finding & previewing ---------------------------------------------------------------

    public function test_a_former_member_is_found_by_email(): void
    {
        $service = app(RehireService::class);

        $this->assertSame($this->former->id, $service->findFormer($this->org, strtoupper($this->former->user->email))->id);
        $this->assertNull($service->findFormer($this->org, 'nobody@example.com'));

        // Only former members of THIS company, and never active ones.
        [$other] = $this->makeCompany();
        $this->assertNull($service->findFormer($other, $this->former->user->email));
        $this->assertNull($service->findFormer($this->org, $this->dm->user->email));
    }

    public function test_the_rehire_page_shows_the_previous_history(): void
    {
        $this->actingAsMember($this->admin)->get("/people/{$this->former->id}/rehire")->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Rehire')
                ->where('person.email', $this->former->user->email)->where('person.last_role', 'Developer')
                ->where('person.employee_code', 'E-7')->has('history', 1)->where('history.0.exit_type', 'resigned')
                ->where('canArchive', true));
    }

    public function test_the_rehire_page_redirects_for_people_who_have_not_left(): void
    {
        $this->actingAsMember($this->admin)->get("/people/{$this->dm->id}/rehire")->assertRedirect(route('people.show', $this->dm));
    }

    public function test_only_people_with_the_rehire_permission_can_rehire(): void
    {
        $this->actingAsMember($this->dm)->get("/people/{$this->former->id}/rehire")->assertForbidden();
        $this->rehire([], $this->dm)->assertForbidden();
        $this->assertNotNull(Membership::withoutGlobalScopes()->onlyTrashed()->find($this->former->id));

        $hod = $this->makeMember($this->org, 'hod');
        $this->rehire([], $hod)->assertSessionHasNoErrors();
    }

    // ---- the "same person" path ------------------------------------------------------------

    public function test_rehiring_restores_the_same_membership_with_a_new_position(): void
    {
        Notification::fake();
        $dept = Department::factory()->create(['organization_id' => $this->org->id]);

        $this->rehire(['department_id' => $dept->id, 'designation' => 'Team Lead', 'employee_code' => 'E-7', 'joined_at' => today()->toDateString()])
            ->assertSessionHasNoErrors()->assertRedirect(route('people.show', $this->former->id));

        $member = Membership::withoutGlobalScopes()->findOrFail($this->former->id);
        $this->assertSame($this->former->id, $member->id, 'the same membership row comes back, so the old history reconnects');
        $this->assertSame($this->former->user_id, $member->user_id);
        $this->assertNull($member->deleted_at);
        $this->assertSame(Membership::ACTIVE, $member->status);
        $this->assertSame('team-lead', $member->role->slug);
        $this->assertSame($dept->id, $member->department_id);
        $this->assertSame($this->dm->id, $member->reports_to_id);
        $this->assertSame('Team Lead', $member->designation);
        $this->assertSame('E-7', $member->employee_code);
        $this->assertSame(today()->toDateString(), $member->joined_at->toDateString());

        // Exit data is cleared.
        $this->assertNull($member->left_at);
        $this->assertNull($member->exit_type);
        $this->assertNull($member->exit_reason);
        $this->assertNull($member->exited_by);
        $this->assertNull($member->scheduled_exit_at);

        $this->assertSame([$member->id => 0, $this->dm->id => 1], $this->closureOf($member));
        Notification::assertSentTo($member->user, WelcomeBack::class);
    }

    public function test_the_employment_history_gets_a_second_period(): void
    {
        $this->rehire();

        $periods = Membership::withoutGlobalScopes()->find($this->former->id)->employmentPeriods;
        $this->assertCount(2, $periods);

        [$current, $old] = [$periods[0], $periods[1]];
        $this->assertNull($current->left_at);
        $this->assertSame('team-lead', Role::withoutGlobalScopes()->find($current->role_id)->slug);
        $this->assertSame($this->dm->id, $current->reports_to_id);

        $this->assertNotNull($old->left_at);
        $this->assertSame('resigned', $old->exit_type);
        $this->assertSame('developer', Role::withoutGlobalScopes()->find($old->role_id)->slug, 'the old period keeps the old role');
    }

    public function test_old_permission_overrides_are_cleared_and_only_the_new_role_applies(): void
    {
        $perms = app(PermissionService::class);
        $perms->override($this->former, 'audit.view', 'grant'); // an old exception
        $perms->override($this->former, 'task.create', 'revoke');
        $this->assertTrue($perms->can($this->former, 'audit.view'));

        $this->rehire(['role_id' => $this->role('developer')->id])->assertSessionHasNoErrors();

        $member = Membership::withoutGlobalScopes()->find($this->former->id);
        $this->assertSame(0, DB::table('membership_permission_overrides')->where('membership_id', $member->id)->count());
        $this->assertFalse($perms->can($member, 'audit.view'), 'the old grant is gone');
        $this->assertTrue($perms->can($member, 'task.create'), 'the old revoke is gone');
    }

    public function test_the_permissions_of_the_new_role_apply_immediately(): void
    {
        $this->rehire(['role_id' => $this->role('team-lead')->id]);

        $member = Membership::withoutGlobalScopes()->find($this->former->id);
        $this->assertTrue($member->hasPermission('task.assign'));
        $this->assertFalse($member->hasPermission('user.invite'));
    }

    public function test_the_rehired_person_signs_in_with_their_old_account(): void
    {
        $this->rehire();
        auth()->logout();

        $this->post('/login', ['email' => $this->former->user->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->former->user);
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('auth.role', 'Team Lead'));
    }

    public function test_the_old_employee_code_is_kept_unless_changed(): void
    {
        $this->rehire(['employee_code' => null]);

        $this->assertSame('E-7', Membership::withoutGlobalScopes()->find($this->former->id)->employee_code);
    }

    public function test_the_new_role_and_manager_follow_the_normal_rules(): void
    {
        $dev = $this->makeMember($this->org, 'developer');
        $dgm = $this->makeMember($this->org, 'dgm');
        [$other] = $this->makeCompany();

        $this->rehire(['role_id' => $this->role('team-lead')->id, 'reports_to_id' => $dev->id])->assertSessionHasErrors('reports_to_id'); // does not outrank
        $this->rehire(['role_id' => $this->role('hod')->id], $dgm)->assertForbidden(); // dgm lacks user.rehire
        $this->rehire(['role_id' => Role::withoutGlobalScopes()->where('organization_id', $other->id)->first()->id])->assertSessionHasErrors('role_id');
        $this->rehire(['role_id' => 999999])->assertSessionHasErrors('role_id');
        $this->rehire(['department_id' => 999999])->assertSessionHasErrors('department_id');

        $this->assertNotNull(Membership::withoutGlobalScopes()->onlyTrashed()->find($this->former->id), 'nothing was restored');
    }

    public function test_external_roles_are_restored_without_a_manager(): void
    {
        $this->rehire(['role_id' => $this->role('external-stakeholder')->id, 'reports_to_id' => $this->dm->id])->assertSessionHasErrors('reports_to_id');

        $this->rehire(['role_id' => $this->role('external-stakeholder')->id, 'reports_to_id' => null])->assertSessionHasNoErrors();

        $member = Membership::withoutGlobalScopes()->find($this->former->id);
        $this->assertSame('external', $member->user_type);
        $this->assertNull($member->reports_to_id);
        $this->assertSame([], $this->closureOf($member));
    }

    public function test_you_cannot_rehire_into_a_more_senior_role_than_your_own(): void
    {
        $hod = $this->makeMember($this->org, 'hod');
        $custom = Role::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Wide', 'slug' => 'wide', 'level' => 40, 'data_scope' => 'organization']);
        $custom->permissions()->sync(Permission::whereIn('name', ['user.view', 'user.rehire', 'user.view_former'])->pluck('id'));
        $actor = $this->makeMember($this->org, 'dm');
        $actor->forceFill(['role_id' => $custom->id])->save();

        $this->rehire(['role_id' => $this->role('hod')->id], $actor->fresh())->assertSessionHasErrors('role_id');
        $this->rehire(['role_id' => $this->role('team-lead')->id], $actor->fresh())->assertSessionHasNoErrors();
    }

    public function test_rehiring_is_blocked_when_the_user_limit_is_reached(): void
    {
        $this->org->forceFill(['user_limit' => 2])->save(); // admin + dm

        $this->rehire()->assertSessionHasErrors('member');
        $this->assertNotNull(Membership::withoutGlobalScopes()->onlyTrashed()->find($this->former->id));

        $this->org->forceFill(['user_limit' => 3])->save();
        $this->rehire()->assertSessionHasNoErrors();
    }

    public function test_an_active_member_cannot_be_rehired(): void
    {
        $this->actingAsMember($this->admin)->post("/people/{$this->dm->id}/rehire", ['role_id' => $this->role('team-lead')->id])
            ->assertSessionHasErrors('member');
    }

    public function test_a_person_can_leave_and_return_more_than_once(): void
    {
        $this->rehire();
        $this->exitAgain($this->former);

        $member = Membership::withoutGlobalScopes()->onlyTrashed()->findOrFail($this->former->id);
        $periods = $member->employmentPeriods;
        $this->assertCount(2, $periods);
        $this->assertTrue($periods->every(fn ($p) => $p->left_at !== null));
        $this->assertSame('contract_ended', $periods[0]->exit_type);
        $this->assertSame('resigned', $periods[1]->exit_type);

        $this->former = $member;
        $this->rehire()->assertSessionHasNoErrors();
        $this->assertCount(3, Membership::withoutGlobalScopes()->find($this->former->id)->employmentPeriods);
        $this->assertSame(1, Membership::withoutGlobalScopes()->withTrashed()->where('user_id', $this->former->user_id)->count(), 'still one membership row for this person');
    }

    public function test_the_profile_shows_the_whole_history(): void
    {
        $this->rehire();
        $member = Membership::withoutGlobalScopes()->find($this->former->id);

        $this->actingAsMember($member)->get('/profile')->assertInertia(fn ($page) => $page->has('employment.periods', 2));
        $this->actingAsMember($this->admin)->get("/people/{$member->id}")->assertInertia(fn ($page) => $page->has('history', 2));
    }

    // ---- the "different person, same email" path -------------------------------------------

    public function test_a_different_person_gets_a_fresh_account_and_the_old_history_stays(): void
    {
        Notification::fake();
        $email = $this->former->user->email;
        $oldUserId = $this->former->user_id;

        $this->actingAsMember($this->admin)->post("/people/{$this->former->id}/rehire/different-person")
            ->assertRedirect(route('people.invite', ['email' => $email]));

        // The old account is archived and its email freed.
        $old = User::find($oldUserId);
        $this->assertSame("archived+{$oldUserId}@archived.invalid", $old->email);
        $this->assertSame('anonymized', $old->status);
        $this->assertNull(User::where('email', $email)->first());

        // The old person's history is untouched.
        $member = Membership::withoutGlobalScopes()->onlyTrashed()->find($this->former->id);
        $this->assertNotNull($member);
        $this->assertSame($oldUserId, $member->user_id);
        $this->assertSame(1, $member->employmentPeriods()->count());

        // The new person is invited with the same email and gets their own account.
        $this->actingAsMember($this->admin)->post('/people/invitations', ['email' => $email, 'role_id' => $this->role('developer')->id])
            ->assertSessionHasNoErrors();
        $token = null;
        Notification::assertSentOnDemand(UserInvited::class, function ($n) use (&$token) {
            $token = $n->invitation->plainToken;

            return true;
        });
        auth()->logout();
        $this->post("/invitations/{$token}/accept", ['name' => 'New Person', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word']);

        $newUser = User::where('email', $email)->firstOrFail();
        $this->assertNotSame($oldUserId, $newUser->id);
        $newMembership = Membership::withoutGlobalScopes()->where('user_id', $newUser->id)->sole();
        $this->assertNotSame($member->id, $newMembership->id);
        $this->assertSame(1, $newMembership->employmentPeriods()->count(), 'a clean history');
    }

    public function test_the_archived_account_can_no_longer_sign_in(): void
    {
        $email = $this->former->user->email;
        $this->actingAsMember($this->admin)->post("/people/{$this->former->id}/rehire/different-person");
        auth()->logout();

        $this->post('/login', ['email' => $email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => "archived+{$this->former->user_id}@archived.invalid", 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_an_account_in_use_elsewhere_is_not_archived(): void
    {
        [$other] = $this->makeCompany();
        $role = Role::withoutGlobalScopes()->where('organization_id', $other->id)->where('slug', 'developer')->first();
        Membership::withoutGlobalScopes()->create(['organization_id' => $other->id, 'user_id' => $this->former->user_id, 'role_id' => $role->id, 'status' => 'active']);

        $this->actingAsMember($this->admin)->get("/people/{$this->former->id}/rehire")->assertInertia(fn ($page) => $page->where('canArchive', false));
        $this->actingAsMember($this->admin)->post("/people/{$this->former->id}/rehire/different-person")->assertSessionHasErrors('member');

        $this->assertNotSame('anonymized', User::find($this->former->user_id)->status);
        $this->assertSame($this->former->user->email, User::find($this->former->user_id)->email);
    }

    public function test_archiving_needs_both_the_rehire_and_invite_permissions(): void
    {
        $hod = $this->makeMember($this->org, 'hod');
        $custom = Role::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'RehireOnly', 'slug' => 'rehire-only', 'level' => 20, 'data_scope' => 'organization']);
        $custom->permissions()->sync(Permission::whereIn('name', ['user.view', 'user.rehire'])->pluck('id'));
        $actor = $this->makeMember($this->org, 'dgm');
        $actor->forceFill(['role_id' => $custom->id])->save();

        $this->actingAsMember($actor->fresh())->post("/people/{$this->former->id}/rehire/different-person")->assertForbidden();
        $this->assertNotSame('anonymized', User::find($this->former->user_id)->status);
        $this->assertNotNull($hod);
    }

    public function test_an_invitation_to_a_former_members_email_is_blocked_until_a_choice_is_made(): void
    {
        $this->actingAsMember($this->admin)->post('/people/invitations', ['email' => $this->former->user->email, 'role_id' => $this->role('developer')->id])
            ->assertRedirect(route('people.rehire.create', $this->former->id));

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }
}
