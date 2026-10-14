<?php

namespace Tests\Feature\People;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Services\HierarchyService;
use App\Services\MemberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class HierarchyServiceTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private HierarchyService $hierarchy;

    private Organization $org;

    /** HOD(10) > DM(40) > TL(60) > Dev(70) */
    private Membership $hod;

    private Membership $dm;

    private Membership $tl;

    private Membership $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoleTemplate();
        $this->hierarchy = app(HierarchyService::class);
        [$this->org] = $this->makeCompany();

        $this->hod = $this->makeMember($this->org, 'hod');
        $this->dm = $this->makeMember($this->org, 'dm', ['reports_to_id' => $this->hod->id]);
        $this->tl = $this->makeMember($this->org, 'team-lead', ['reports_to_id' => $this->dm->id]);
        $this->dev = $this->makeMember($this->org, 'developer', ['reports_to_id' => $this->tl->id]);
        $this->hierarchy->rebuildOrganization($this->org->id);
    }

    private function fails(callable $fn, string $field = 'reports_to_id'): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());

            return;
        }

        $this->fail('Expected a validation error on '.$field);
    }

    public function test_closure_table_holds_every_ancestor_with_its_depth(): void
    {
        $this->assertSame(
            [$this->dev->id => 0, $this->tl->id => 1, $this->dm->id => 2, $this->hod->id => 3],
            $this->closureOf($this->dev)
        );
        $this->assertSame([$this->hod->id => 0], $this->closureOf($this->hod));
    }

    public function test_descendants_and_ancestors_relations_use_the_closure(): void
    {
        $this->assertEqualsCanonicalizing([$this->dm->id, $this->tl->id, $this->dev->id], $this->hod->descendants()->pluck('memberships.id')->all());
        $this->assertEqualsCanonicalizing([$this->tl->id, $this->dm->id, $this->hod->id], $this->dev->ancestors()->pluck('memberships.id')->all());
        $this->assertEqualsCanonicalizing([$this->dev->id], $this->tl->descendants()->pluck('memberships.id')->all());
    }

    public function test_setting_a_manager_rebuilds_the_whole_subtree(): void
    {
        $dm2 = $this->makeMember($this->org, 'dm', ['reports_to_id' => $this->hod->id]);

        // Move the TL (and so the developer below) under the second DM.
        $this->hierarchy->setManager($this->tl, $dm2);

        $this->assertSame($dm2->id, $this->tl->fresh()->reports_to_id);
        $this->assertSame([$this->tl->id => 0, $dm2->id => 1, $this->hod->id => 2], $this->closureOf($this->tl));
        $this->assertSame(
            [$this->dev->id => 0, $this->tl->id => 1, $dm2->id => 2, $this->hod->id => 3],
            $this->closureOf($this->dev)
        );

        // The old manager no longer sees them.
        $this->assertSame([$this->dm->id => 0, $this->hod->id => 1], $this->closureOf($this->dm));
        $this->assertSame([], $this->dm->descendants()->pluck('memberships.id')->all());
    }

    public function test_skipping_levels_is_allowed(): void
    {
        $this->hierarchy->setManager($this->dev, $this->dm); // Developer straight under a DM

        $this->assertSame([$this->dev->id => 0, $this->dm->id => 1, $this->hod->id => 2], $this->closureOf($this->dev));
    }

    public function test_clearing_the_manager_detaches_the_subtree_from_the_tree_above(): void
    {
        $this->hierarchy->setManager($this->tl, null);

        $this->assertNull($this->tl->fresh()->reports_to_id);
        $this->assertSame([$this->tl->id => 0], $this->closureOf($this->tl));
        $this->assertSame([$this->dev->id => 0, $this->tl->id => 1], $this->closureOf($this->dev));
    }

    public function test_a_manager_must_outrank_the_person(): void
    {
        // A Developer (70) cannot manage a Team Lead (60), and equal levels do not count either.
        $this->fails(fn () => $this->hierarchy->setManager($this->tl, $this->dev));

        $tl2 = $this->makeMember($this->org, 'team-lead');
        $this->fails(fn () => $this->hierarchy->setManager($this->tl, $tl2));

        $this->assertSame($this->dm->id, $this->tl->fresh()->reports_to_id);
    }

    public function test_loops_are_blocked(): void
    {
        // Same level would already fail the rank rule, so test the cycle guard directly on a rank-valid loop attempt.
        $this->assertTrue($this->hierarchy->wouldCreateCycle($this->hod, $this->dev));
        $this->assertFalse($this->hierarchy->wouldCreateCycle($this->dev, $this->hod));

        // And through the public API: the HOD cannot report to their own developer.
        $this->fails(fn () => $this->hierarchy->setManager($this->hod, $this->dev));
        $this->assertNull($this->hod->fresh()->reports_to_id);
    }

    public function test_nobody_reports_to_themselves(): void
    {
        $this->fails(fn () => $this->hierarchy->setManager($this->tl, $this->tl));
    }

    public function test_external_people_are_never_in_the_hierarchy(): void
    {
        $client = $this->makeMember($this->org, 'external-stakeholder');

        $this->fails(fn () => $this->hierarchy->setManager($client, $this->dm));
        $this->assertSame([], $this->closureOf($client));

        // ...and they cannot be someone's manager.
        $this->fails(fn () => $this->hierarchy->setManager($this->dev, $client));
    }

    public function test_the_manager_must_be_active_and_in_the_same_company(): void
    {
        [$other] = $this->makeCompany();
        $stranger = $this->makeMember($other, 'hod');
        $this->fails(fn () => $this->hierarchy->setManager($this->dev, $stranger));

        $this->dm->forceFill(['status' => Membership::EXITED])->save();
        $this->fails(fn () => $this->hierarchy->setManager($this->dev, $this->dm->fresh()));
    }

    public function test_exited_people_are_left_out_of_the_tree(): void
    {
        $this->hierarchy->purge($this->dm);

        $this->assertSame([], $this->closureOf($this->dm));
        $this->assertArrayNotHasKey($this->dm->id, $this->closureOf($this->dev));
        $this->assertSame(0, DB::table('membership_hierarchy')->where('ancestor_id', $this->dm->id)->count());
    }

    public function test_rebuilding_a_whole_company_is_idempotent_and_isolated_per_company(): void
    {
        [$other] = $this->makeCompany();
        $otherHod = $this->makeMember($other, 'hod');
        $otherDev = $this->makeMember($other, 'developer', ['reports_to_id' => $otherHod->id]);
        $this->hierarchy->rebuildOrganization($other->id);

        $before = DB::table('membership_hierarchy')->count();
        $this->hierarchy->rebuildOrganization($this->org->id);
        $this->hierarchy->rebuildOrganization($this->org->id);

        $this->assertSame($before, DB::table('membership_hierarchy')->count());
        $this->assertSame([$otherDev->id => 0, $otherHod->id => 1], $this->closureOf($otherDev));
    }

    public function test_role_change_must_keep_the_reporting_line_ranked(): void
    {
        // Promoting the Developer above the Team Lead they report to would break the rule.
        $service = app(MemberService::class);
        $admin = Membership::withoutGlobalScopes()->where('organization_id', $this->org->id)->whereHas('role', fn ($q) => $q->withoutGlobalScopes()->where('is_locked', true))->first();
        $dmRole = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'dm')->first();

        $this->fails(fn () => $service->update($this->dev, $admin, ['role_id' => $dmRole->id]), 'role_id');
        $this->assertNotSame($dmRole->id, $this->dev->fresh()->role_id);
    }

    public function test_demoting_a_manager_below_their_report_is_refused(): void
    {
        $service = app(MemberService::class);
        $admin = Membership::withoutGlobalScopes()->where('organization_id', $this->org->id)->whereHas('role', fn ($q) => $q->withoutGlobalScopes()->where('is_locked', true))->first();
        $devRole = Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('slug', 'developer')->first();

        // The TL has a developer under them; making the TL a developer too would leave equal ranks.
        $this->fails(fn () => $service->update($this->tl, $admin, ['role_id' => $devRole->id]), 'role_id');
    }
}
