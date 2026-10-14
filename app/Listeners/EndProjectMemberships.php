<?php

namespace App\Listeners;

use App\Events\MembershipExited;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Scopes\OrganizationScope;

/**
 * When someone leaves the company their project memberships end. The history of which projects they were on is kept
 * (rows get a `left_at`), so a rehire can offer to re-add them.
 */
class EndProjectMemberships
{
    public function handle(MembershipExited $event): void
    {
        $id = $event->membership->id;

        ProjectMember::where('membership_id', $id)->whereNull('left_at')->update(['left_at' => now()]);

        // A project they still managed (the scheduled path skips the handover screen) is left without a manager.
        Project::withoutGlobalScope(OrganizationScope::class)->where('manager_membership_id', $id)
            ->update(['manager_membership_id' => null]);
    }
}
