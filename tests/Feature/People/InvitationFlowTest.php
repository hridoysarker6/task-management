<?php

namespace Tests\Feature\People;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class InvitationFlowTest extends TestCase
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

    private function invite(array $overrides = [], ?Membership $by = null)
    {
        return $this->actingAsMember($by ?? $this->admin)->post('/people/invitations', array_merge([
            'email' => 'new.person@example.com',
            'role_id' => $this->role('developer')->id,
        ], $overrides));
    }

    // ---- sending ---------------------------------------------------------------------------

    public function test_an_invitation_is_created_and_emailed(): void
    {
        Notification::fake();
        $tl = $this->makeMember($this->org, 'team-lead');

        $this->invite([
            'email' => 'New.Person@Example.com', 'name' => 'New Person', 'reports_to_id' => $tl->id,
            'designation' => 'Backend dev', 'employee_code' => 'E-77',
        ])->assertRedirect(route('people.index', ['tab' => 'invited']));

        $invitation = Invitation::withoutGlobalScopes()->sole();
        $this->assertSame('new.person@example.com', $invitation->email); // lower-cased
        $this->assertSame($this->org->id, $invitation->organization_id);
        $this->assertSame($tl->id, $invitation->reports_to_id);
        $this->assertSame('E-77', $invitation->employee_code);
        $this->assertSame($this->admin->id, $invitation->invited_by);
        $this->assertEqualsWithDelta(7 * 86400, now()->diffInSeconds($invitation->expires_at, false), 5);

        Notification::assertSentOnDemand(UserInvited::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'new.person@example.com');
    }

    public function test_only_the_hash_of_the_token_is_stored(): void
    {
        Notification::fake();
        $this->invite();

        $invitation = Invitation::withoutGlobalScopes()->sole();
        $sent = null;
        Notification::assertSentOnDemand(UserInvited::class, function ($n) use (&$sent) {
            $sent = $n->invitation->plainToken;

            return true;
        });

        $this->assertNotNull($sent);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertNotSame($sent, $invitation->token_hash);
        $this->assertSame(hash('sha256', $sent), $invitation->token_hash);
    }

    public function test_only_people_with_the_invite_permission_can_invite(): void
    {
        $dev = $this->makeMember($this->org, 'developer');

        $this->invite([], $dev)->assertForbidden();
        $this->actingAsMember($dev)->get('/people/invite')->assertForbidden();
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_the_invite_form_renders(): void
    {
        $this->actingAsMember($this->admin)->get('/people/invite?email=x@y.com')->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Invite')->where('email', 'x@y.com')->has('roles', 10));
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAsMember($this->admin)->post('/people/invitations', [])->assertSessionHasErrors(['email', 'role_id']);
        $this->invite(['email' => 'not-an-email'])->assertSessionHasErrors('email');
    }

    public function test_the_role_must_belong_to_the_company(): void
    {
        [$other] = $this->makeCompany();

        $this->invite(['role_id' => $this->role('developer', $other)->id])->assertSessionHasErrors('role_id');
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_you_cannot_invite_into_a_role_more_senior_than_your_own(): void
    {
        $dm = $this->makeMember($this->org, 'dm');

        $this->invite(['role_id' => $this->role('hod')->id], $dm)->assertSessionHasErrors('role_id');
        $this->invite(['role_id' => $this->role('team-lead')->id], $dm)->assertSessionHasNoErrors();
    }

    public function test_a_current_member_cannot_be_invited_again(): void
    {
        $member = $this->makeMember($this->org, 'developer');

        $this->invite(['email' => $member->user->email])->assertSessionHasErrors('email');
    }

    public function test_a_second_pending_invitation_for_the_same_email_is_refused(): void
    {
        Notification::fake();
        $this->invite()->assertSessionHasNoErrors();

        $this->invite()->assertSessionHasErrors('email');
        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_a_revoked_or_expired_invitation_allows_inviting_again(): void
    {
        Notification::fake();
        $this->invite();
        Invitation::withoutGlobalScopes()->sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->invite()->assertSessionHasNoErrors();
        $this->assertSame(2, Invitation::withoutGlobalScopes()->count());
    }

    public function test_the_manager_must_outrank_the_invited_role(): void
    {
        $dev = $this->makeMember($this->org, 'developer');

        $this->invite(['role_id' => $this->role('team-lead')->id, 'reports_to_id' => $dev->id])->assertSessionHasErrors('reports_to_id');
    }

    public function test_external_roles_cannot_have_a_manager(): void
    {
        Notification::fake();
        $dm = $this->makeMember($this->org, 'dm');

        $this->invite(['role_id' => $this->role('external-stakeholder')->id, 'reports_to_id' => $dm->id])->assertSessionHasErrors('reports_to_id');
    }

    public function test_the_employee_code_must_be_unique(): void
    {
        $this->makeMember($this->org, 'developer', ['employee_code' => 'E-1']);

        $this->invite(['employee_code' => 'E-1'])->assertSessionHasErrors('employee_code');
    }

    public function test_the_user_limit_blocks_invitations_and_counts_pending_ones(): void
    {
        Notification::fake();
        $this->org->forceFill(['user_limit' => 2])->save(); // the admin + one more

        $this->invite(['email' => 'one@example.com'])->assertSessionHasNoErrors();
        $this->invite(['email' => 'two@example.com'])->assertSessionHasErrors('email');

        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_inviting_a_former_employee_points_to_rehire(): void
    {
        $former = $this->makeMember($this->org, 'developer');
        $former->forceFill(['status' => Membership::EXITED, 'left_at' => now()->toDateString()])->save();
        $former->delete();

        $this->invite(['email' => $former->user->email])
            ->assertRedirect(route('people.rehire.create', $former->id));

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    // ---- resend / revoke / expiry ----------------------------------------------------------

    public function test_resend_renews_the_link_and_invalidates_the_old_one(): void
    {
        Notification::fake();
        $this->invite();
        $invitation = Invitation::withoutGlobalScopes()->sole();
        $oldHash = $invitation->token_hash;
        $invitation->forceFill(['expires_at' => now()->addHour()])->save();

        $this->actingAsMember($this->admin)->post(route('people.invitations.resend', $invitation))->assertSessionHasNoErrors();

        $invitation->refresh();
        $this->assertNotSame($oldHash, $invitation->token_hash);
        $this->assertEqualsWithDelta(7 * 86400, now()->diffInSeconds($invitation->expires_at, false), 5);
        Notification::assertSentOnDemandTimes(UserInvited::class, 2);
    }

    public function test_an_expired_invitation_can_be_resent_and_works_again(): void
    {
        Notification::fake();
        $this->invite();
        $invitation = Invitation::withoutGlobalScopes()->sole();
        $invitation->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAsMember($this->admin)->post(route('people.invitations.resend', $invitation));

        $this->assertTrue($invitation->fresh()->isUsable());
    }

    public function test_revoked_and_accepted_invitations_cannot_be_resent(): void
    {
        Notification::fake();
        $this->invite();
        $invitation = Invitation::withoutGlobalScopes()->sole();

        $this->actingAsMember($this->admin)->delete(route('people.invitations.destroy', $invitation))->assertSessionHasNoErrors();
        $this->assertNotNull($invitation->fresh()->revoked_at);

        $this->actingAsMember($this->admin)->post(route('people.invitations.resend', $invitation))->assertSessionHasErrors('invitation');
    }

    public function test_invitations_of_other_companies_are_out_of_reach(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $foreign = Invitation::factory()->create(['organization_id' => $other->id, 'role_id' => $this->role('developer', $other)->id]);

        $this->actingAsMember($this->admin)->post(route('people.invitations.resend', $foreign))->assertNotFound();
        $this->actingAsMember($this->admin)->delete(route('people.invitations.destroy', $foreign))->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
    }

    public function test_the_invited_tab_lists_open_invitations_with_their_state(): void
    {
        Notification::fake();
        $this->invite(['email' => 'a@example.com']);
        $this->invite(['email' => 'b@example.com']);
        Invitation::withoutGlobalScopes()->where('email', 'b@example.com')->update(['expires_at' => now()->subDay()]);

        $this->actingAsMember($this->admin)->get('/people?tab=invited')
            ->assertInertia(fn ($page) => $page->has('rows.data', 2)->where('counts.invited', 2));
    }

    // ---- accepting -------------------------------------------------------------------------

    /** Create an invitation and return its plain token. */
    private function token(array $overrides = []): string
    {
        $token = null;
        Notification::fake();
        $this->invite($overrides)->assertSessionHasNoErrors();
        Notification::assertSentOnDemand(UserInvited::class, function ($n) use (&$token) {
            $token = $n->invitation->plainToken;

            return true;
        });

        return $token;
    }

    public function test_a_new_person_accepts_with_a_name_and_password(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead');
        $token = $this->token(['reports_to_id' => $tl->id, 'designation' => 'Dev', 'employee_code' => 'E-9']);
        auth()->logout();

        $this->get("/invitations/{$token}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Invitations/Accept')->where('state', 'usable')->where('accountExists', false)
                ->where('invitation.company', $this->org->name));

        $this->post("/invitations/{$token}/accept", ['name' => 'Rahim', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'])
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'new.person@example.com')->firstOrFail();
        $this->assertSame('Rahim', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);

        $membership = Membership::withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame($this->org->id, $membership->organization_id);
        $this->assertSame('developer', $membership->role->slug);
        $this->assertSame($tl->id, $membership->reports_to_id);
        $this->assertSame('E-9', $membership->employee_code);
        $this->assertSame(Membership::ACTIVE, $membership->status);
        $this->assertSame(now()->toDateString(), $membership->joined_at->toDateString());

        // Employment history and hierarchy are in place.
        $this->assertSame(1, $membership->employmentPeriods()->count());
        $this->assertNull($membership->employmentPeriods()->first()->left_at);
        $this->assertSame([$membership->id => 0, $tl->id => 1], $this->closureOf($membership));
        $this->assertNotNull(Invitation::withoutGlobalScopes()->sole()->accepted_at);
    }

    public function test_the_new_member_lands_in_the_company_with_the_role_permissions(): void
    {
        $token = $this->token();
        auth()->logout();

        $this->post("/invitations/{$token}/accept", ['name' => 'Rahim', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word']);

        $this->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page->where('currentOrganization.id', $this->org->id)->where('auth.role', 'Developer'));
    }

    public function test_accepting_needs_a_valid_password(): void
    {
        $token = $this->token();
        auth()->logout();

        $this->post("/invitations/{$token}/accept", ['name' => 'Rahim', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
        $this->post("/invitations/{$token}/accept", ['name' => '', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'])
            ->assertSessionHasErrors('name');

        $this->assertNull(User::where('email', 'new.person@example.com')->first());
    }

    public function test_an_invitation_can_only_be_used_once(): void
    {
        $token = $this->token();
        auth()->logout();
        $payload = ['name' => 'Rahim', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'];

        $this->post("/invitations/{$token}/accept", $payload)->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->flushSession();

        $this->get("/invitations/{$token}")->assertInertia(fn ($page) => $page->where('state', 'accepted'));
    }

    public function test_an_expired_link_cannot_be_accepted(): void
    {
        $token = $this->token();
        auth()->logout();
        Invitation::withoutGlobalScopes()->sole()->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->get("/invitations/{$token}")->assertInertia(fn ($page) => $page->where('state', 'expired'));
        $this->post("/invitations/{$token}/accept", ['name' => 'Rahim', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'])
            ->assertSessionHasErrors('invitation');
        $this->assertNull(User::where('email', 'new.person@example.com')->first());
    }

    public function test_a_revoked_link_cannot_be_accepted(): void
    {
        $token = $this->token();
        Invitation::withoutGlobalScopes()->sole()->forceFill(['revoked_at' => now()])->save();
        auth()->logout();

        $this->get("/invitations/{$token}")->assertInertia(fn ($page) => $page->where('state', 'revoked'));
        $this->post("/invitations/{$token}/accept", ['name' => 'Rahim', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'])
            ->assertSessionHasErrors('invitation');
    }

    public function test_an_unknown_token_shows_the_invalid_page(): void
    {
        $this->get('/invitations/'.str_repeat('x', 64))->assertOk()
            ->assertInertia(fn ($page) => $page->where('state', 'invalid'));
        $this->post('/invitations/'.str_repeat('x', 64).'/accept', [])->assertNotFound();
    }

    public function test_the_old_link_stops_working_after_a_resend(): void
    {
        $token = $this->token();
        $invitation = Invitation::withoutGlobalScopes()->sole();
        $this->actingAsMember($this->admin)->post(route('people.invitations.resend', $invitation));
        auth()->logout();

        $this->get("/invitations/{$token}")->assertInertia(fn ($page) => $page->where('state', 'invalid'));
    }

    public function test_someone_with_an_account_must_sign_in_as_the_invited_email(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.com']);
        $token = $this->token(['email' => 'existing@example.com']);
        auth()->logout();

        // Signed out: asked to sign in; the link is remembered so they come back after logging in.
        $this->get("/invitations/{$token}")
            ->assertInertia(fn ($page) => $page->where('accountExists', true)->where('emailMatches', false))
            ->assertSessionHas('url.intended', route('invitations.show', $token));
        $this->post("/invitations/{$token}/accept")->assertForbidden();

        // Signed in as somebody else: still refused.
        $this->actingAs(User::factory()->create())->post("/invitations/{$token}/accept")->assertForbidden();

        // Signed in as the right person: joins, keeping their existing account.
        $this->actingAs($existing)->get("/invitations/{$token}")->assertInertia(fn ($page) => $page->where('emailMatches', true));
        $this->actingAs($existing)->post("/invitations/{$token}/accept")->assertRedirect(route('dashboard'));

        $membership = Membership::withoutGlobalScopes()->where('user_id', $existing->id)->sole();
        $this->assertSame($this->org->id, $membership->organization_id);
        $this->assertSame(1, User::where('email', 'existing@example.com')->count());
    }

    public function test_a_suspended_company_cannot_take_new_members(): void
    {
        $token = $this->token();
        auth()->logout();
        $this->org->forceFill(['status' => Organization::SUSPENDED])->save();

        $this->post("/invitations/{$token}/accept", ['name' => 'Rahim', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'])
            ->assertSessionHasErrors('invitation');
    }

    public function test_a_manager_who_left_in_the_meantime_is_skipped_not_fatal(): void
    {
        $tl = $this->makeMember($this->org, 'team-lead');
        $token = $this->token(['reports_to_id' => $tl->id]);
        auth()->logout();
        $tl->forceFill(['status' => Membership::EXITED])->save();
        $tl->delete();

        $this->post("/invitations/{$token}/accept", ['name' => 'Rahim', 'password' => 'Str0ng-Pass!word', 'password_confirmation' => 'Str0ng-Pass!word'])
            ->assertRedirect(route('dashboard'));

        $this->assertNull(Membership::withoutGlobalScopes()->where('user_id', User::where('email', 'new.person@example.com')->value('id'))->value('reports_to_id'));
    }
}
