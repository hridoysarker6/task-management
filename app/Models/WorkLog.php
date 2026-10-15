<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Time someone spent on a task on one day.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $task_id
 * @property int $membership_id
 * @property int|null $timesheet_id
 * @property Carbon $work_date
 * @property int $minutes
 * @property string|null $note
 */
#[Fillable(['organization_id', 'task_id', 'membership_id', 'timesheet_id', 'work_date', 'minutes', 'note'])]
class WorkLog extends Model
{
    use BelongsToOrganization, SoftDeletes;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /** @return BelongsTo<Membership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withoutGlobalScopes()->withTrashed();
    }

    /** @return BelongsTo<Timesheet, $this> */
    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(Timesheet::class);
    }
}
