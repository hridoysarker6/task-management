<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One stretch of employment (join to exit) of a membership. Rehiring adds a new period.
 *
 * @property int $id
 * @property int $membership_id
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property string|null $designation
 * @property string|null $exit_type
 * @property string|null $exit_reason
 */
#[Fillable([
    'membership_id', 'joined_at', 'left_at', 'role_id', 'department_id', 'reports_to_id', 'designation',
    'exit_type', 'exit_reason', 'created_by',
])]
class EmploymentPeriod extends Model
{
    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'left_at' => 'date',
        ];
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Membership, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'reports_to_id')->withoutGlobalScopes()->withTrashed();
    }
}
