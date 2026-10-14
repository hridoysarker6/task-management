<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's place in one project. Leaving sets `left_at`; re-adding clears it, so the history stays.
 *
 * @property int $id
 * @property int $project_id
 * @property int $membership_id
 * @property string $project_role
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 */
#[Fillable(['project_id', 'membership_id', 'project_role', 'added_by', 'joined_at', 'left_at'])]
class ProjectMember extends Model
{
    public $timestamps = false;

    public const ROLES = ['manager', 'member', 'stakeholder', 'viewer'];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class)->withoutGlobalScopes()->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }
}
