<?php

namespace Tests\Feature\People;

use App\Events\MembershipExited;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\OffboardingService;
use App\Services\SeatService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class OffboardingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private Organization $org;

    private Membership $admin;

    private Membership $dm;

    private Membership $tl;

    private Membership $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        [$this->org, $this->admin] = $this->makeCompany();

        // dm > tl > dev
        $this->dm = $this->makeMember($this->org, 'dm');
        $this->tl = $this->makeMember($this->org, 'team-lead', ['reports_to_id' => $this->dm->id]);
        $this->dev = $this->makeMember($this->org, 'developer', ['reports_to_id' => $this->tl->id]);
    }

    private function role(string $slug): Role
    {
        return Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', $slug)->firstOrFail();
    }

    private function former(Membership $m): ?Membership
    {
        return Membership::withoutGlobalScopes()->onlyTrashed()->find($m->id);
    }

    private function offboard(Membership $m, array $data = [], ?Membership $by = null)
    {
        return $this->actingAsMember($by ?? $this->admin)->post("/people/{$m->id}/offboard", $data + [
            'exit_type' => 'resigned', 'left_at' => today()->toDateString(), 'exit_reason' => 'Moved abroad',
        ]);
    }

    // ---- exit now --------------------------------------------------------------------------

    public function test_offboarding_today_ends_the_membership_but_keeps_the_account(): void
    {
        Event::fake([MembershipExited::class]);

        $this->offboard($this->dev)->assertSessionHasNoErrors()->assertRedirect(route('people.index', ['tab' => 'former']));

        $gone = $this->former($this->dev);
        $this->assertNotNull($gone, 'membership is soft-deleted, not removed');
        $this->assertSame(Membership::EXITED, $gone->status);
        $this->assertSame(today()->toDateString(), $gone->left_at->toDateString());
        $this->assertSame('resigned', $gone->exit_type);
        $this->assertSame('Moved abroad', $gone->exit_reason);
        $this->assertSame($this->admin->id, $gone->exited_by);
        $this->assertNull($gone->scheduled_exit_at);

        $this->assertNotNull(User::find($this->dev->user_id), 'the user account is never deleted');
        Event::assertDispatched(MembershipExited::class, fn ($e) => $e->membership->id === $this->dev->id);
    }

    public function test_the_employment_period_is_closed_with_the_exit_details(): void
    {
        $this->offboard($this->dev, ['exit_type' => 'terminated', 'exit_reason' => 'Policy breach']);

        $period = $this->former($this->dev)->employmentPeriods()->sole();
        $this->assertSame(today()->toDateString(), $period->left_at->toDateString());
        $this->assertSame('terminated', $period->exit_type);
        $this->assertSame('Policy breach', $period->exit_reason);
    }

    public function test_they_disappear_from_the_hierarchy_and_the_active_list(): void
    {
        $this->offboard($this->dev);

        $this->assertSame([], $this->closureOf($this->dev));
        $this->assertSame([], $this->tl->descendants()->pluck('memberships.id')->all());
        $this->assertSame(0, DB::table('membership_hierarchy')->where('ancestor_id', $this->dev->id)->count());

        $this->actingAsMember($this->admin)->get('/people')->assertInertia(fn ($p) => $p->where('counts.active', 3)->where('counts.former', 1));
    }

    public function test_sessions_are_revoked(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $this->dev->user_id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 's2', 'user_id' => $this->tl->user_id, 'payload' => '', 'last_activity' => time()]);

        $this->offboard($this->dev);

        $this->assertDatabaseMissing('sessions', ['id' => 's1']);
        $this->assertDatabaseHas('sessions', ['id' => 's2']);
    }

    public function test_department_head_and_team_links_are_cleared(): void
    {
        $dept = Department::factory()->create(['organization_id' => $this->org->id, 'head_membership_id' => $this->dev->id]);
        $teamId = DB::table('teams')->insertGetId(['organization_id' => $this->org->id, 'name' => 'Pay', 'lead_membership_id' => $this->dev->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('team_members')->insert(['team_id' => $teamId, 'membership_id' => $this->dev->id]);

        $this->offboard($this->dev);

        $this->assertNull($dept->fresh()->head_membership_id);
        $this->assertNull(DB::table('teams')->where('id', $teamId)->value('lead_membership_id'));
        $this->assertSame(0, DB::table('team_members')->where('team_id', $teamId)->count());
    }

    public function test_the_seat_is_freed(): void
    {
        $this->org->forceFill(['user_limit' => 5])->save(); // admin, dm, tl, dev + 1 free
        $svc = app(SeatService::class);
        $this->assertSame(1, $svc->remaining($this->org));

        $this->offboard($this->dev);

        $this->assertSame(2, $svc->remaining($this->org->fresh()));
    }

    public function test_the_page_lists_the_handover(): void
    {
        $this->actingAsMember($this->admin)->get("/people/{$this->tl->id}/offboard")->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Offboard')
                ->has('directReports', 1)->where('directReports.0.name', $this->dev->user->name)
                ->where('person.default_manager_id', $this->dm->id)
                ->where('managers', fn ($m) => collect($m)->pluck('id')->contains($this->dm->id) && ! collect($m)->pluck('id')->contains($this->dev->id)));
    }

    // ---- handover & safety rules -----------------------------------------------------------

    public function test_direct_reports_must_be_handed_over_first(): void
    {
        $this->offboard($this->tl)->assertSessionHasErrors('new_manager_id');

        $this->assertNull($this->former($this->tl));
        $this->assertSame(Membership::ACTIVE, $this->tl->fresh()->status);
    }

    public function test_direct_reports_move_to_the_chosen_manager(): void
    {
        $dm2 = $this->makeMember($this->org, 'dm');

        $this->offboard($this->tl, ['new_manager_id' => $dm2->id])->assertSessionHasNoErrors();

        $this->assertSame($dm2->id, $this->dev->fresh()->reports_to_id);
        $this->assertSame([$this->dev->id => 0, $dm2->id => 1], $this->closureOf($this->dev));
        $this->assertNotNull($this->former($this->tl));
        $this->assertSame([$this->dev->id], $dm2->descendants()->pluck('memberships.id')->all());
        $this->assertSame([], $this->dm->descendants()->pluck('memberships.id')->all());
    }

    public function test_the_new_manager_must_be_valid(): void
    {
        $hod = $this->makeMember($this->org, 'hod');
        $junior = $this->makeMember($this->org, 'developer');
        $client = $this->makeMember($this->org, 'external-stakeholder');

        $this->offboard($this->tl, ['new_manager_id' => $this->tl->id])->assertSessionHasErrors('new_manager_id');
        $this->offboard($this->tl, ['new_manager_id' => $junior->id])->assertSessionHasErrors('new_manager_id'); // does not outrank the developer
        $this->offboard($this->tl, ['new_manager_id' => $this->dev->id])->assertSessionHasErrors('new_manager_id');
        $this->offboard($this->tl, ['new_manager_id' => $client->id])->assertSessionHasErrors('new_manager_id');
        $this->offboard($this->tl, ['new_manager_id' => 999999])->assertSessionHasErrors('new_manager_id');

        $this->offboard($this->tl, ['new_manager_id' => $hod->id])->assertSessionHasNoErrors();
        $this->assertSame($hod->id, $this->dev->fresh()->reports_to_id);
    }

    public function test_you_cannot_offboard_yourself(): void
    {
        $hod = $this->makeMember($this->org, 'hod');

        $this->offboard($hod, [], $hod)->assertSessionHasErrors('member');
        $this->assertNull($this->former($hod));
    }

    public function test_the_last_company_admin_cannot_be_offboarded(): void
    {
        // A level-0 role that is not "locked", so the actor can act on the admin without being one.
        $super = Role::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Super', 'slug' => 'super', 'level' => 0, 'data_scope' => 'organization']);
        $actor = $this->makeMember($this->org, 'hod');
        $actor->forceFill(['role_id' => $super->id])->save();

        try {
            app(OffboardingService::class)->offboard($this->admin, $actor->fresh(), ['exit_type' => 'resigned', 'left_at' => today()->toDateString()]);
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('last Company Admin', $e->errors()['member'][0]);
        }

        $this->assertSame(Membership::ACTIVE, $this->admin->fresh()->status);
    }

    public function test_a_company_admin_can_be_offboarded_when_another_remains(): void
    {
        $second = $this->makeMember($this->org, 'company-admin');

        $this->offboard($second)->assertSessionHasNoErrors();

        $this->assertNotNull($this->former($second));
    }

    public function test_you_cannot_offboard_someone_more_senior(): void
    {
        $hod = $this->makeMember($this->org, 'hod');
        $custom = Role::withoutGlobalScopes()->create(['organization_id' => $this->org->id, 'name' => 'Wide', 'slug' => 'wide', 'level' => 40, 'data_scope' => 'organization']);
        $custom->permissions()->sync(Permission::whereIn('name', ['user.view', 'user.offboard'])->pluck('id'));
        $actor = $this->makeMember($this->org, 'dm');
        $actor->forceFill(['role_id' => $custom->id])->save();

        $this->offboard($hod, [], $actor->fresh())->assertSessionHasErrors('member');
    }

    public function test_only_people_with_the_offboard_permission_can_do_it(): void
    {
        $this->offboard($this->dev, [], $this->dm)->assertForbidden();
        $this->actingAsMember($this->dm)->get("/people/{$this->dev->id}/offboard")->assertForbidden();

        $hod = $this->makeMember($this->org, 'hod');
        $this->offboard($this->dev, [], $hod)->assertSessionHasNoErrors();
    }

    public function test_exit_input_is_validated(): void
    {
        $this->offboard($this->dev, ['exit_type' => 'vanished'])->assertSessionHasErrors('exit_type');
        $this->offboard($this->dev, ['left_at' => 'tomorrow-ish'])->assertSessionHasErrors('left_at');
        $this->offboard($this->dev, ['left_at' => $this->dev->joined_at->subDay()->toDateString()])->assertSessionHasErrors('left_at');
    }

    public function test_someone_in_another_company_cannot_be_offboarded(): void
    {
        [$other, $otherAdmin] = $this->makeCompany();
        $foreign = $this->makeMember($other, 'developer');

        $this->offboard($foreign)->assertNotFound();
        $this->assertNull($this->former($foreign));
    }

    public function test_an_already_exited_member_cannot_be_offboarded_again(): void
    {
        $this->offboard($this->dev);

        $this->offboard($this->dev)->assertNotFound(); // soft-deleted rows do not resolve on this route
    }

    // ---- scheduled exits -------------------------------------------------------------------

    public function test_a_future_last_day_schedules_the_exit(): void
    {
        $date = today()->addDays(10)->toDateString();

        $this->offboard($this->dev, ['left_at' => $date])->assertRedirect(route('people.index', ['tab' => 'active']));

        $dev = $this->dev->fresh();
        $this->assertSame(Membership::ACTIVE, $dev->status);
        $this->assertNull($dev->deleted_at);
        $this->assertSame($date, $dev->scheduled_exit_at->toDateString());
        $this->assertSame('resigned', $dev->exit_type);
        $this->assertArrayHasKey($this->tl->id, $this->closureOf($this->dev), 'still in the hierarchy until the last day');
    }

    public function test_the_daily_command_exits_people_whose_day_has_come(): void
    {
        $due = $this->makeMember($this->org, 'developer');
        $later = $this->makeMember($this->org, 'developer');
        $this->offboard($due, ['left_at' => today()->addDays(2)->toDateString()]);
        $this->offboard($later, ['left_at' => today()->addDays(30)->toDateString()]);

        // Not yet.
        Artisan::call('members:process-exits');
        $this->assertNull($this->former($due));

        $this->travelTo(today()->addDays(3));
        Artisan::call('members:process-exits');

        $gone = $this->former($due);
        $this->assertNotNull($gone);
        $this->assertSame(today()->subDay()->toDateString(), $gone->left_at->toDateString(), 'left_at is the scheduled day, not the day the job ran');
        $this->assertSame(Membership::EXITED, $gone->status);
        $this->assertNull($gone->scheduled_exit_at);
        $this->assertSame([], $this->closureOf($due));
        $this->assertNull($this->former($later));
        $this->assertSame(Membership::ACTIVE, $later->fresh()->status);
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command, 'members:process-exits'));

        $this->assertCount(1, $events);
        $this->assertSame('10 0 * * *', $events->first()->expression);
    }

    public function test_a_scheduled_exit_can_be_cancelled(): void
    {
        $this->offboard($this->dev, ['left_at' => today()->addDays(10)->toDateString()]);

        $this->actingAsMember($this->admin)->delete("/people/{$this->dev->id}/offboard")->assertSessionHasNoErrors();

        $dev = $this->dev->fresh();
        $this->assertNull($dev->scheduled_exit_at);
        $this->assertNull($dev->exit_type);

        Artisan::call('members:process-exits');
        $this->travelTo(today()->addDays(20));
        Artisan::call('members:process-exits');
        $this->assertSame(Membership::ACTIVE, $dev->fresh()->status);
    }

    public function test_reports_still_below_a_scheduled_leaver_move_up_when_the_job_runs(): void
    {
        // The handover happened at scheduling time, but someone may have been moved under them since.
        $this->offboard($this->tl, ['new_manager_id' => $this->dm->id, 'left_at' => today()->addDay()->toDateString()]);
        $newcomer = $this->makeMember($this->org, 'developer', ['reports_to_id' => $this->tl->id]);

        $this->travelTo(today()->addDays(2));
        Artisan::call('members:process-exits');

        $this->assertSame($this->dm->id, $newcomer->fresh()->reports_to_id);
        $this->assertSame([$newcomer->id => 0, $this->dm->id => 1], $this->closureOf($newcomer));
    }

    // ---- what the leaver experiences -------------------------------------------------------

    public function test_a_former_employee_is_told_when_their_access_ended(): void
    {
        $this->offboard($this->dev, ['left_at' => today()->subDays(3)->toDateString()]);

        auth()->logout();
        $response = $this->post('/login', ['email' => $this->dev->user->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $message = session('errors')->first('email');
        $this->assertStringContainsString("Your access to {$this->org->name} ended on", $message);
        $this->assertStringContainsString(today()->subDays(3)->format('j M Y'), $message);
    }

    public function test_someone_already_signed_in_is_signed_out_with_the_message(): void
    {
        $this->offboard($this->dev);

        $this->actingAs(User::find($this->dev->user_id))->get('/dashboard')
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Your access to'));
        $this->assertGuest();
    }

    public function test_a_former_employee_can_still_use_another_company(): void
    {
        [$other] = $this->makeCompany();
        $role = Role::withoutGlobalScopes()->where('organization_id', $other->id)->where('slug', 'developer')->first();
        Membership::withoutGlobalScopes()->create(['organization_id' => $other->id, 'user_id' => $this->dev->user_id, 'role_id' => $role->id, 'status' => 'active']);

        $this->offboard($this->dev);

        auth()->logout();
        $this->post('/login', ['email' => $this->dev->user->email, 'password' => 'password'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();
        $this->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('currentOrganization.id', $other->id));
    }

    public function test_the_former_employee_is_hidden_from_the_active_pickers(): void
    {
        $this->offboard($this->dev);

        $this->actingAsMember($this->admin)->get('/people/invite')
            ->assertInertia(fn ($page) => $page->where('managers', fn ($m) => ! collect($m)->pluck('id')->contains($this->dev->id)));
    }

    public function test_their_profile_stays_visible_under_former_employees(): void
    {
        $this->offboard($this->dev);

        $this->actingAsMember($this->admin)->get('/people?tab=former')
            ->assertInertia(fn ($page) => $page->has('rows.data', 1)->where('rows.data.0.exit_type', 'resigned'));
        $this->actingAsMember($this->admin)->get("/people/{$this->dev->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('person.is_former', true)->where('person.name', $this->dev->user->name));
    }
}
