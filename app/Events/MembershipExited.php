<?php

namespace App\Events;

use App\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a person's membership has ended (soft-deleted). Later modules listen to it to clean up their own
 * data: ending project memberships, removing task watchers, handing over timesheet approvals.
 */
class MembershipExited
{
    use Dispatchable;

    public function __construct(public Membership $membership) {}
}
