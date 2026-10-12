<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

/**
 * Sets a company up the moment it is approved (WORKFLOW.md section 1.3):
 * copies the default role template and makes the contact person its Company Admin.
 *
 * Default task statuses/types are created per project (phase 4), so there is nothing to copy for them yet.
 */
class CompanyProvisioningService
{
    public function __construct(private RoleTemplateService $templates) {}

    public function provision(Organization $organization): Membership
    {
        return DB::transaction(function () use ($organization) {
            $roles = $this->templates->copyToOrganization($organization);

            /** @var Role $adminRole */
            $adminRole = $roles->get(Role::COMPANY_ADMIN_SLUG)
                ?? throw new \RuntimeException('The default role template has no Company Admin role. Run the RolePermissionSeeder.');

            $applicant = $organization->applicant;

            $membership = Membership::withoutGlobalScopes()->withTrashed()
                ->where('organization_id', $organization->id)
                ->where('user_id', $applicant->id)
                ->first();

            if ($membership) {
                $membership->restore();
                $membership->update(['role_id' => $adminRole->id, 'status' => Membership::ACTIVE, 'user_type' => 'internal']);
            } else {
                $membership = Membership::withoutGlobalScopes()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $applicant->id,
                    'role_id' => $adminRole->id,
                    'user_type' => 'internal',
                    'designation' => $organization->settings['contact_designation'] ?? null,
                    'status' => Membership::ACTIVE,
                    'joined_at' => now()->toDateString(),
                ]);
            }

            $applicant->forceFill(['last_organization_id' => $organization->id])->save();

            return $membership;
        });
    }
}
