<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** An optional label grouping people, e.g. "Payments Team". Separate from the reporting hierarchy. */
#[Fillable(['organization_id', 'department_id', 'name', 'lead_membership_id'])]
class Team extends Model
{
    use BelongsToOrganization, SoftDeletes;

    /** @return BelongsTo<Membership, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'lead_membership_id');
    }

    /** @return BelongsToMany<Membership, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'team_members');
    }
}
