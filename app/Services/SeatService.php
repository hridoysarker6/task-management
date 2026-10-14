<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use Illuminate\Validation\ValidationException;

/**
 * The company's user limit. Seats in use = active members + invitations that can still be accepted.
 * Former employees do not count.
 */
class SeatService
{
    public function used(Organization $organization): int
    {
        $members = Membership::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->where('status', Membership::ACTIVE)
            ->count();

        $invited = Invitation::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $organization->id)
            ->usable()
            ->count();

        return $members + $invited;
    }

    /** Seats left, or null when the company has no limit. */
    public function remaining(Organization $organization): ?int
    {
        return $organization->user_limit === null ? null : max(0, $organization->user_limit - $this->used($organization));
    }

    /** @throws ValidationException */
    public function assertAvailable(Organization $organization, int $needed = 1, string $field = 'email'): void
    {
        $remaining = $this->remaining($organization);

        if ($remaining !== null && $remaining < $needed) {
            throw ValidationException::withMessages([
                $field => "The company's user limit ({$organization->user_limit}) has been reached. Ask the platform administrator to raise it.",
            ]);
        }
    }
}
