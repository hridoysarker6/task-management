<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property int|null $parent_id
 * @property string $name
 * @property string|null $code
 * @property int|null $head_membership_id
 */
#[Fillable(['organization_id', 'parent_id', 'name', 'code', 'head_membership_id'])]
class Department extends Model
{
    use BelongsToOrganization, HasFactory, SoftDeletes;

    /** @return BelongsTo<Membership, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'head_membership_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }
}
