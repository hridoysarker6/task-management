<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A named set of task filters for the list or the board. Private to its owner, or shared with everyone who can see the project.
 *
 * @property int $id
 * @property int $membership_id
 * @property int|null $project_id
 * @property string $name
 * @property string $view
 * @property array<string, mixed> $filters
 * @property bool $is_shared
 */
#[Fillable(['membership_id', 'project_id', 'name', 'view', 'filters', 'is_shared'])]
class SavedFilter extends Model
{
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'is_shared' => 'boolean',
        ];
    }

    /** @return BelongsTo<Membership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withoutGlobalScopes()->withTrashed();
    }

    /**
     * The filters a person can use in a project: their own, plus everyone else's shared ones.
     *
     * @param  Builder<SavedFilter>  $query
     * @return Builder<SavedFilter>
     */
    public function scopeUsableBy(Builder $query, Membership $member, int $projectId): Builder
    {
        return $query->where('project_id', $projectId)
            ->where(fn ($q) => $q->where('membership_id', $member->id)->orWhere('is_shared', true));
    }
}
