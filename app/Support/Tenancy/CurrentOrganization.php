<?php

namespace App\Support\Tenancy;

use App\Models\Membership;
use App\Models\Organization;

/**
 * Request-scoped holder for the company the signed-in user is working in.
 * Registered as a scoped singleton; set by the SetCurrentOrganization middleware.
 */
class CurrentOrganization
{
    public ?Organization $organization = null;

    public ?Membership $membership = null;

    public function set(Organization $organization, Membership $membership): void
    {
        $this->organization = $organization;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }
}
