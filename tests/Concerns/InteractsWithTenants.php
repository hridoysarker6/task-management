<?php

namespace Tests\Concerns;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\CompanyProvisioningService;
use App\Services\EmploymentService;
use App\Services\HierarchyService;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\RoleTemplateSeeder;
use Illuminate\Support\Facades\DB;

trait InteractsWithTenants
{
    protected function seedRoleTemplate(): void
    {
        $this->seed(RoleTemplateSeeder::class);
    }

    /** An approved company with its Company Admin. Requires seedRoleTemplate() first. */
    protected function makeCompany(array $attributes = []): array
    {
        $organization = Organization::factory()->active()->create($attributes);
        $admin = app(CompanyProvisioningService::class)->provision($organization);

        return [$organization, $admin];
    }

    /**
     * A member of the company holding the role with the given slug (e.g. 'developer').
     * $attributes may set reports_to_id, department_id, employee_code, designation, ...
     */
    protected function makeMember(Organization $organization, string $roleSlug, array $attributes = []): Membership
    {
        $role = Role::withoutGlobalScopes()->where('organization_id', $organization->id)->where('slug', $roleSlug)->firstOrFail();

        $member = Membership::withoutGlobalScopes()->create($attributes + [
            'organization_id' => $organization->id,
            'user_id' => User::factory()->create()->id,
            'role_id' => $role->id,
            'user_type' => $role->is_external ? 'external' : 'internal',
            'status' => Membership::ACTIVE,
            'joined_at' => now()->subYear()->toDateString(),
        ]);

        app(EmploymentService::class)->open($member);
        app(HierarchyService::class)->rebuildSubtree($member);

        return $member;
    }

    /** Act as the member inside their company, the way the middleware sets it up. */
    protected function actingAsMember(Membership $membership): static
    {
        return $this->actingAs($membership->user)
            ->withSession(['current_organization_id' => $membership->organization_id]);
    }

    /** Set the company context directly, for service / gate tests that make no HTTP request. */
    protected function enterCompany(Membership $membership): void
    {
        $membership->load('organization');
        app(CurrentOrganization::class)->set($membership->organization, $membership);
    }

    /** All (ancestor => depth) pairs below/above, for asserting the closure table. */
    protected function closureOf(Membership $descendant): array
    {
        return DB::table('membership_hierarchy')
            ->where('descendant_id', $descendant->id)->orderBy('depth')->pluck('depth', 'ancestor_id')->all();
    }
}
