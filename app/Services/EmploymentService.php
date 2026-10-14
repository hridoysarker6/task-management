<?php

namespace App\Services;

use App\Models\EmploymentPeriod;
use App\Models\Membership;
use Illuminate\Support\Carbon;

/** Keeps `employment_periods` (join / exit history) in step with a membership. */
class EmploymentService
{
    /** Start a new period, snapshotting role, department, manager and designation. */
    public function open(Membership $member, ?Membership $by = null, ?Carbon $joinedAt = null): EmploymentPeriod
    {
        return EmploymentPeriod::create([
            'membership_id' => $member->id,
            'joined_at' => ($joinedAt ?? $member->joined_at ?? now())->toDateString(),
            'role_id' => $member->role_id,
            'department_id' => $member->department_id,
            'reports_to_id' => $member->reports_to_id,
            'designation' => $member->designation,
            'created_by' => $by?->id,
        ]);
    }

    public function current(Membership $member): ?EmploymentPeriod
    {
        return EmploymentPeriod::where('membership_id', $member->id)->whereNull('left_at')->latest('id')->first();
    }

    /** Refresh the open period after a role, department, manager or designation change. */
    public function sync(Membership $member): void
    {
        $period = $this->current($member) ?? $this->open($member);

        $period->update([
            'role_id' => $member->role_id,
            'department_id' => $member->department_id,
            'reports_to_id' => $member->reports_to_id,
            'designation' => $member->designation,
        ]);
    }

    public function close(Membership $member, Carbon $leftAt, ?string $exitType, ?string $exitReason): void
    {
        $period = $this->current($member);

        $period?->update([
            'left_at' => $leftAt->toDateString(),
            'exit_type' => $exitType,
            'exit_reason' => $exitReason,
        ]);
    }
}
