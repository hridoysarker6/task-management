<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One person's week of work. Draft or returned: still editable. Submitted or approved: locked.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $membership_id
 * @property Carbon $week_start
 * @property int $total_min
 * @property string $status
 * @property Carbon|null $submitted_at
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 */
#[Fillable(['organization_id', 'membership_id', 'week_start', 'total_min', 'status', 'submitted_at', 'reviewed_by', 'reviewed_at', 'review_note'])]
class Timesheet extends Model
{
    use BelongsToOrganization;

    public const DRAFT = 'draft';

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /** The Monday of the week a date falls in. */
    public static function weekStartOf(Carbon|string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay()->startOfWeek(Carbon::MONDAY);
    }

    /** May the owner still add, change or remove entries? */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::DRAFT, self::RETURNED], true);
    }

    /** @return BelongsTo<Membership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Membership, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'reviewed_by')->withoutGlobalScopes()->withTrashed();
    }

    /** @return HasMany<WorkLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(WorkLog::class)->orderBy('work_date')->orderBy('id');
    }
}
