<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of a task's history: who changed what, and from what to what.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $membership_id
 * @property string $event
 * @property string|null $field
 * @property string|null $old_value
 * @property string|null $new_value
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
#[Fillable(['organization_id', 'task_id', 'membership_id', 'event', 'field', 'old_value', 'new_value', 'meta', 'created_at'])]
class TaskActivity extends Model
{
    use BelongsToOrganization;

    public $timestamps = false;

    /** Fields that external stakeholders must never see (estimates, effort). */
    public const INTERNAL_FIELDS = ['original_estimate_min', 'remaining_estimate_min', 'time_spent_min', 'story_points'];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Membership, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withoutGlobalScopes()->withTrashed();
    }
}
