<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A time-boxed batch of work in a project. Planned -> active -> completed; only one is active per project.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $project_id
 * @property string $name
 * @property string|null $goal
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $status
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $committed_points
 * @property string|null $completed_points
 * @property int|null $created_by
 */
#[Fillable(['organization_id', 'project_id', 'name', 'goal', 'start_date', 'end_date', 'status', 'started_at', 'completed_at', 'committed_points', 'completed_points', 'created_by'])]
class Sprint extends Model
{
    use BelongsToOrganization, SoftDeletes;

    public const PLANNED = 'planned';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isPlanned(): bool
    {
        return $this->status === self::PLANNED;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @param  Builder<Sprint>  $query
     * @return Builder<Sprint>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PLANNED, self::ACTIVE]);
    }
}
